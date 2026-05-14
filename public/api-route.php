<?php
/**
 * GET /api-route?path=...&country=TR&language=tr&device=mobile&...
 *
 * Looks up the campaign for the given path, walks its tree with the visitor
 * context, and returns a JSON decision the Cloudflare Worker (or any other
 * caller) can execute.
 *
 * Response:
 *   { "action": "passthrough" | "redirect" | "proxy" | "serve" | "404",
 *     "url": "https://...",
 *     "campaign_id": 2,
 *     "matched_node_id": 7,
 *     "label": "Tree node label",
 *     "reason": "human-readable" }
 *
 * action:
 *   passthrough — no campaign matched, caller should fetch origin normally
 *   redirect    — caller should 302 to url
 *   proxy       — caller should proxy url under its own domain (URL bar stays)
 *   serve       — caller should fetch url and serve content as-is (no rewrite,
 *                 used when url is already on our domain like /p/home)
 *   404         — caller should return 404
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';
\Trafic\Bootstrap::autoload();

use Trafic\Bootstrap;
use Trafic\TreeEvaluator;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$db = Bootstrap::db();

// --- Input ----------------------------------------------------------------
$rawPath = (string) ($_GET['path'] ?? '/');
$path    = parse_url($rawPath, PHP_URL_PATH) ?: '/';
$path    = $path === '/' ? '/' : rtrim($path, '/');

$ctx = [
    'country_code'  => strtoupper(trim((string) ($_GET['country'] ?? ''))) ?: null,
    'language'      => strtolower(trim((string) ($_GET['language'] ?? ''))) ?: null,
    'device_type'   => trim((string) ($_GET['device'] ?? '')) ?: null,
    'os'            => trim((string) ($_GET['os'] ?? '')) ?: null,
    'browser'       => trim((string) ($_GET['browser'] ?? '')) ?: null,
    'bot_name'      => trim((string) ($_GET['bot_name'] ?? '')),
    'bot_category'  => trim((string) ($_GET['bot_category'] ?? '')),
    'ad_platform'   => trim((string) ($_GET['ad_platform'] ?? '')),
    'referrer_host' => strtolower(trim((string) ($_GET['referrer_host'] ?? ''))) ?: null,
];

// --- Find campaign --------------------------------------------------------
$campaign = null;

// 1. By custom incoming_path
$campaign = $db->one(
    'SELECT id, slug, name, root_node_id, default_redirect_url, active FROM campaigns WHERE incoming_path = ? LIMIT 1',
    [$path]
);

// 2. By slug if path looks like /go/{slug}
if (!$campaign && preg_match('~^/go/([A-Za-z0-9_-]+)$~', $path, $m)) {
    $campaign = $db->one(
        'SELECT id, slug, name, root_node_id, default_redirect_url, active FROM campaigns WHERE slug = ? LIMIT 1',
        [$m[1]]
    );
}

if (!$campaign || !$campaign['active']) {
    echo json_encode(['action' => 'passthrough', 'reason' => 'no-campaign']);
    exit;
}

// --- Walk tree -------------------------------------------------------------
$destUrl = null;
$matchedNodeId = null;
$matchedLabel = null;
$deliveryMode = 'redirect';

if ($campaign['root_node_id']) {
    $eval = new TreeEvaluator($db);
    $result = $eval->evaluate((int) $campaign['root_node_id'], $ctx);
    if ($result['url']) {
        $destUrl       = $result['url'];
        $matchedNodeId = $result['matched_node_id'];
        $deliveryMode  = $result['delivery_mode'] ?: 'redirect';
        // Optional label lookup
        if ($matchedNodeId) {
            $row = $db->one('SELECT label FROM tree_nodes WHERE id = ?', [$matchedNodeId]);
            if ($row) $matchedLabel = $row['label'];
        }
    }
}

// Fall back to campaign default
if (!$destUrl && !empty($campaign['default_redirect_url'])) {
    $destUrl = $campaign['default_redirect_url'];
    $deliveryMode = 'redirect';
}

if (!$destUrl) {
    echo json_encode([
        'action' => '404',
        'campaign_id' => (int) $campaign['id'],
        'reason' => 'no-match',
    ]);
    exit;
}

// --- Decide action --------------------------------------------------------
// proxy → Worker proxies the URL (full cookies, sessions, URL bar masked)
// redirect → Worker sends 302
// serve → URL is on our own domain, fetch and return as-is (no rewriting)
$ourDomain = parse_url($destUrl, PHP_URL_HOST);
$selfDomain = $_SERVER['HTTP_HOST'] ?? '';

$action = $deliveryMode === 'proxy' ? 'proxy' : 'redirect';

// If "proxy" mode but URL is on our own domain, use "serve" — much cheaper
if ($action === 'proxy' && $ourDomain && strcasecmp($ourDomain, $selfDomain) === 0) {
    $action = 'serve';
}

echo json_encode([
    'action'         => $action,
    'url'            => $destUrl,
    'campaign_id'    => (int) $campaign['id'],
    'matched_node_id'=> $matchedNodeId,
    'label'          => $matchedLabel,
    'reason'         => 'matched',
], JSON_UNESCAPED_SLASHES);
