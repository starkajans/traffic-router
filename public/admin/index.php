<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Bootstrap;

Auth::require();
$db = Bootstrap::db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->run('UPDATE campaigns SET active = 1 - active WHERE id = ?', [$id]);
        flash('Campaign updated.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->run('DELETE FROM hits WHERE campaign_id = ?', [$id]);
        $db->delete('campaigns', ['id' => $id]);
        flash('Campaign deleted.');
    }
    header('Location: ' . admin_url('/'));
    exit;
}

$campaigns = $db->all(
    'SELECT c.id, c.slug, c.name, c.active, c.root_node_id, c.default_redirect_url, c.created_at,
            (SELECT COUNT(*) FROM hits h WHERE h.campaign_id = c.id) AS hit_count,
            (SELECT COUNT(*) FROM tree_nodes n WHERE n.campaign_id = c.id) AS node_count
     FROM campaigns c
     ORDER BY c.created_at DESC'
);

$base = base_url();
$csrf = Auth::csrfToken();
$host = (\Trafic\Bootstrap::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');

layout_head('Campaigns');
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <h1 style="margin:0">Campaigns</h1>
  <a class="btn" href="<?= h(admin_url('/campaign.php')) ?>">+ New campaign</a>
</div>

<div class="card">
<?php if (!$campaigns): ?>
  <p class="muted">No campaigns yet. Create one to get started.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Slug / URL</th>
        <th>Nodes</th>
        <th>Hits</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
      <tr>
        <td><strong><?= h($c['name']) ?></strong></td>
        <td>
          <code><?= h($c['slug']) ?></code><br>
          <span class="muted" style="font-size:12px"><?= h($host . $base . '/go/' . $c['slug']) ?></span>
        </td>
        <td><?= (int) $c['node_count'] ?></td>
        <td><?= (int) $c['hit_count'] ?></td>
        <td>
          <?php if ($c['active']): ?>
            <span class="badge badge-on">Active</span>
          <?php else: ?>
            <span class="badge badge-off">Disabled</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="actions">
            <a class="btn btn-sm" href="<?= h(admin_url('/tree.php?campaign=' . $c['id'])) ?>">Edit tree</a>
            <a class="btn btn-sm btn-secondary" href="<?= h(admin_url('/campaign.php?id=' . $c['id'])) ?>">Settings</a>
            <a class="btn btn-sm btn-secondary" href="<?= h(admin_url('/analytics.php?campaign=' . $c['id'])) ?>">Stats</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Toggle this campaign?')">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-secondary"><?= $c['active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete campaign and ALL its nodes + hit logs? This cannot be undone.')">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-danger">Delete</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php layout_foot(); ?>
