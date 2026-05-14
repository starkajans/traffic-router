<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Gemini;
use Trafic\Settings;

Auth::require();

$msg = null;
$msgType = 'ok';
$testResult = null;

$MASK = '••••••••••••KEEP-EXISTING';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_gemini') {
        $keyInput = trim((string) ($_POST['gemini_api_key'] ?? ''));
        $model = trim((string) ($_POST['gemini_model'] ?? 'gemini-2.5-flash'));

        // Keep existing if user didn't change the masked placeholder
        if (strpos($keyInput, '•') !== false) {
            // Untouched — keep existing key, just update model
            Settings::set('gemini_model', $model);
            flash('Model güncellendi (API key değişmedi).');
        } elseif ($keyInput === '') {
            $msg = 'API key boş.';
            $msgType = 'err';
        } elseif (!preg_match('~^AIza[A-Za-z0-9_-]{20,}$~', $keyInput)) {
            $msg = 'API key formatı yanlış (Gemini key\'leri "AIza..." ile başlar).';
            $msgType = 'err';
        } else {
            Settings::set('gemini_api_key', $keyInput);
            Settings::set('gemini_model', $model);
            flash('Gemini ayarları kaydedildi.');
            header('Location: ' . admin_url('/settings.php'));
            exit;
        }
    }

    if ($action === 'test_gemini') {
        $key = Settings::get('gemini_api_key', '');
        $model = Settings::get('gemini_model', 'gemini-2.5-flash') ?: 'gemini-2.5-flash';
        if (!$key) {
            $msg = 'Önce API key kaydet, sonra test et.';
            $msgType = 'err';
        } else {
            $g = new Gemini($key, $model);
            $testResult = $g->testConnection();
        }
    }

    if ($action === 'clear_gemini') {
        Settings::set('gemini_api_key', '');
        flash('Gemini API key silindi.');
        header('Location: ' . admin_url('/settings.php'));
        exit;
    }
}

$apiKey = Settings::get('gemini_api_key', '') ?: '';
$model  = Settings::get('gemini_model', 'gemini-2.5-flash') ?: 'gemini-2.5-flash';
$displayKey = $apiKey ? ('••••••••••••' . substr($apiKey, -6)) : '';
$csrf   = Auth::csrfToken();

layout_head('Ayarlar');
?>
<h1>Ayarlar</h1>

<?php if ($msg): ?><div class="flash flash-<?= h($msgType) ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="card" style="max-width:720px">
  <h2>Gemini AI (Wizard için)</h2>
  <p class="muted" style="margin-top:0">
    Doğal dilde konuşarak kampanya oluşturmak için Google Gemini API key. Ücretsiz tier yeterli.<br>
    Key'ini buradan al: <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>
  </p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_gemini">

    <div class="field">
      <label>API Key</label>
      <input type="text" name="gemini_api_key" value="<?= h($displayKey) ?>" placeholder="AIza..." autocomplete="off" spellcheck="false">
      <div class="help">Mevcut key gizli görünüyor. Değiştirmek için tüm field'ı sil, yenisini yapıştır.</div>
    </div>

    <div class="field">
      <label>Model</label>
      <select name="gemini_model">
        <?php foreach ([
            'gemini-2.5-flash'      => 'Gemini 2.5 Flash (hızlı, ücretsiz tier — önerilir)',
            'gemini-2.5-pro'        => 'Gemini 2.5 Pro (daha akıllı, ücretli)',
            'gemini-2.0-flash'      => 'Gemini 2.0 Flash',
            'gemini-1.5-flash'      => 'Gemini 1.5 Flash (legacy)',
        ] as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $model === $k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="actions">
      <button class="btn" type="submit">Kaydet</button>
    </div>
  </form>

  <?php if ($apiKey): ?>
    <hr style="margin:18px 0;border:0;border-top:1px solid #e5e7eb">
    <form method="post" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="test_gemini">
      <button class="btn btn-secondary" type="submit">🔌 Bağlantıyı test et</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('Gemini API key silinsin mi?')">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="clear_gemini">
      <button class="btn btn-danger" type="submit">Key'i sil</button>
    </form>
  <?php endif; ?>

  <?php if ($testResult): ?>
    <div class="flash flash-<?= $testResult['ok'] ? 'ok' : 'err' ?>" style="margin-top:14px">
      <?php if ($testResult['ok']): ?>
        ✓ Bağlantı başarılı. Gemini cevabı: <code><?= h($testResult['message']) ?></code>
      <?php else: ?>
        ✗ Bağlantı başarısız: <?= h($testResult['message']) ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($apiKey): ?>
    <p style="margin-top:18px"><a class="btn" href="<?= h(admin_url('/wizard.php')) ?>">→ AI Wizard'a git, kampanya oluştur</a></p>
  <?php endif; ?>
</div>

<?php layout_foot(); ?>
