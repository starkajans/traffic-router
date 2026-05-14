<?php
declare(strict_types=1);

namespace Trafic;

use GeoIp2\Database\Reader;

final class GeoIP
{
    private ?Reader $reader = null;

    public function __construct(?string $dbPath)
    {
        if ($dbPath && is_file($dbPath) && class_exists(Reader::class)) {
            try {
                $this->reader = new Reader($dbPath);
            } catch (\Throwable $e) {
                $this->reader = null;
            }
        }
    }

    /** @return array{code: ?string, name: ?string} */
    public function lookup(string $ip): array
    {
        if (!$this->reader || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['code' => null, 'name' => null];
        }
        try {
            $rec = $this->reader->country($ip);
            return [
                'code' => $rec->country->isoCode,
                'name' => $rec->country->name,
            ];
        } catch (\Throwable $e) {
            return ['code' => null, 'name' => null];
        }
    }

    public static function clientIp(array $config): string
    {
        $trust = !empty($config['trust_proxy_headers']);
        if ($trust) {
            $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
            foreach ($headers as $h) {
                if (!empty($_SERVER[$h])) {
                    $first = trim(explode(',', $_SERVER[$h])[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP)) {
                        return $first;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get visitor country from Cloudflare's CF-IPCountry header.
     * Cloudflare's geolocation is well-maintained and avoids a MaxMind DB read.
     * Returns ['code' => 'XX', 'name' => 'Name'] or ['code' => null, 'name' => null].
     *
     * Only trusted when trust_proxy_headers is enabled in config.
     */
    public static function cloudflareCountry(array $config): array
    {
        if (empty($config['trust_proxy_headers'])) {
            return ['code' => null, 'name' => null];
        }
        $code = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        // CF can return "XX" (unknown), "T1" (Tor), or "" — treat all as missing
        if (!preg_match('~^[A-Z]{2}$~', $code) || $code === 'XX') {
            return ['code' => null, 'name' => null];
        }
        return ['code' => $code, 'name' => self::isoCountryName($code)];
    }

    /**
     * Resolve a 2-letter ISO 3166-1 country code to its English name.
     * Uses ext-intl when available; otherwise returns null.
     */
    public static function isoCountryName(string $code): ?string
    {
        $code = strtoupper(trim($code));
        if (strlen($code) !== 2) return null;
        if (class_exists('Locale')) {
            $name = @\Locale::getDisplayRegion('-' . $code, 'en');
            if ($name && $name !== $code) return $name;
        }
        return null;
    }
}
