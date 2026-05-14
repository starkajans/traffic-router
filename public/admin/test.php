<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Bootstrap;
use Trafic\GeoIP;
use Trafic\TreeEvaluator;
use Trafic\UserAgent;

Auth::require();
$db = Bootstrap::db();

$campaigns = $db->all('SELECT id, slug, name, root_node_id, default_redirect_url FROM campaigns ORDER BY name');

// ---- Detect "my computer" values from the actual current request -----------
$cfg = Bootstrap::config();
$selfUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
$selfIp = GeoIP::clientIp($cfg);
$selfCfCountry = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
$selfLang = UserAgent::primaryLanguage() ?? '';

// ---- Preset templates -------------------------------------------------------
$presets = [
    'self' => [
        'icon'  => '💻',
        'label' => 'Benim bilgisayarım',
        'desc'  => 'Şu an giriş yaptığın gerçek değerlerin',
        'data'  => [
            'ua'      => $selfUa,
            'ip'      => $selfIp,
            'country' => $selfCfCountry !== '' && $selfCfCountry !== 'XX' ? $selfCfCountry : '',
            'language'=> $selfLang,
        ],
    ],
    'tr_iphone' => [
        'icon'  => '📱',
        'label' => 'Türk iPhone',
        'desc'  => 'TR, Safari, iOS, mobile',
        'data'  => [
            'country' => 'TR', 'language' => 'tr',
            'device'  => 'mobile', 'os' => 'iOS', 'browser' => 'Safari',
            'ua'      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        ],
    ],
    'tr_android' => [
        'icon'  => '🤖',
        'label' => 'Türk Android',
        'desc'  => 'TR, Chrome, Android, mobile',
        'data'  => [
            'country' => 'TR', 'language' => 'tr',
            'device'  => 'mobile', 'os' => 'Android', 'browser' => 'Chrome',
            'ua'      => 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
        ],
    ],
    'tr_desktop' => [
        'icon'  => '🖥️',
        'label' => 'Türk Windows',
        'desc'  => 'TR, Chrome, Windows, desktop',
        'data'  => [
            'country' => 'TR', 'language' => 'tr',
            'device'  => 'desktop', 'os' => 'Windows 10/11', 'browser' => 'Chrome',
            'ua'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ],
    ],
    'us_desktop' => [
        'icon'  => '🇺🇸',
        'label' => 'ABD masaüstü',
        'desc'  => 'US, Chrome, Windows',
        'data'  => [
            'country' => 'US', 'language' => 'en',
            'device'  => 'desktop', 'os' => 'Windows 10/11', 'browser' => 'Chrome',
            'ua'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ],
    ],
    'de_mac' => [
        'icon'  => '🇩🇪',
        'label' => 'Alman Mac',
        'desc'  => 'DE, Safari, macOS',
        'data'  => [
            'country' => 'DE', 'language' => 'de',
            'device'  => 'desktop', 'os' => 'macOS', 'browser' => 'Safari',
            'ua'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        ],
    ],
    'meta_ad_click' => [
        'icon'  => '📘',
        'label' => 'Facebook reklam tıklaması',
        'desc'  => 'TR mobil + fbclid',
        'data'  => [
            'country' => 'TR', 'language' => 'tr',
            'device'  => 'mobile', 'os' => 'iOS', 'browser' => 'Safari',
            'ad_platform'   => 'meta_ads',
            'referrer_host' => 'l.facebook.com',
            'ua'      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram',
        ],
    ],
    'google_ad_click' => [
        'icon'  => '🔍',
        'label' => 'Google reklam tıklaması',
        'desc'  => 'TR desktop + gclid',
        'data'  => [
            'country' => 'TR', 'language' => 'tr',
            'device'  => 'desktop', 'os' => 'Windows 10/11', 'browser' => 'Chrome',
            'ad_platform'   => 'google_ads',
            'referrer_host' => 'google.com',
        ],
    ],
    'tiktok_ad_click' => [
        'icon'  => '🎵',
        'label' => 'TikTok reklam tıklaması',
        'desc'  => 'TR mobil + ttclid',
        'data'  => [
            'country' => 'TR', 'language' => 'tr',
            'device'  => 'mobile', 'os' => 'Android', 'browser' => 'Chrome',
            'ad_platform'   => 'tiktok_ads',
            'referrer_host' => 'tiktok.com',
        ],
    ],
    'gptbot' => [
        'icon'  => '🤖',
        'label' => 'ChatGPT (GPTBot)',
        'desc'  => 'OpenAI crawler',
        'data'  => [
            'bot' => 'GPTBot', 'bot_category' => 'ai',
            'ua'  => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
        ],
    ],
    'claudebot' => [
        'icon'  => '🧠',
        'label' => 'Claude (ClaudeBot)',
        'desc'  => 'Anthropic crawler',
        'data'  => [
            'bot' => 'ClaudeBot', 'bot_category' => 'ai',
            'ua'  => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ClaudeBot/1.0; +claudebot@anthropic.com',
        ],
    ],
    'perplexity' => [
        'icon'  => '🔎',
        'label' => 'Perplexity',
        'desc'  => 'PerplexityBot',
        'data'  => [
            'bot' => 'PerplexityBot', 'bot_category' => 'ai',
            'ua'  => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; PerplexityBot/1.0; +https://docs.perplexity.ai/docs/perplexity-bot',
        ],
    ],
    'googlebot' => [
        'icon'  => '🌐',
        'label' => 'Googlebot',
        'desc'  => 'Google arama crawler',
        'data'  => [
            'bot' => 'Googlebot', 'bot_category' => 'search',
            'ua'  => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ],
    ],
    'adsbot_google' => [
        'icon'  => '📊',
        'label' => 'Google Ads botu',
        'desc'  => 'AdsBot-Google (landing kontrol)',
        'data'  => [
            'bot' => 'AdsBot-Google', 'bot_category' => 'ads_google',
            'ua'  => 'AdsBot-Google (+http://www.google.com/adsbot.html)',
        ],
    ],
    'fb_share' => [
        'icon'  => '📱',
        'label' => 'Facebook link önizleme',
        'desc'  => 'facebookexternalhit (ads_meta)',
        'data'  => [
            'bot' => 'facebookexternalhit', 'bot_category' => 'ads_meta',
            'ua'  => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        ],
    ],
];

