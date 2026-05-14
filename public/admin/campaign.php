<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Bootstrap;

Auth::require();
$db = Bootstrap::db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$campaign = null;
if ($id) {
    $campaign = $db->one('SELECT * FROM campaigns WHERE id = ?', [$id]);
    if (!$campaign) {
        flash('Campaign not found.', 'err');
        header('Location: ' . admin_url('/'));
        exit;
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $incoming = trim((string) ($_POST['incoming_path'] ?? ''));
    $default = trim((string) ($_POST['default_redirect_url'] ?? ''));
    $active = !empty($_POST['active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';
    if (!preg_match('~^[A-Za-z0-9_-]{1,64}$~', $slug)) {
        $errors[] = 'Slug must be 1-64 chars (letters, digits, _ -).';
    }
    if ($incoming !== '') {
        if ($incoming[0] !== '/') $incoming = '/' . $incoming;
        if (!preg_match('~^/[A-Za-z0-9/_-]{0,254}$~', $incoming)) {
            $errors[] = 'Incoming path must start with "/" and contain only letters, digits, dash, underscore, slash.';
        }
        if (in_array($incoming, ['/admin', '/p', '/go', '/index.php', '/p.php'], true) || strpos($incoming, '/admin/') === 0 || strpos($incoming, '/p/') === 0 || strpos($incoming, '/go/') === 0) {
            $errors[] = 'Incoming path "/admin", "/go/...", "/p/..." reserved — pick another.';
        }
    }
    if ($default !== '' && !filter_var($default, FILTER_VALIDATE_URL)) {
        $errors[] = 'Default redirect URL is not valid.';
    }

    // Uniqueness
    if (!$errors) {
        $clash = $db->one(
            'SELECT id FROM campaigns WHERE slug = ? AND id <> ?',
            [$slug, $campaign['id'] ?? 0]
        );
        if ($clash) $errors[] = 'Slug is already in use.';

        if ($incoming !== '') {
            $clashPath = $db->one(
                'SELECT id FROM campaigns WHERE incoming_path = ? AND id <> ?',
                [$incoming, $campaign['id'] ?? 0]
            );
            if ($clashPath) $errors[] = 'Incoming path is already used by another campaign.';
        }
    }

    if (!$errors) {
        $data = [
            'slug'          => $slug,
            'incoming_path' => $incoming !== '' ? $incoming : null,
            'name'          => $name,
            'default_redirect_url' => $default !== '' ? $default : null,
            'active' => $active,
        ];
        if ($campaign) {
            $db->update('campaigns', $data, ['id' => $campaign['id']]);
            flash('Campaign saved.');
            header('Location: ' . admin_url('/tree.php?campaign=' . $campaign['id']));
        } else {
            $newId = $db->insert('campaigns', $data);
            flash('Campaign created. Add nodes to build your decision tree.');
            header('Location: ' . admin_url('/tree.php?campaign=' . $newId));
        }
        exit;
    }
}

$csrf = Auth::csrfToken();
layout_head($campaign ? 'Edit campaign' : 'New campaign');
?>
<h1><?= $campaign ? 'Edit campaign' : 'New campaign' ?></h1>
<?php foreach ($errors as $e): ?>
  <div class="flash flash-err"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:640px">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
  <div class="field">
    <label>Name</label>
    <input type="text" name="name" required value="<?= h($_POST['name'] ?? $campaign['name'] ?? '') ?>">
  </div>
  <div class="field">
    <label>Slug</label>
    <input type="text" name="slug" required value="<?= h($_POST['slug'] ?? $campaign['slug'] ?? '') ?>">
    <div class="help">Visitors reach this campaign via <code>/go/{slug}</code>. Letters, digits, dash, underscore only.</div>
  </div>
  <div class="field">
    <label>Özel gelen URL yolu (incoming path) <span class="muted">(opsiyonel)</span></label>
    <input type="text" name="incoming_path" value="<?= h($_POST['incoming_path'] ?? $campaign['incoming_path'] ?? '') ?>" placeholder="/ veya /promo veya /iletisim">
    <div class="help">
      Bu kampanyayı <code>/go/{slug}</code>'a ek olarak başka bir URL'den de tetikle.<br>
      <code>/</code> = ana sayfa (yourdomain.com/) &nbsp;·&nbsp; <code>/promo</code>, <code>/iletisim</code>, <code>/landing</code> vb.<br>
      Boş bırakırsan sadece <code>/go/{slug}</code> çalışır.<br>
      <span style="color:#b91c1c">Kullanılamaz:</span> <code>/admin/*</code>, <code>/go/*</code>, <code>/p/*</code>
    </div>
  </div>
  <div class="field">
    <label>Default redirect URL <span class="muted">(optional)</span></label>
    <input type="url" name="default_redirect_url" value="<?= h($_POST['default_redirect_url'] ?? $campaign['default_redirect_url'] ?? '') ?>" placeholder="https://example.com/fallback">
    <div class="help">Used when no rule in the decision tree matches.</div>
  </div>
  <div class="field">
    <label>
      <input type="checkbox" name="active" <?= (!isset($campaign) || $campaign['active']) ? 'checked' : '' ?>>
      Active
    </label>
  </div>
  <button class="btn" type="submit">Save</button>
  <a class="btn btn-secondary" href="<?= h(admin_url('/')) ?>">Cancel</a>
</form>
</div>
<?php layout_foot(); ?>
