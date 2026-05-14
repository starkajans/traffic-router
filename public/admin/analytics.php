<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Bootstrap;

Auth::require();
$db = Bootstrap::db();

$campaignId = isset($_GET['campaign']) ? (int) $_GET['campaign'] : 0;
$days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
$showBots = isset($_GET['include_bots']);

$where = ['hit_at >= (NOW() - INTERVAL ? DAY)'];
$params = [$days];
$campaign = null;
if ($campaignId) {
    $campaign = $db->one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
    if (!$campaign) { flash('Campaign not found.', 'err'); header('Location: ' . admin_url('/')); exit; }
    $where[] = 'campaign_id = ?';
    $params[] = $campaignId;
}
if (!$showBots) {
    $where[] = 'is_bot = 0';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totals = $db->one("SELECT COUNT(*) AS hits, COUNT(DISTINCT ip_address) AS uniques, SUM(is_bot) AS bots FROM hits $whereSql", $params);
$totalHits = (int) ($totals['hits'] ?? 0);

$byCountry = $db->all("SELECT country_code, country_name, COUNT(*) AS n FROM hits $whereSql GROUP BY country_code, country_name ORDER BY n DESC LIMIT 15", $params);
$byDevice  = $db->all("SELECT device_type AS k, COUNT(*) AS n FROM hits $whereSql GROUP BY device_type ORDER BY n DESC", $params);
$byBrowser = $db->all("SELECT browser AS k, COUNT(*) AS n FROM hits $whereSql GROUP BY browser ORDER BY n DESC LIMIT 10", $params);
$byOS      = $db->all("SELECT os AS k, COUNT(*) AS n FROM hits $whereSql GROUP BY os ORDER BY n DESC LIMIT 10", $params);
$byLang    = $db->all("SELECT language AS k, COUNT(*) AS n FROM hits $whereSql GROUP BY language ORDER BY n DESC LIMIT 10", $params);
$botWhereSql = str_replace('is_bot = 0', '1=1', $whereSql) . ' AND is_bot = 1';
$byBotCat  = $db->all("SELECT COALESCE(bot_category,'(unknown)') AS k, COUNT(*) AS n FROM hits $botWhereSql GROUP BY bot_category ORDER BY n DESC", $params);
$byBot     = $db->all("SELECT bot_name AS k, COUNT(*) AS n FROM hits $botWhereSql GROUP BY bot_name ORDER BY n DESC LIMIT 15", $params);
$byRef     = $db->all("SELECT referrer_host AS k, COUNT(*) AS n FROM hits $whereSql AND referrer_host IS NOT NULL GROUP BY referrer_host ORDER BY n DESC LIMIT 10", $params);
$byAdPlat  = $db->all("SELECT ad_platform AS k, COUNT(*) AS n FROM hits $whereSql AND ad_platform IS NOT NULL GROUP BY ad_platform ORDER BY n DESC", $params);
$byDest    = $db->all("SELECT redirect_url AS k, COUNT(*) AS n FROM hits $whereSql AND redirect_url IS NOT NULL GROUP BY redirect_url ORDER BY n DESC LIMIT 10", $params);
$byDay     = $db->all("SELECT DATE(hit_at) AS d, COUNT(*) AS n FROM hits $whereSql GROUP BY DATE(hit_at) ORDER BY d", $params);

$recent = $db->all("SELECT * FROM hits $whereSql ORDER BY hit_at DESC LIMIT 50", $params);

function bar(int $n, int $total): string {
    if ($total <= 0) return '<span class="bar"><span style="width:0"></span></span>';
    $pct = min(100, (int) round($n / $total * 100));
    return '<span class="bar"><span style="width:' . $pct . '%"></span></span>';
}

function kpi_table(array $rows, int $total): string {
    if (!$rows) return '<p class="muted">No data.</p>';
    $out = '<table>';
    foreach ($rows as $r) {
        $label = $r['k'] !== null && $r['k'] !== '' ? $r['k'] : '(unknown)';
        $n = (int) $r['n'];
        $out .= '<tr><td style="width:30%">' . h($label) . '</td><td style="width:50%"><div class="kpi-bar">' . bar($n, $total) . '</div></td><td style="text-align:right">' . number_format($n) . '</td></tr>';
    }
    return $out . '</table>';
}

layout_head('Analytics');
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <h1 style="margin:0">Analytics <?= $campaign ? ' — ' . h($campaign['name']) : '' ?></h1>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <?php if ($campaign): ?><input type="hidden" name="campaign" value="<?= (int) $campaign['id'] ?>"><?php endif; ?>
    <label style="display:inline">Range:</label>
    <select name="days" onchange="this.form.submit()">
      <?php foreach ([1, 7, 30, 90] as $d): ?>
        <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> day<?= $d > 1 ? 's' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <label style="display:inline"><input type="checkbox" name="include_bots" <?= $showBots ? 'checked' : '' ?> onchange="this.form.submit()"> Include bots</label>
  </form>
</div>

<div class="stat-grid">
  <div class="stat"><div class="n"><?= number_format($totalHits) ?></div><div class="l">Hits</div></div>
  <div class="stat"><div class="n"><?= number_format((int) $totals['uniques']) ?></div><div class="l">Unique IPs</div></div>
  <div class="stat"><div class="n"><?= number_format((int) $totals['bots']) ?></div><div class="l">Bot hits</div></div>
  <div class="stat"><div class="n"><?= $totalHits ? number_format(($totals['bots'] / max(1, $totalHits)) * 100, 1) : '0' ?>%</div><div class="l">Bot %</div></div>
</div>

<div class="row" style="margin-top:18px">
  <div class="col card" style="min-width:280px"><h2>Hits per day</h2>
    <?php if (!$byDay): ?><p class="muted">No data.</p><?php else:
        $max = max(array_column($byDay, 'n')); ?>
      <table>
        <?php foreach ($byDay as $d): ?>
          <tr><td style="width:30%"><?= h($d['d']) ?></td>
              <td><div class="kpi-bar"><span class="bar"><span style="width:<?= (int) round($d['n']/$max*100) ?>%"></span></span></div></td>
              <td style="text-align:right"><?= number_format((int) $d['n']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="col card" style="min-width:280px"><h2>Countries</h2>
    <?php if (!$byCountry): ?><p class="muted">No data.</p><?php else: ?>
      <table>
        <?php foreach ($byCountry as $r): ?>
          <tr><td><?= h($r['country_code'] ?? '??') ?> <span class="muted">— <?= h($r['country_name'] ?? 'Unknown') ?></span></td>
              <td><div class="kpi-bar"><?= bar((int) $r['n'], $totalHits) ?></div></td>
              <td style="text-align:right"><?= number_format((int) $r['n']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col card"><h2>Device</h2><?= kpi_table($byDevice, $totalHits) ?></div>
  <div class="col card"><h2>OS</h2><?= kpi_table($byOS, $totalHits) ?></div>
  <div class="col card"><h2>Browser</h2><?= kpi_table($byBrowser, $totalHits) ?></div>
  <div class="col card"><h2>Language</h2><?= kpi_table($byLang, $totalHits) ?></div>
</div>

<div class="row">
  <div class="col card"><h2>Ad platform <span class="muted" style="font-size:12px">(from click-IDs)</span></h2><?= kpi_table($byAdPlat, $totalHits) ?></div>
  <div class="col card"><h2>Referrers</h2><?= kpi_table($byRef, $totalHits) ?></div>
</div>

<div class="row">
  <div class="col card"><h2>Bot category</h2><?= kpi_table($byBotCat, $totalHits) ?></div>
  <div class="col card"><h2>Bots (by name)</h2><?= kpi_table($byBot, $totalHits) ?></div>
</div>

<div class="card"><h2>Destination URLs</h2><?= kpi_table($byDest, $totalHits) ?></div>

<div class="card">
  <h2>Recent hits</h2>
  <?php if (!$recent): ?><p class="muted">No data.</p><?php else: ?>
    <table>
      <thead><tr><th>Time</th><th>IP</th><th>Country</th><th>Device</th><th>OS / Browser</th><th>Lang</th><th>Bot</th><th>Ad</th><th>Referrer</th><th>→ Destination</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><?= h($r['hit_at']) ?></td>
            <td><code><?= h($r['ip_address'] ?? '') ?></code></td>
            <td><?= h($r['country_code'] ?? '') ?></td>
            <td><?= h($r['device_type'] ?? '') ?></td>
            <td><?= h(($r['os'] ?? '') . ' / ' . ($r['browser'] ?? '')) ?></td>
            <td><?= h($r['language'] ?? '') ?></td>
            <td><?php if ($r['is_bot']): ?><span class="badge badge-bot"><?= h($r['bot_name'] ?? 'bot') ?></span><?php endif; ?></td>
            <td><?php if ($r['ad_platform']): ?><span class="badge" style="background:#dbeafe;color:#1e40af"><?= h($r['ad_platform']) ?></span><?php endif; ?></td>
            <td><?= h($r['referrer_host'] ?? '') ?></td>
            <td><?php if ($r['redirect_url']): ?><a href="<?= h($r['redirect_url']) ?>" target="_blank" rel="noopener" title="<?= h($r['redirect_url']) ?>"><?= h(mb_strimwidth($r['redirect_url'], 0, 40, '…')) ?></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php layout_foot(); ?>
