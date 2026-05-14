<?php
declare(strict_types=1);

namespace Trafic;

final class Bootstrap
{
    private static ?array $config = null;
    private static ?Database $db = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $path = dirname(__DIR__) . '/config.php';
            if (!is_file($path)) {
                http_response_code(500);
                exit('Missing config.php — copy config.example.php to config.php and edit it.');
            }
            self::$config = require $path;
        }
        return self::$config;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            self::$db = new Database(self::config()['db']);
        }
        return self::$db;
    }

    /**
     * Reliable HTTPS detection that respects Cloudflare's CF-Visitor and X-Forwarded-Proto
     * when trust_proxy_headers is enabled. CF terminates TLS at the edge and forwards
     * to origin over HTTP unless you've configured Full / Full (strict) mode.
     */
    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') === 'on') return true;
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
        $cfg = self::config();
        if (!empty($cfg['trust_proxy_headers'])) {
            if (strcasecmp((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') === 0) return true;
            $cfVisitor = $_SERVER['HTTP_CF_VISITOR'] ?? '';
            if ($cfVisitor && strpos($cfVisitor, '"https"') !== false) return true;
        }
        return false;
    }

    public static function autoload(): void
    {
        $vendor = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
        }
        spl_autoload_register(static function (string $class): void {
            if (strpos($class, 'Trafic\\') !== 0) {
                return;
            }
            $rel = str_replace('\\', '/', substr($class, 7)) . '.php';
            $file = __DIR__ . '/' . $rel;
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