$selectedId = (int) ($_GET['campaign'] ?? $_POST['campaign'] ?? ($campaigns[0]['id'] ?? 0));
$selected = null;
foreach ($campaigns as $c) {
    if ((int) $c['id'] === $selectedId) { $selected = $c; break; }
}

// Inputs (defaults)
$ip       = trim((string) ($_POST['ip'] ?? ''));
$uaString = trim((string) ($_POST['ua'] ?? ''));
$country  = strtoupper(trim((string) ($_POST['country'] ?? '')));
$language = strtolower(trim((string) ($_POST['language'] ?? '')));
$device   = (string) ($_POST['device'] ?? '');
$os       = (string) ($_POST['os'] ?? '');
$browser  = (string) ($_POST['browser'] ?? '');
$botName  = trim((string) ($_POST['bot'] ?? ''));
$botCat   = strtolower(trim((string) ($_POST['bot_category'] ?? '')));
$adPlat   = strtolower(trim((string) ($_POST['ad_platform'] ?? '')));
$refHost  = strtolower(trim((string) ($_POST['referrer_host'] ?? '')));

$autoFilled = false;

// If a UA string was given, parse it to fill device/os/browser/bot
if ($uaString !== '') {
    $autoFilled = true;
    $device  = $device  ?: (function () use ($uaString) { return \Trafic\UserAgent::detectDevice($uaString); })();
    $os      = $os      ?: \Trafic\UserAgent::detectOS($uaString);
    $browser = $browser ?: \Trafic\UserAgent::detectBrowser($uaString);
    $bot     = \Trafic\UserAgent::detectBot($uaString);
    if ($botName === '' && $bot !== null) { $botName = $bot; }
}

// Auto-derive category from bot name if name is set but category isn't
if ($botName !== '' && $botCat === '') {
    $botCat = (string) (\Trafic\UserAgent::categoryOf($botName) ?? '');
}

// If an IP was given, look up country
$geoLookup = null;
if ($ip !== '') {
    $geo = new GeoIP($cfg['geoip_db'] ?? null);
    $geoLookup = $geo->lookup($ip);
    if (!$country && $geoLookup['code']) {
        $country = (string) $geoLookup['code'];
    }
}

