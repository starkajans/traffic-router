<?php
declare(strict_types=1);

namespace Trafic;

/**
 * Server-side proxy delivery for redirect nodes.
 *
 * Flow:
 *   1. Router calls deliver() with the destination URL.
 *   2. We cURL the destination, get the response.
 *   3. For HTML: rewrite asset URLs (img/link/script/a/form/iframe/url() in CSS) to
 *      point at our /go/{slug}/_proxy?t=<signed-token> endpoint.
 *   4. Stream the (possibly rewritten) body to the visitor with the original Content-Type.
 *   5. Strip headers that would leak the destination (Location, Set-Cookie domain,
 *      Content-Security-Policy, X-Frame-Options, etc.)
 *
 * Asset requests come back to handleAsset(), which verifies the HMAC, fetches the
 * upstream URL, and (for CSS) recursively rewrites url() references inside.
 */
final class Proxy
{
    private string $secret;
    private int $timeout;
    private int $maxBytes;

    public function __construct(string $secret, int $timeout = 15, int $maxBytes = 8388608)
    {
        $this->secret = $secret;
        $this->timeout = $timeout;
        $this->maxBytes = $maxBytes;
    }

    public function sign(string $url): string
    {
        $b64 = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
        $sig = substr(hash_hmac('sha256', $url, $this->secret), 0, 16);
        return $b64 . '.' . $sig;
    }

    public function unsign(string $token): ?string
    {
        if (!is_string($token) || strpos($token, '.') === false) return null;
        [$b64, $sig] = explode('.', $token, 2);
        $url = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($url === false) return null;
        $expected = substr(hash_hmac('sha256', $url, $this->secret), 0, 16);
        if (!hash_equals($expected, $sig)) return null;
        if (!preg_match('~^https?://~i', $url)) return null;
        return $url;
    }

    /**
     * Deliver the destination as a proxied response to the current visitor.
     * Returns true if served, false on hard failure (caller should fall back).
     */
    public function deliver(string $destinationUrl, string $assetEndpoint): bool
    {
        $resp = $this->fetch($destinationUrl);
        if (!$resp) return false;

        $contentType = $resp['content_type'] ?? 'text/html; charset=utf-8';
        $body = $resp['body'];

        $isHtml = stripos($contentType, 'text/html') !== false;
        if ($isHtml) {
            $body = $this->rewriteHtml($body, $resp['final_url'] ?: $destinationUrl, $assetEndpoint);
        }

        // Status code: pass through 200/4xx; never forward 3xx (would leak Location)
        $status = $resp['status'] >= 200 && $resp['status'] < 400 ? 200 : $resp['status'];
        if ($status < 200 || $status > 599) $status = 502;

        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow', true);
        // Don't leak referer to subsequent navigations
        header('Referrer-Policy: no-referrer');
        echo $body;
        return true;
    }

    /**
     * Handle a /go/{slug}/_proxy?t=... request: fetch the signed URL upstream and stream it back.
     */
    public function handleAsset(string $token, string $assetEndpoint): bool
    {
        $url = $this->unsign($token);
        if (!$url) {
            http_response_code(400);
            echo 'Bad token.';
            return false;
        }
        $resp = $this->fetch($url);
        if (!$resp) {
            http_response_code(502);
            return false;
        }

        $contentType = $resp['content_type'] ?? 'application/octet-stream';
        $body = $resp['body'];

        // For CSS responses, rewrite url(...) references so they also flow through us
        if (stripos($contentType, 'text/css') !== false) {
            $body = $this->rewriteCss($body, $resp['final_url'] ?: $url, $assetEndpoint);
        }
        // For HTML loaded via iframe-as-asset, treat same as main response
        if (stripos($contentType, 'text/html') !== false) {
            $body = $this->rewriteHtml($body, $resp['final_url'] ?: $url, $assetEndpoint);
        }

        $status = $resp['status'] >= 200 && $resp['status'] < 400 ? 200 : $resp['status'];
        if ($status < 200 || $status > 599) $status = 502;

        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=300');
        echo $body;
        return true;
    }

    // ---- internals ----

