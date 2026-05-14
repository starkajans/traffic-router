<?php
// Copy to config.php and edit. config.php is gitignored.

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'trafic',
        'user'     => 'trafic',
        'password' => 'change-me',
        'charset'  => 'utf8mb4',
    ],

    // Path to MaxMind GeoLite2-Country.mmdb (download free at maxmind.com).
    // OPTIONAL if you're on Cloudflare — we use CF-IPCountry header first.
    // Leave null to skip MaxMind entirely.
    'geoip_db' => __DIR__ . '/data/GeoLite2-Country.mmdb',

    // Trust X-Forwarded-For / CF-Connecting-IP / CF-IPCountry / X-Forwarded-Proto headers?
    // MUST be true behind Cloudflare or any reverse proxy you control,
    // otherwise visitor IPs/countries will be wrong and HTTPS detection will fail.
    'trust_proxy_headers' => true,

    // Session cookie name for admin
    'session_name' => 'trafic_admin',

    // Base path for public router. Empty if site is at root.
    // Set to '/router' if the public/ folder is served from yourdomain.com/router/
    'base_path' => '',

    // Secret used to HMAC-sign proxied asset URLs. Prevents the proxy from being
    // turned into an open proxy. Generate with: openssl rand -hex 32
    'proxy_secret' => 'CHANGE-ME-LONG-RANDOM-STRING',

    // Proxy fetch timeout (seconds) and max response size (bytes).
    'proxy_timeout' => 15,
    'proxy_max_bytes' => 8 * 1024 * 1024, // 8 MB
];
