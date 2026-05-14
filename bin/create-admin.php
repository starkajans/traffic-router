<?php
// Usage: php bin/create-admin.php <username> <password>
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';
\Trafic\Bootstrap::autoload();

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> <password>\n");
    exit(1);
}
$u = $argv[1];
$p = $argv[2];
if (strlen($p) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$db = \Trafic\Bootstrap::db();
$hash = password_hash($p, PASSWORD_DEFAULT);

$existing = $db->one('SELECT id FROM admin_users WHERE username = ?', [$u]);
if ($existing) {
    $db->update('admin_users', ['password_hash' => $hash], ['id' => $existing['id']]);
    echo "Updated password for existing user '$u'.\n";
} else {
    $db->insert('admin_users', ['username' => $u, 'password_hash' => $hash]);
    echo "Created admin user '$u'.\n";
}