    /**
     * @return array{status:int,content_type:string,body:string,final_url:string}|null
     */
    private function fetch(string $url): ?array
    {
        if (!preg_match('~^https?://~i', $url)) return null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
            CURLOPT_ENCODING       => '', // accept gzip
            CURLOPT_HTTPHEADER     => array_filter([
                isset($_SERVER['HTTP_ACCEPT']) ? 'Accept: ' . $_SERVER['HTTP_ACCEPT'] : 'Accept: */*',
                isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? 'Accept-Language: ' . $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null,
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Cap response size — abort transfer if it exceeds limit
            CURLOPT_BUFFERSIZE     => 65536,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
                return $dlNow > $this->maxBytes ? 1 : 0;
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return null;
        }
        $info = [
            'status'       => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'content_type' => (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream'),
            'body'         => $body,
            'final_url'    => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        ];
        curl_close($ch);
        return $info;
    }

    /**
     * Rewrite asset/link URLs in an HTML document to flow through our proxy endpoint.
     * Removes/neutralises tags and headers that would leak the destination.
     */
    private function rewriteHtml(string $html, string $baseUrl, string $assetEndpoint): string
    {
        // 1. Strip canonical / alternate / og:url tags that reveal the source
        $html = preg_replace('~<link[^>]+rel\s*=\s*["\'](?:canonical|alternate)["\'][^>]*>~i', '', $html) ?? $html;
        $html = preg_replace('~<meta[^>]+property\s*=\s*["\']og:url["\'][^>]*>~i', '', $html) ?? $html;
        $html = preg_replace('~<meta[^>]+name\s*=\s*["\']twitter:url["\'][^>]*>~i', '', $html) ?? $html;
        // 2. Strip <base href> tags (we'll rewrite explicitly)
        $html = preg_replace('~<base[^>]*>~i', '', $html) ?? $html;
        // 3. Strip http-equiv refresh redirects
        $html = preg_replace('~<meta[^>]+http-equiv\s*=\s*["\']refresh["\'][^>]*>~i', '', $html) ?? $html;

        $rewrite = function (string $url) use ($baseUrl, $assetEndpoint): ?string {
            $abs = $this->resolveUrl($url, $baseUrl);
            if ($abs === null) return null;
            return $assetEndpoint . '?t=' . $this->sign($abs);
        };

        // src=, href=, action=, formaction=, poster=, data-src=, content= (for og:image), cite=
        $html = preg_replace_callback(
            '~\b(src|href|action|formaction|poster|data-src|cite)\s*=\s*(["\'])([^"\']*?)\2~i',
            function ($m) use ($rewrite) {
                $new = $rewrite($m[3]);
                if ($new === null) return $m[0];
                return $m[1] . '=' . $m[2] . htmlspecialchars($new, ENT_QUOTES) . $m[2];
            },
            $html
        ) ?? $html;

        // srcset can hold multiple URLs separated by commas with optional descriptors
        $html = preg_replace_callback(
            '~\bsrcset\s*=\s*(["\'])([^"\']+)\1~i',
            function ($m) use ($rewrite) {
                $parts = preg_split('~\s*,\s*~', $m[2]) ?: [];
                $out = [];
                foreach ($parts as $part) {
                    $bits = preg_split('~\s+~', trim($part), 2) ?: [];
                    $u = $bits[0] ?? '';
                    $desc = isset($bits[1]) ? ' ' . $bits[1] : '';
                    $new = $rewrite($u);
                    if ($new !== null) {
                        $out[] = htmlspecialchars($new, ENT_QUOTES) . $desc;
                    } else {
                        $out[] = htmlspecialchars($u, ENT_QUOTES) . $desc;
                    }
                }
                return 'srcset=' . $m[1] . implode(', ', $out) . $m[1];
            },
            $html
        ) ?? $html;

        // CSS url(...) references inside <style> blocks and inline style="..."
        $html = preg_replace_callback(
            '~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i',
            function ($m) use ($rewrite) {
                $new = $rewrite($m[2]);
                if ($new === null) return $m[0];
                return 'url("' . htmlspecialchars($new, ENT_QUOTES) . '")';
            },
            $html
        ) ?? $html;

        // 4. Optionally insert a tiny script to redirect form submissions and JS-driven navigations
        //    away from leaking. Best-effort only.
        $shield = "<script>(function(){try{" .
            "var O=window.location;" .
            // Block window.open to off-site URLs
            "var _o=window.open;window.open=function(u){return _o.call(window,u||'about:blank')};" .
            "}catch(e){}})();</script>";
        $html = preg_replace('~</head>~i', $shield . '</head>', $html, 1) ?? $html;

        return $html;
    }

    private function rewriteCss(string $css, string $baseUrl, string $assetEndpoint): string
    {
        // url(...) references
        $css = preg_replace_callback(
            '~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i',
            function ($m) use ($baseUrl, $assetEndpoint) {
                $abs = $this->resolveUrl($m[2], $baseUrl);
                if ($abs === null) return $m[0];
                return 'url("' . $assetEndpoint . '?t=' . $this->sign($abs) . '")';
            },
            $css
        ) ?? $css;
        // @import "..." or @import url(...)
        $css = preg_replace_callback(
            '~@import\s+(?:url\(\s*)?(["\'])([^"\']+)\1\s*\)?~i',
            function ($m) use ($baseUrl, $assetEndpoint) {
                $abs = $this->resolveUrl($m[2], $baseUrl);
                if ($abs === null) return $m[0];
                return '@import url("' . $assetEndpoint . '?t=' . $this->sign($abs) . '")';
            },
            $css
        ) ?? $css;
        return $css;
    }

    /**
     * Resolve a possibly-relative URL against a base URL. Returns absolute http(s) URL or null
     * if it shouldn't be rewritten (e.g. javascript:, data:, mailto:, fragment-only).
     */
    public function resolveUrl(string $url, string $base): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        // Fragment-only, data, mailto, tel, javascript -> don't rewrite
        if (preg_match('~^(#|data:|mailto:|tel:|javascript:|blob:|about:)~i', $url)) return null;
        // Protocol-relative
        if (strncmp($url, '//', 2) === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }
        // Already absolute http/https
        if (preg_match('~^https?://~i', $url)) return $url;
        // Other schemes we don't want
        if (preg_match('~^[a-z][a-z0-9+\-.]*:~i', $url)) return null;

        $p = parse_url($base);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return null;
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        if ($url[0] === '/') {
            return $origin . $url;
        }
        // Relative — resolve against directory of base path
        $path = $p['path'] ?? '/';
        $dir = substr($path, -1) === '/' ? $path : (dirname($path));
        if ($dir === '' || $dir === '\\' || $dir === '.') $dir = '/';
        $combined = rtrim($dir, '/') . '/' . $url;
        // Collapse ./ and ../ segments
        $segs = [];
        foreach (explode('/', $combined) as $s) {
            if ($s === '' || $s === '.') continue;
            if ($s === '..') { array_pop($segs); continue; }
            $segs[] = $s;
        }
        return $origin . '/' . implode('/', $segs);
    }
}
