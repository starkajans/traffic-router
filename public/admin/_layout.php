<?php
// Shared admin layout helpers. Pages call layout_head($title) and layout_foot().
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Bootstrap.php';
\Trafic\Bootstrap::autoload();

use Trafic\Auth;
use Trafic\Bootstrap;

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string {
    $cfg = Bootstrap::config();
    return rtrim($cfg['base_path'] ?? '', '/');
}

function admin_url(string $path = ''): string {
    return base_url() . '/admin' . $path;
}

function flash(string $msg, string $type = 'ok'): void {
    Auth::startSession();
    $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flash(): array {
    Auth::startSession();
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function layout_head(string $title): void {
    $loggedIn = Auth::check();
    $user = $_SESSION['admin_username'] ?? '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — Trafic Router</title>
<link rel="stylesheet" href="<?= h(admin_url('/style.css')) ?>">
</head>
<body>
<header class="top">
  <a href="<?= h(admin_url('/')) ?>" class="brand">Trafic Router</a>
  <?php if ($loggedIn): ?>
  <nav>
    <a href="<?= h(admin_url('/')) ?>">Campaigns</a>
    <a href="<?= h(admin_url('/test.php')) ?>">Test</a>
    <a href="<?= h(admin_url('/analytics.php')) ?>">Analytics</a>
    <span class="user"><?= h($user) ?></span>
    <a href="<?= h(admin_url('/logout.php')) ?>" class="logout">Logout</a>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php
    foreach (take_flash() as $f) {
        echo '<div class="flash flash-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

function layout_foot(): void {
    ?>
</main>
</body>
</html>
<?php
}