// Build context for the evaluator
$ctx = [
    'country_code'  => $country ?: null,
    'language'      => $language ?: null,
    'device_type'   => $device ?: null,
    'os'            => $os ?: null,
    'browser'       => $browser ?: null,
    'bot_name'      => $botName,
    'bot_category'  => $botCat,
    'ad_platform'   => $adPlat,
    'referrer_host' => $refHost ?: null,
];

// Trace evaluation step-by-step
$trace = [];
$finalUrl = null;
$matchedNodeId = null;
$ran = ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) && $selected;

if ($ran && $selected['root_node_id']) {
    $nodes = $db->all('SELECT * FROM tree_nodes WHERE campaign_id = ?', [(int) $selected['id']]);
    $cases = $db->all(
        'SELECT c.* FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE n.campaign_id = ? ORDER BY c.sort_order, c.id',
        [(int) $selected['id']]
    );
    $byId = [];
    foreach ($nodes as $n) { $n['cases'] = []; $byId[(int) $n['id']] = $n; }
    foreach ($cases as $c) {
        $pid = (int) $c['parent_node_id'];
        if (isset($byId[$pid])) $byId[$pid]['cases'][] = $c;
    }

    $varToKey = [
        'country' => 'country_code', 'language' => 'language', 'device' => 'device_type',
        'os' => 'os', 'browser' => 'browser', 'bot' => 'bot_name',
        'bot_category' => 'bot_category', 'ad_platform' => 'ad_platform',
        'referrer_host' => 'referrer_host',
    ];

    $current = (int) $selected['root_node_id'];
    $guard = 0;
    while ($current && isset($byId[$current])) {
        if (++$guard > 50) { $trace[] = ['type' => 'error', 'msg' => 'Cycle / depth limit hit.']; break; }
        $node = $byId[$current];
        if ($node['node_type'] === 'redirect') {
            $trace[] = ['type' => 'redirect', 'node' => $node];
            $finalUrl = $node['redirect_url'];
            $matchedNodeId = (int) $node['id'];
            break;
        }
        // check node
        $variable = (string) $node['variable'];
        $value = (string) ($ctx[$varToKey[$variable] ?? $variable] ?? '');
        $matchedCase = null;
        $defaultCase = null;
        foreach ($node['cases'] as $case) {
            if ($case['match_operator'] === 'default') { $defaultCase = $case; continue; }
            if (TreeEvaluator::matches($value, $case['match_operator'], (string) ($case['match_value'] ?? ''))) {
                $matchedCase = $case;
                break;
            }
        }
        $used = $matchedCase ?? $defaultCase;
        $trace[] = ['type' => 'check', 'node' => $node, 'value' => $value, 'case' => $used, 'is_default' => ($matchedCase === null && $defaultCase !== null)];
        if (!$used || !$used['child_node_id']) {
            $trace[] = ['type' => 'dead_end', 'msg' => $used ? 'Case has no child node linked.' : 'No matching case and no default.'];
            break;
        }
        $current = (int) $used['child_node_id'];
    }
} elseif ($ran && !$selected['root_node_id']) {
    $trace[] = ['type' => 'error', 'msg' => 'Campaign has no root node yet.'];
}

if ($ran && $finalUrl === null && $selected && !empty($selected['default_redirect_url'])) {
    $trace[] = ['type' => 'fallback', 'url' => $selected['default_redirect_url']];
    $finalUrl = $selected['default_redirect_url'];
}

layout_head('Test redirect');
?>
<h1>Test redirect</h1>
<p class="muted">Simulate how a visitor with specific attributes would be routed. No hits are logged.</p>

