<?php
declare(strict_types=1);

namespace Trafic;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $cfg = Bootstrap::config();
            session_name($cfg['session_name'] ?? 'trafic_admin');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => Bootstrap::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(string $username, string $password): bool
    {
        self::startSession();
        $row = Bootstrap::db()->one(
            'SELECT id, username, password_hash FROM admin_users WHERE username = ?',
            [$username]
        );
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $row['id'];
        $_SESSION['admin_username'] = $row['username'];
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::startSession();
        return !empty($_SESSION['admin_user_id']);
    }

    public static function require(): void
    {
        if (!self::check()) {
            $base = rtrim(Bootstrap::config()['base_path'] ?? '', '/');
            header('Location: ' . $base . '/admin/login.php');
            exit;
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        self::startSession();
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch.');
        }
    }
}
