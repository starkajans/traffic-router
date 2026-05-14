<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';
\Trafic\Bootstrap::autoload();

use Trafic\Bootstrap;
use Trafic\GeoIP;
use Trafic\Proxy;
use Trafic\TreeEvaluator;
use Trafic\UserAgent;

$cfg = Bootstrap::config();
$db  = Bootstrap::db();

$slug = $_GET['slug'] ?? '';
$campaign = null;

if ($slug !== '') {
    // /go/{slug} flow — match by slug
    if (!preg_match('~^[A-Za-z0-9_-]{1,64}$~', $slug)) {
        http_response_code(404);
        echo 'Not found.';
        exit;
    }
    $campaign = $db->one(
        'SELECT id, slug, name, root_node_id, default_redirect_url, active FROM campaigns WHERE slug = ? LIMIT 1',
        [$slug]
    );
} else {
    // No slug — try to match by custom incoming_path (root or other paths)
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // Normalise: strip trailing slash except for root, lowercase
    $reqPath = $reqPath === '/' ? '/' : rtrim($reqPath, '/');
    $base = rtrim($cfg['base_path'] ?? '', '/');
    if ($base !== '' && strpos($reqPath, $base) === 0) {
        $reqPath = substr($reqPath, strlen($base)) ?: '/';
    }
    $campaign = $db->one(
        'SELECT id, slug, name, root_node_id, default_redirect_url, active FROM campaigns WHERE incoming_path = ? LIMIT 1',
        [$reqPath]
    );
}

if (!$campaign || !$campaign['active']) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$base = rtrim($cfg['base_path'] ?? '', '/');
$assetEndpoint = $base . '/go/' . $campaign['slug'] . '/_proxy';
$proxy = new Proxy(
    (string) ($cfg['proxy_secret'] ?? ''),
    (int) ($cfg['proxy_timeout'] ?? 15),
    (int) ($cfg['proxy_max_bytes'] ?? 8 * 1024 * 1024)
);

// --- Branch 1: proxied asset request ---
if (!empty($_GET['_proxy'])) {
    $token = (string) ($_GET['t'] ?? '');
    if ($token === '') {
        http_response_code(400);
        echo 'Missing token.';
        exit;
    }
    $proxy->handleAsset($token, $assetEndpoint);
    exit;
}

// --- Branch 2: main redirect / proxied page ---
$ua    = UserAgent::parse();
$ip    = GeoIP::clientIp($cfg);

// Prefer Cloudflare's CF-IPCountry header (no MMDB read, well-maintained geolocation).
// Fall back to MaxMind only if CF didn't provide it (e.g. running without CF proxy).
$geo = GeoIP::cloudflareCountry($cfg);
if (!$geo['code']) {
    $geo = (new GeoIP($cfg['geoip_db'] ?? null))->lookup($ip);
}

$ctx = [
    'country_code'  => $geo['code'],
    'country_name'  => $geo['name'],
    'language'      => $ua['language'],
    'device_type'   => $ua['device'],
    'os'            => $ua['os'],
    'browser'       => $ua['browser'],
    'bot_name'      => $ua['bot_name'] ?? '',
    'bot_category'  => $ua['bot_category'] ?? '',
    'ad_platform'   => $ua['ad_platform'] ?? '',
    'referrer_host' => $ua['referrer_host'],
];

$matchedNodeId = null;
$redirectUrl   = null;
$deliveryMode  = 'redirect';

if ($campaign['root_node_id']) {
    $eval = new TreeEvaluator($db);
    $result = $eval->evaluate((int) $campaign['root_node_id'], $ctx);
    $matchedNodeId = $result['matched_node_id'];
    $redirectUrl   = $result['url'];
    $deliveryMode  = $result['delivery_mode'] ?: 'redirect';
}

if (!$redirectUrl && !empty($campaign['default_redirect_url'])) {
    $redirectUrl = $campaign['default_redirect_url'];
    // Falling back to default uses plain redirect
    $deliveryMode = 'redirect';
}

// Log hit (best-effort)
try {
    $db->insert('hits', [
        'campaign_id'     => (int) $campaign['id'],
        'ip_address'      => $ip,
        'country_code'    => $geo['code'],
        'country_name'    => $geo['name'],
        'language'        => $ua['language'],
        'device_type'     => $ua['device'],
        'os'              => $ua['os'],
        'browser'         => $ua['browser'],
        'is_bot'          => $ua['is_bot'] ? 1 : 0,
        'bot_name'        => $ua['bot_name'],
        'bot_category'    => $ua['bot_category'],
        'ad_platform'     => $ua['ad_platform'],
        'referrer_host'   => $ua['referrer_host'],
        'referrer'        => $ua['referrer'],
        'user_agent'      => $ua['user_agent'],
        'matched_node_id' => $matchedNodeId,
        'redirect_url'    => $redirectUrl,
    ]);
} catch (\Throwable $e) {
    error_log('[trafic] hit log failed: ' . $e->getMessage());
}

if (!$redirectUrl) {
    http_response_code(204);
    exit;
}

if ($deliveryMode === 'proxy') {
    $ok = $proxy->deliver($redirectUrl, $assetEndpoint);
    if ($ok) exit;
    // Hard failure (couldn't fetch destination) — fall through to redirect as a safety net
    error_log('[trafic] proxy deliver failed for ' . $redirectUrl . ' — falling back to 302');
}

// Plain redirect
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $redirectUrl, true, 302);
exit;