<div class="card">
<form method="post">
  <div class="field">
    <label>Campaign</label>
    <select name="campaign">
      <?php foreach ($campaigns as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $selectedId === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> <span class="muted">(<?= h($c['slug']) ?>)</span></option>
      <?php endforeach; ?>
    </select>
  </div>

  <h2 style="margin-top:18px">⚡ Hazır şablonlar <span class="muted" style="font-size:12px">(tıkla, alanlar otomatik dolar)</span></h2>
  <div id="presets" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
    <?php foreach ($presets as $key => $p): ?>
      <button type="button" class="preset-chip"
              data-preset='<?= h(json_encode($p['data'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
              title="<?= h($p['desc']) ?>">
        <span style="font-size:16px"><?= $p['icon'] ?></span>
        <?= h($p['label']) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <h2 style="margin-top:18px">Option A — paste a User-Agent + IP (auto-detects device/OS/browser/bot/country)</h2>
  <div class="row">
    <div class="col">
      <label>User-Agent</label>
      <input type="text" name="ua" value="<?= h($uaString) ?>" placeholder='Mozilla/5.0 ... or "GPTBot/1.0"'>
    </div>
    <div class="col" style="flex:0 0 240px">
      <label>IP address</label>
      <input type="text" name="ip" value="<?= h($ip) ?>" placeholder="e.g. 8.8.8.8">
      <?php if ($geoLookup && $geoLookup['code']): ?>
        <div class="help">→ Resolved to <code><?= h($geoLookup['code']) ?></code> (<?= h((string) $geoLookup['name']) ?>)</div>
      <?php elseif ($ip): ?>
        <div class="help">No GeoIP result (DB missing or private IP?)</div>
      <?php endif; ?>
    </div>
  </div>

  <h2 style="margin-top:18px">Option B — override / set variables directly</h2>
  <div class="row">
    <div class="col"><label>Country (2-letter)</label><input type="text" name="country" maxlength="2" value="<?= h($country) ?>" placeholder="US"></div>
    <div class="col"><label>Language</label><input type="text" name="language" maxlength="5" value="<?= h($language) ?>" placeholder="en"></div>
    <div class="col"><label>Device</label>
      <select name="device">
        <option value="">(any)</option>
        <?php foreach (['mobile','tablet','desktop'] as $o): ?>
          <option <?= $device === $o ? 'selected' : '' ?>><?= $o ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col"><label>OS</label>
      <select name="os">
        <option value="">(any)</option>
        <?php foreach (['Windows 10/11','Windows','iOS','macOS','Android','Linux','ChromeOS','Other'] as $o): ?>
          <option <?= $os === $o ? 'selected' : '' ?>><?= h($o) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col"><label>Browser</label>
      <select name="browser">
        <option value="">(any)</option>
        <?php foreach (['Chrome','Safari','Firefox','Edge','Opera','Samsung','UC','IE','Other'] as $o): ?>
          <option <?= $browser === $o ? 'selected' : '' ?>><?= $o ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col"><label>Bot name <span class="muted">(blank = human)</span></label><input type="text" name="bot" value="<?= h($botName) ?>" placeholder="GPTBot, Googlebot, ..."></div>
    <div class="col"><label>Bot category</label>
      <select name="bot_category">
        <option value="">(auto from bot name)</option>
        <?php foreach (['ai','ads_google','ads_meta','ads_tiktok','ads_bing','search','seo','social','monitor','archive','generic'] as $cat): ?>
          <option <?= $botCat === $cat ? 'selected' : '' ?>><?= $cat ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col"><label>Ad platform <span class="muted">(click-ID)</span></label>
      <select name="ad_platform">
        <option value="">(none — not from ad)</option>
        <?php foreach (['google_ads','meta_ads','tiktok_ads','bing_ads','twitter_ads','linkedin_ads','pinterest_ads','reddit_ads','snapchat_ads','impact_ads','yandex_ads'] as $plat): ?>
          <option <?= $adPlat === $plat ? 'selected' : '' ?>><?= $plat ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col"><label>Referrer host</label><input type="text" name="referrer_host" value="<?= h($refHost) ?>" placeholder="google.com"></div>
  </div>

  <p style="margin-top:14px">
    <button class="btn">Run test</button>
    <button type="button" class="btn btn-secondary" id="clear-btn">Temizle</button>
  </p>
</form>
</div>

<style>
.preset-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 16px;
    background: #f3f4f6; border: 1px solid #e5e7eb;
    font-size: 13px; cursor: pointer;
    transition: all 0.15s;
}
.preset-chip:hover { background: #2563eb; color: #fff; border-color: #2563eb; }
.preset-chip.active { background: #1e40af; color: #fff; border-color: #1e40af; }
</style>

<script>
(function() {
    const FORM = document.querySelector('form');
    const FIELDS = ['ua','ip','country','language','device','os','browser','bot','bot_category','ad_platform','referrer_host'];

    function setField(name, value) {
        const el = FORM.querySelector('[name="' + name + '"]');
        if (!el) return;
        if (el.tagName === 'SELECT') {
            // For selects, find matching option (case-insensitive) or set to '' if no match
            const v = (value || '').toString();
            let found = false;
            for (const opt of el.options) {
                if (opt.value.toLowerCase() === v.toLowerCase()) {
                    el.value = opt.value;
                    found = true;
                    break;
                }
            }
            if (!found) el.value = '';
        } else {
            el.value = value || '';
        }
    }

    function clearAll() {
        FIELDS.forEach(f => setField(f, ''));
        document.querySelectorAll('.preset-chip.active').forEach(c => c.classList.remove('active'));
    }

    function applyPreset(data, chipEl) {
        clearAll();
        Object.entries(data).forEach(([k, v]) => setField(k, v));
        document.querySelectorAll('.preset-chip').forEach(c => c.classList.remove('active'));
        if (chipEl) chipEl.classList.add('active');
    }

    document.querySelectorAll('.preset-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const data = JSON.parse(chip.dataset.preset);
            applyPreset(data, chip);
        });
    });

    document.getElementById('clear-btn').addEventListener('click', clearAll);
})();
</script>

