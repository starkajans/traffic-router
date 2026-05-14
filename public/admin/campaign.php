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

<div class="card" style="max-width:680px">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

  <div class="field">
    <label>Kampanya adı</label>
    <input type="text" name="name" id="f-name" required value="<?= h($_POST['name'] ?? $campaign['name'] ?? '') ?>" placeholder="Örn: Türk Mobil Kampanyası">
  </div>

  <h2 style="margin:24px 0 10px;font-size:14px;color:#374151;text-transform:uppercase;letter-spacing:.04em">Bu kampanyayı tetikleyecek URL(ler)</h2>

  <div class="field">
    <label>🎯 Ziyaretçi URL'i <span class="muted">(opsiyonel — başka bir URL'de de açılsın)</span></label>
    <input type="text" name="incoming_path" value="<?= h($_POST['incoming_path'] ?? $campaign['incoming_path'] ?? '') ?>" placeholder="/ veya /promo veya /iletisim">
    <div class="help">
      <strong>Örnek:</strong><br>
      • <code>/</code> &nbsp;→&nbsp; ana sayfada bu kampanya çalışır <code>(yourdomain.com/)</code><br>
      • <code>/promo</code> &nbsp;→&nbsp; <code>yourdomain.com/promo</code> ziyaret edenlerde<br>
      • <code>/iletisim</code> &nbsp;→&nbsp; <code>yourdomain.com/iletisim</code><br>
      Boş bırakırsan sadece aşağıdaki <code>/go/{slug}</code> URL'i çalışır.<br>
      <span style="color:#b91c1c">⚠ Kullanılamaz:</span> <code>/admin</code>, <code>/go/...</code>, <code>/p/...</code>
    </div>
  </div>

  <div class="field">
    <label>🔗 Kampanya kısa kodu (her zaman çalışan iç URL)</label>
    <div style="display:flex;align-items:center;gap:6px">
      <code style="background:#f3f4f6;padding:7px 10px;border-radius:6px;border:1px solid #d1d5db">/go/</code>
      <input type="text" name="slug" id="f-slug" required value="<?= h($_POST['slug'] ?? $campaign['slug'] ?? '') ?>" placeholder="turkmobil" style="flex:1" pattern="[A-Za-z0-9_-]{1,64}">
    </div>
    <div class="help">
      İç tanımlayıcı — kampanya panelden / API'den erişmek için.<br>
      İsimden otomatik dolar, istersen değiştir. Sadece harf, rakam, tire, alt-tire.
    </div>
  </div>

  <hr style="margin:24px 0;border:0;border-top:1px solid #e5e7eb">

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

<script>
// Auto-slugify name → slug (only if slug is empty or user hasn't manually edited it)
(function() {
    const name = document.getElementById('f-name');
    const slug = document.getElementById('f-slug');
    if (!name || !slug) return;
    let userTouched = slug.value.trim() !== '';
    slug.addEventListener('input', () => { userTouched = true; });
    name.addEventListener('input', () => {
        if (userTouched) return;
        const s = name.value
            .toLowerCase()
            .replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ı/g,'i')
            .replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u')
            .replace(/[^a-z0-9]+/g,'-')
            .replace(/^-+|-+$/g,'')
            .substring(0, 64);
        slug.value = s;
    });
})();
</script>
<?php layout_foot(); ?>
