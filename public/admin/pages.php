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
        $db->run('UPDATE pages SET active = 1 - active WHERE id = ?', [$id]);
        flash('Sayfa durumu güncellendi.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->delete('pages', ['id' => $id]);
        flash('Sayfa silindi.');
    }
    header('Location: ' . admin_url('/pages.php'));
    exit;
}

$pages = $db->all('SELECT id, slug, title, active, updated_at FROM pages ORDER BY updated_at DESC');

$csrf = Auth::csrfToken();
$host = (\Trafic\Bootstrap::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base = base_url();

layout_head('Pages');
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <h1 style="margin:0">📄 Pages — Landing sayfaları</h1>
  <a class="btn" href="<?= h(admin_url('/page.php')) ?>">+ Yeni sayfa (AI ile)</a>
</div>

<div class="card">
<?php if (!$pages): ?>
  <p class="muted">Henüz sayfa yok. AI ile tek tıkla oluşturmaya başla.</p>
  <p><a class="btn" href="<?= h(admin_url('/page.php')) ?>">+ İlk sayfanı oluştur</a></p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Başlık</th>
        <th>URL</th>
        <th>Güncelleme</th>
        <th>Durum</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pages as $p): ?>
      <tr>
        <td><strong><?= h($p['title']) ?></strong></td>
        <td>
          <code><?= h('/p/' . $p['slug']) ?></code><br>
          <a href="<?= h($host . $base . '/p/' . $p['slug']) ?>" target="_blank" rel="noopener" class="muted" style="font-size:12px">
            <?= h($host . $base . '/p/' . $p['slug']) ?> ↗
          </a>
        </td>
        <td><span class="muted"><?= h($p['updated_at']) ?></span></td>
        <td>
          <?php if ($p['active']): ?>
            <span class="badge badge-on">Aktif</span>
          <?php else: ?>
            <span class="badge badge-off">Kapalı</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="actions">
            <a class="btn btn-sm" href="<?= h(admin_url('/page.php?id=' . $p['id'])) ?>">Düzenle</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-secondary"><?= $p['active'] ? 'Kapat' : 'Aç' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Sayfa silinsin mi? Geri alınamaz.')">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-danger">Sil</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<div class="card">
  <h2>💡 Kullanım</h2>
  <ul style="margin:0;padding-left:18px">
    <li>"Yeni sayfa" → slug + Türkçe açıklama → AI tüm HTML'i üretir</li>
    <li>HTML'i sonradan elle düzenleyebilirsin (sol editör, sağ canlı önizleme)</li>
    <li>Sayfan <code><?= h($host . $base) ?>/p/<strong>slug</strong></code> URL'inde yayında olur</li>
    <li>Mobile-responsive, SEO meta tag'li, JSON-LD schema'lı — bot'lar "gerçek sayfa" olarak görür</li>
    <li>Bir kampanyada redirect URL olarak <code><?= h($host . $base) ?>/p/slug</code> kullanabilirsin</li>
    <li>Cloudflare 5 dakika cache'ler — değişiklik sonrası purge etmen gerekebilir</li>
  </ul>
</div>

<?php layout_foot(); ?>