<?php if ($ran): ?>
  <div class="card">
    <h2>Result</h2>
    <p>Final URL:
      <?php if ($finalUrl): ?>
        <a href="<?= h($finalUrl) ?>" target="_blank" rel="noopener"><code><?= h($finalUrl) ?></code></a>
      <?php else: ?>
        <em>No URL — visitor would receive 204 No Content.</em>
      <?php endif; ?>
    </p>

    <h2 style="margin-top:18px">Visitor context used</h2>
    <table style="max-width:520px">
      <?php foreach ([
        'country' => $ctx['country_code'], 'language' => $ctx['language'], 'device' => $ctx['device_type'],
        'os' => $ctx['os'], 'browser' => $ctx['browser'],
        'bot' => $ctx['bot_name'] ?: '(none — human)',
        'bot_category' => $ctx['bot_category'] ?: '(none — human)',
        'ad_platform' => $ctx['ad_platform'] ?: '(none — not from ad)',
        'referrer_host' => $ctx['referrer_host'],
      ] as $k => $v): ?>
        <tr><td style="width:160px"><code><?= h($k) ?></code></td><td><?= h((string) $v) ?: '<span class="muted">(empty)</span>' ?></td></tr>
      <?php endforeach; ?>
    </table>

    <h2 style="margin-top:18px">Evaluation trace</h2>
    <ol>
      <?php foreach ($trace as $step): ?>
        <li style="margin-bottom:6px">
          <?php if ($step['type'] === 'check'):
              $n = $step['node']; $case = $step['case']; ?>
            <strong>Check</strong> <code><?= h($n['variable']) ?></code> (visitor value: <code><?= h($step['value']) ?: '∅' ?></code>)
            <?php if ($case): ?>
              → matched <?= $step['is_default'] ? 'the <strong>default</strong> case' : 'case <em>"' . h($case['match_operator']) . ' ' . h((string) $case['match_value']) . '"</em>' ?>
              <?php if ($case['label']): ?> <span class="muted">— <?= h($case['label']) ?></span><?php endif; ?>
            <?php else: ?>
              → <span style="color:#b91c1c">no case matched and no default exists</span>
            <?php endif; ?>
          <?php elseif ($step['type'] === 'redirect'): ?>
            <strong>Redirect</strong> → <code><?= h((string) $step['node']['redirect_url']) ?></code>
          <?php elseif ($step['type'] === 'fallback'): ?>
            <strong>Fallback to campaign default</strong> → <code><?= h((string) $step['url']) ?></code>
          <?php elseif ($step['type'] === 'dead_end' || $step['type'] === 'error'): ?>
            <span style="color:#b91c1c"><?= h($step['msg']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
<?php endif; ?>

<?php layout_foot(); ?>
