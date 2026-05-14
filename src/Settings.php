<?php
declare(strict_types=1);

namespace Trafic;

/**
 * Key-value config stored in DB so the admin can update it from the UI
 * without touching files. Use for things like API keys, model choice, etc.
 */
final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = Bootstrap::db()->one('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        if (!$row) return $default;
        return $row['value'] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Bootstrap::db()->run(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }

    public static function all(): array
    {
        $rows = Bootstrap::db()->all('SELECT `key`, `value` FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }
}
