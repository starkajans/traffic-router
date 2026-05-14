<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';
\Trafic\Bootstrap::autoload();

use Trafic\Bootstrap;

$slug = $_GET['slug'] ?? '';
if (!preg_match('~^[A-Za-z0-9_-]{1,64}$~', $slug)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$page = Bootstrap::db()->one(
    'SELECT title, html, active FROM pages WHERE slug = ? LIMIT 1',
    [$slug]
);

if (!$page || !$page['active']) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
// Allow Cloudflare to cache for 5 minutes — short enough that edits propagate quickly
header('Cache-Control: public, max-age=300');
echo $page['html'];
