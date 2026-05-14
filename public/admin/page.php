<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Bootstrap;
use Trafic\Gemini;
use Trafic\PageGenerator;
use Trafic\Settings;

Auth::require();
$db = Bootstrap::db();
$apiKey = Settings::get('gemini_api_key', '') ?: '';
$model  = Settings::get('gemini_model', 'gemini-2.5-flash') ?: 'gemini-2.5-flash';

$id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$page = $id ? $db->one('SELECT * FROM pages WHERE id = ?', [$id]) : null;
if ($id && !$page) {
    flash('Sayfa bulunamadı.', 'err');
    header('Location: ' . admin_url('/pages.php'));
    exit;
}

$errors = [];

// ============================================================================
// JSON API for AI generation (POST ?action=ai_generate or ?action=ai_edit)
// ============================================================================
$apiAction = $_GET['action'] ?? null;
if ($apiAction && in_array($apiAction, ['ai_generate', 'ai_edit'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($body['_csrf'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
        exit;
    }
    if (!$apiKey) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Önce Settings\'te Gemini API key gir.']);
        exit;
    }

    try {
        // Long curl + PHP timeout for HTML gen (Gemini emits 80-150 tok/s, can take ~2 min)
        set_time_limit(240);
        $g = new Gemini($apiKey, $model, 180);
        if ($apiAction === 'ai_generate') {
            $description = trim((string) ($body['description'] ?? ''));
            if ($description === '') throw new \InvalidArgumentException('Açıklama boş');
            $result = PageGenerator::generate($g, $description);
        } else { // ai_edit
            $current = (string) ($body['current_html'] ?? '');
            $instr   = trim((string) ($body['instruction'] ?? ''));
            if ($current === '' || $instr === '') throw new \InvalidArgumentException('Eksik veri');
            $result = PageGenerator::edit($g, $current, $instr);
        }
        echo json_encode(['ok' => true, 'html' => $result['html'], 'title' => $result['title']], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// Regular form POST: save
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $slug   = strtolower(trim((string) ($_POST['slug'] ?? '')));
    $title  = trim((string) ($_POST['title'] ?? ''));
    $html   = (string) ($_POST['html'] ?? '');
    $prompt = trim((string) ($_POST['prompt'] ?? ''));
    $active = !empty($_POST['active']) ? 1 : 0;

    if (!preg_match('~^[a-z0-9_-]{1,64}$~', $slug)) {
        $errors[] = 'Slug 1-64 karakter olmalı (küçük harf, rakam, tire, alt-tire).';
    }
    if ($title === '') $errors[] = 'Başlık boş olamaz.';
    if (trim($html) === '') $errors[] = 'HTML içerik boş olamaz.';

    if (!$errors) {
        $clash = $db->one('SELECT id FROM pages WHERE slug = ? AND id <> ?', [$slug, $page['id'] ?? 0]);
        if ($clash) $errors[] = 'Bu slug başka sayfada kullanılıyor.';
    }

    if (!$errors) {
        $data = [
            'slug'   => $slug,
            'title'  => $title,
            'html'   => $html,
            'prompt' => $prompt !== '' ? $prompt : null,
            'active' => $active,
        ];
        if ($page) {
            $db->update('pages', $data, ['id' => $page['id']]);
            flash('Sayfa güncellendi.');
            header('Location: ' . admin_url('/page.php?id=' . $page['id']));
        } else {
            $newId = $db->insert('pages', $data);
            flash('Sayfa oluşturuldu.');
            header('Location: ' . admin_url('/page.php?id=' . $newId));
        }
        exit;
    }
}

$csrf = Auth::csrfToken();
$host = (\Trafic\Bootstrap::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');

layout_head($page ? 'Sayfa düzenle' : 'Yeni sayfa');
?>
<style>
.editor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.editor-grid > div { min-width: 0; }
@media (max-width: 900px) { .editor-grid { grid-template-columns: 1fr; } }

#html-editor {
    width: 100%; min-height: 520px;
    font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    border: 1px solid #d1d5db; border-radius: 6px;
    padding: 10px;
}
#preview-frame {
    width: 100%; min-height: 520px;
    border: 1px solid #d1d5db; border-radius: 6px;
    background: #fff;
}
.gen-row { display: flex; gap: 8px; align-items: stretch; }
.gen-row textarea {
    flex: 1; min-height: 56px; padding: 10px;
    border: 1px solid #d1d5db; border-radius: 6px; font: inherit;
}
.gen-row button { padding: 0 18px; }
.spinner { display: none; padding: 8px; color: #6b7280; font-style: italic; }
.spinner.active { display: block; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
  <h1 style="margin:0"><?= $page ? '✏️ Düzenle: ' . h($page['title']) : '➕ Yeni sayfa' ?></h1>
  <a class="btn btn-secondary" href="<?= h(admin_url('/pages.php')) ?>">← Tüm sayfalar</a>
</div>

<?php foreach ($errors as $e): ?>
  <div class="flash flash-err"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if (!$apiKey): ?>
  <div class="flash flash-err">
    Gemini API key tanımlı değil. <a href="<?= h(admin_url('/settings.php')) ?>">Settings'e git</a>, key ekle, sonra geri dön. (AI olmadan yine elle HTML yazabilirsin.)
  </div>
<?php endif; ?>

<!-- AI generation panel -->
<?php if ($apiKey && !$page): ?>
<div class="card">
  <h2>🤖 AI ile oluştur</h2>
  <p class="muted">İstediğin sayfayı Türkçe tarif et — AI tam HTML üretecek. Birkaç saniye sürer.</p>
  <div class="gen-row">
    <textarea id="gen-input" placeholder='Örn: "Müşteri ilişkileri yazılımı satan bir SaaS şirketinin ana sayfası. Mor tonlarda, modern. Hero section, 3 özellik, fiyatlandırma, müşteri yorumları, footer."'></textarea>
    <button type="button" class="btn" id="gen-btn">✨ Üret</button>
  </div>
  <div class="spinner" id="gen-spinner">Gemini düşünüyor... ⏳ (5-15 saniye sürer)</div>
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

  <div class="card">
    <div class="row">
      <div class="col" style="flex:2 1 300px">
        <label>Başlık</label>
        <input type="text" name="title" id="page-title" value="<?= h($_POST['title'] ?? $page['title'] ?? '') ?>" required>
      </div>
      <div class="col" style="flex:1 1 200px">
        <label>Slug</label>
        <input type="text" name="slug" value="<?= h($_POST['slug'] ?? $page['slug'] ?? '') ?>" required pattern="[a-z0-9_-]{1,64}">
        <div class="help">URL: <code><?= h($host) ?>/p/<strong>slug</strong></code></div>
      </div>
      <div class="col" style="flex:0 0 100px;align-self:flex-end">
        <label style="margin:0">
          <input type="checkbox" name="active" <?= empty($page) || $page['active'] ? 'checked' : '' ?>>
          Aktif
        </label>
      </div>
    </div>

    <details style="margin-top:10px">
      <summary class="muted" style="cursor:pointer">📝 Orijinal AI promptu (oluştururken kullanılan)</summary>
      <textarea name="prompt" rows="3" placeholder="AI ile oluşturduysan promptun burada saklanır — sonradan farklı bir varyasyon istemek için kullanabilirsin"><?= h($_POST['prompt'] ?? $page['prompt'] ?? '') ?></textarea>
    </details>
  </div>

  <div class="card">
    <h2 style="margin-top:0">HTML & Önizleme</h2>
    <div class="editor-grid">
      <div>
        <label>HTML kaynağı</label>
        <textarea name="html" id="html-editor" spellcheck="false"><?= h($_POST['html'] ?? $page['html'] ?? '') ?></textarea>
      </div>
      <div>
        <label>Canlı önizleme</label>
        <iframe id="preview-frame" sandbox="allow-same-origin"></iframe>
      </div>
    </div>

    <?php if ($apiKey && $page): ?>
    <div style="margin-top:14px;padding:12px;background:#f9fafb;border-radius:6px">
      <strong>🪄 AI ile düzenle:</strong>
      <div class="gen-row" style="margin-top:8px">
        <textarea id="edit-input" placeholder='Örn: "Başlığı kırmızı yap", "Bir SSS bölümü ekle", "Telefon numarasını 0212 555 değiştir"'></textarea>
        <button type="button" class="btn btn-secondary" id="edit-btn">↻ Uygula</button>
      </div>
      <div class="spinner" id="edit-spinner">Gemini düzenliyor... ⏳</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <button class="btn" type="submit">💾 Kaydet</button>
    <?php if ($page && $page['active']): ?>
      <a class="btn btn-secondary" href="<?= h($host . '/p/' . $page['slug']) ?>" target="_blank">👁 Canlı sayfayı aç</a>
    <?php endif; ?>
  </div>
</form>

<script>
(function() {
    const CSRF = <?= json_encode($csrf) ?>;
    const URL_BASE = <?= json_encode(admin_url('/page.php' . ($page ? '?id=' . (int) $page['id'] : ''))) ?>;
    const editor = document.getElementById('html-editor');
    const preview = document.getElementById('preview-frame');
    const titleInput = document.getElementById('page-title');

    function refreshPreview() {
        preview.srcdoc = editor.value;
    }

    let updateTimer;
    editor.addEventListener('input', () => {
        clearTimeout(updateTimer);
        updateTimer = setTimeout(refreshPreview, 400);
    });
    refreshPreview();

    // ---- AI generate (new page) ----
    const genBtn = document.getElementById('gen-btn');
    if (genBtn) {
        const genInput = document.getElementById('gen-input');
        const genSpinner = document.getElementById('gen-spinner');
        genBtn.addEventListener('click', async () => {
            const desc = genInput.value.trim();
            if (!desc) { alert('Açıklama yaz'); return; }
            genBtn.disabled = true;
            genSpinner.classList.add('active');
            try {
                const sep = URL_BASE.includes('?') ? '&' : '?';
                const r = await fetch(URL_BASE + sep + 'action=ai_generate', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({_csrf: CSRF, description: desc})
                });
                const j = await r.json();
                if (j.ok) {
                    editor.value = j.html;
                    if (j.title && !titleInput.value) titleInput.value = j.title;
                    // Save prompt for later
                    const promptInput = document.querySelector('[name="prompt"]');
                    if (promptInput) promptInput.value = desc;
                    refreshPreview();
                } else {
                    alert('AI hata: ' + (j.error || 'bilinmeyen'));
                }
            } catch (e) {
                alert('Bağlantı hatası: ' + e.message);
            } finally {
                genBtn.disabled = false;
                genSpinner.classList.remove('active');
            }
        });
    }

    // ---- AI edit (existing page) ----
    const editBtn = document.getElementById('edit-btn');
    if (editBtn) {
        const editInput = document.getElementById('edit-input');
        const editSpinner = document.getElementById('edit-spinner');
        editBtn.addEventListener('click', async () => {
            const instr = editInput.value.trim();
            if (!instr) { alert('Düzenleme talimatı yaz'); return; }
            if (!editor.value.trim()) { alert('Önce HTML olmalı'); return; }
            editBtn.disabled = true;
            editSpinner.classList.add('active');
            try {
                const sep = URL_BASE.includes('?') ? '&' : '?';
                const r = await fetch(URL_BASE + sep + 'action=ai_edit', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        _csrf: CSRF,
                        current_html: editor.value,
                        instruction: instr,
                    })
                });
                const j = await r.json();
                if (j.ok) {
                    editor.value = j.html;
                    if (j.title) titleInput.value = j.title;
                    editInput.value = '';
                    refreshPreview();
                } else {
                    alert('AI hata: ' + (j.error || 'bilinmeyen'));
                }
            } catch (e) {
                alert('Bağlantı hatası: ' + e.message);
            } finally {
                editBtn.disabled = false;
                editSpinner.classList.remove('active');
            }
        });
    }
})();
</script>

<?php layout_foot(); ?>
