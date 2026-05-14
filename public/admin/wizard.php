<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\AICampaignBuilder;
use Trafic\Auth;
use Trafic\Bootstrap;
use Trafic\Gemini;
use Trafic\Settings;

Auth::require();

$apiKey = Settings::get('gemini_api_key', '') ?: '';
$model  = Settings::get('gemini_model', 'gemini-2.5-flash') ?: 'gemini-2.5-flash';

// ============================================================================
// JSON API: ?action=chat | ?action=create | ?action=reset
// ============================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    Auth::startSession();

    if ($action === 'reset') {
        unset($_SESSION['wizard_history']);
        echo json_encode(['ok' => true]);
        exit;
    }

    if (!$apiKey) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Önce Settings sayfasında Gemini API key gir.']);
        exit;
    }

    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
    $csrfSent = $body['_csrf'] ?? '';
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $csrfSent)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
        exit;
    }

    if ($action === 'chat') {
        $userMsg = trim((string) ($body['message'] ?? ''));
        if ($userMsg === '') {
            echo json_encode(['ok' => false, 'error' => 'Mesaj boş']);
            exit;
        }

        $_SESSION['wizard_history'] = $_SESSION['wizard_history'] ?? [];
        $_SESSION['wizard_history'][] = ['role' => 'user', 'content' => $userMsg];

        try {
            $g = new Gemini($apiKey, $model);
            $reply = $g->chat($_SESSION['wizard_history'], AICampaignBuilder::systemPrompt(), 0.6);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }

        $_SESSION['wizard_history'][] = ['role' => 'assistant', 'content' => $reply];

        $campaign = AICampaignBuilder::extractCampaignJson($reply);
        $cleanText = AICampaignBuilder::stripCampaignBlock($reply);

        echo json_encode([
            'ok'       => true,
            'reply'    => $cleanText,
            'campaign' => $campaign,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create') {
        $data = $body['campaign'] ?? null;
        if (!is_array($data)) {
            echo json_encode(['ok' => false, 'error' => 'Kampanya verisi yok']);
            exit;
        }
        try {
            $id = AICampaignBuilder::buildFromArray(Bootstrap::db(), $data);
            unset($_SESSION['wizard_history']);
            echo json_encode([
                'ok'         => true,
                'campaign_id'=> $id,
                'redirect'   => admin_url('/tree.php?campaign=' . $id),
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bilinmeyen action']);
    exit;
}

// ============================================================================
// HTML page
// ============================================================================
$csrf = Auth::csrfToken();
layout_head('AI Wizard');
?>

<style>
.chat-wrap { max-width: 820px; margin: 0 auto; }
.chat-messages {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    height: 480px; overflow-y: auto; padding: 16px;
}
.chat-msg { margin-bottom: 14px; display: flex; gap: 10px; }
.chat-msg.user { flex-direction: row-reverse; }
.chat-msg .bubble {
    max-width: 75%; padding: 10px 14px; border-radius: 14px;
    line-height: 1.5; white-space: pre-wrap; word-wrap: break-word;
}
.chat-msg.user .bubble { background: #2563eb; color: #fff; border-bottom-right-radius: 4px; }
.chat-msg.assistant .bubble { background: #f3f4f6; color: #1f2937; border-bottom-left-radius: 4px; }
.chat-msg .avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 600;
}
.chat-msg.user .avatar { background: #2563eb; color: #fff; }
.chat-msg.assistant .avatar { background: #10b981; color: #fff; }

.chat-input { display: flex; gap: 8px; margin-top: 12px; }
.chat-input textarea {
    flex: 1; min-height: 56px; max-height: 200px; padding: 10px 12px;
    border: 1px solid #d1d5db; border-radius: 8px; font: inherit;
    resize: vertical;
}
.chat-input button { align-self: stretch; padding: 0 22px; }

.chat-typing { padding: 10px 14px; color: #9ca3af; font-style: italic; }
.chat-typing span { animation: blink 1.4s infinite; }
.chat-typing span:nth-child(2) { animation-delay: 0.2s; }
.chat-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes blink { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }

.campaign-preview {
    background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px;
    padding: 14px; margin-top: 12px;
}
.campaign-preview h3 { margin: 0 0 8px; color: #065f46; font-size: 14px; }
.campaign-preview pre {
    background: #fff; padding: 10px; border-radius: 6px; font-size: 12px;
    max-height: 240px; overflow: auto; margin: 8px 0;
}

.suggestions {
    display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0;
}
.suggestion-chip {
    background: #fff; border: 1px solid #d1d5db; border-radius: 14px;
    padding: 5px 12px; font-size: 12px; cursor: pointer;
    transition: all 0.15s;
}
.suggestion-chip:hover { background: #2563eb; color: #fff; border-color: #2563eb; }
</style>

<div class="chat-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">🤖 AI Wizard</h1>
    <button class="btn btn-secondary btn-sm" id="reset-btn">Sıfırla</button>
  </div>

  <?php if (!$apiKey): ?>
    <div class="flash flash-err">
      Gemini API key tanımlı değil. <a href="<?= h(admin_url('/settings.php')) ?>">Settings</a>'e git, key gir, sonra geri dön.
    </div>
  <?php else: ?>

  <div class="chat-messages" id="chat">
    <div class="chat-msg assistant">
      <div class="avatar">AI</div>
      <div class="bubble">Merhaba 👋 Sana yeni bir kampanya oluşturmada yardım edebilirim. <br><br>Ne yapmak istiyorsun? Örneğin:<br>
        • "Türk kullanıcılar bir landing'e, yabancılar başka bir landing'e gitsin"<br>
        • "Tüm AI botlarını engelle, gerçek kullanıcılar gerçek siteme gelsin"<br>
        • "Facebook reklamından gelenlere özel sayfa, diğerlerine normal"<br>
        • "Mobil kullanıcılar APP store'a, desktop normal siteye"</div>
    </div>
  </div>

  <div class="suggestions">
    <span class="suggestion-chip">AI botlarını engelle</span>
    <span class="suggestion-chip">Türk mobil kullanıcılara özel sayfa</span>
    <span class="suggestion-chip">Facebook reklamından gelenleri ayır</span>
    <span class="suggestion-chip">Sadece insanlar gerçek siteme gelsin</span>
  </div>

  <div class="chat-input">
    <textarea id="msg" placeholder="Cevabını yaz ve Enter bas... (Shift+Enter = yeni satır)" rows="2"></textarea>
    <button class="btn" id="send-btn">Gönder</button>
  </div>

  <script>
  const CSRF = <?= json_encode($csrf) ?>;
  const WIZARD_URL = <?= json_encode(admin_url('/wizard.php')) ?>;
  const chat = document.getElementById('chat');
  const msgInput = document.getElementById('msg');
  const sendBtn = document.getElementById('send-btn');
  const resetBtn = document.getElementById('reset-btn');

  function escapeHtml(s) {
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function addMsg(role, text) {
      const div = document.createElement('div');
      div.className = 'chat-msg ' + role;
      const av = role === 'user' ? 'B' : 'AI';
      div.innerHTML = `<div class="avatar">${av}</div><div class="bubble">${escapeHtml(text)}</div>`;
      chat.appendChild(div);
      chat.scrollTop = chat.scrollHeight;
      return div;
  }

  function addTyping() {
      const div = document.createElement('div');
      div.className = 'chat-msg assistant';
      div.id = 'typing';
      div.innerHTML = `<div class="avatar">AI</div><div class="bubble chat-typing">Düşünüyor<span>.</span><span>.</span><span>.</span></div>`;
      chat.appendChild(div);
      chat.scrollTop = chat.scrollHeight;
  }
  function removeTyping() {
      const t = document.getElementById('typing');
      if (t) t.remove();
  }

  function addCampaignPreview(campaign) {
      const div = document.createElement('div');
      div.className = 'chat-msg assistant';
      div.innerHTML = `<div class="avatar">AI</div><div class="bubble" style="background:transparent;padding:0;max-width:100%">
          <div class="campaign-preview">
            <h3>📋 Önerilen kampanya: ${escapeHtml(campaign.name)}</h3>
            <p style="margin:0">
              <strong>URL:</strong> /go/${escapeHtml(campaign.slug)}<br>
              <strong>Varsayılan fallback:</strong> ${escapeHtml(campaign.default_redirect_url || '(yok)')}
            </p>
            <details><summary style="cursor:pointer;color:#065f46;font-size:12px;margin-top:8px">📐 Tree yapısını JSON olarak gör</summary>
              <pre>${escapeHtml(JSON.stringify(campaign, null, 2))}</pre>
            </details>
            <div style="margin-top:10px;display:flex;gap:8px">
              <button class="btn create-btn">✓ Oluştur</button>
              <button class="btn btn-secondary reject-btn">Hayır, değiştir</button>
            </div>
          </div>
      </div>`;
      chat.appendChild(div);
      chat.scrollTop = chat.scrollHeight;

      div.querySelector('.create-btn').onclick = async () => {
          div.querySelector('.create-btn').disabled = true;
          div.querySelector('.create-btn').textContent = 'Oluşturuluyor...';
          try {
              const r = await fetch(WIZARD_URL + '?action=create', {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({_csrf: CSRF, campaign})
              });
              const j = await r.json();
              if (j.ok) {
                  window.location = j.redirect;
              } else {
                  alert('Hata: ' + (j.error || 'bilinmeyen'));
                  div.querySelector('.create-btn').disabled = false;
                  div.querySelector('.create-btn').textContent = '✓ Oluştur';
              }
          } catch (e) {
              alert('Bağlantı hatası: ' + e.message);
          }
      };
      div.querySelector('.reject-btn').onclick = () => {
          msgInput.focus();
          msgInput.placeholder = 'Neyi değiştirelim?';
      };
  }

  async function send(text) {
      if (!text.trim()) return;
      addMsg('user', text);
      msgInput.value = '';
      sendBtn.disabled = true;
      addTyping();
      try {
          const r = await fetch(WIZARD_URL + '?action=chat', {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({_csrf: CSRF, message: text})
          });
          const j = await r.json();
          removeTyping();
          if (j.ok) {
              if (j.reply) addMsg('assistant', j.reply);
              if (j.campaign) addCampaignPreview(j.campaign);
          } else {
              addMsg('assistant', '⚠ Hata: ' + (j.error || 'bilinmeyen'));
          }
      } catch (e) {
          removeTyping();
          addMsg('assistant', '⚠ Bağlantı hatası: ' + e.message);
      } finally {
          sendBtn.disabled = false;
          msgInput.focus();
      }
  }

  sendBtn.onclick = () => send(msgInput.value);
  msgInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          send(msgInput.value);
      }
  });

  document.querySelectorAll('.suggestion-chip').forEach(chip => {
      chip.onclick = () => send(chip.textContent);
  });

  resetBtn.onclick = async () => {
      if (!confirm('Konuşma sıfırlansın mı?')) return;
      await fetch(WIZARD_URL + '?action=reset', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({_csrf: CSRF})});
      location.reload();
  };

  msgInput.focus();
  </script>
  <?php endif; ?>
</div>

<?php layout_foot(); ?>
