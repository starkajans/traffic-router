<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;
use Trafic\Bootstrap;
use Trafic\TreeEvaluator;

Auth::require();
$db = Bootstrap::db();

$campaignId = isset($_GET['campaign']) ? (int) $_GET['campaign'] : 0;
$campaign = $db->one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
if (!$campaign) {
    flash('Campaign not found.', 'err');
    header('Location: ' . admin_url('/'));
    exit;
}

// --- ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_node') {
        $type = $_POST['node_type'] === 'check' ? 'check' : 'redirect';
        $variable = $type === 'check' ? (string) ($_POST['variable'] ?? '') : null;
        if ($type === 'check' && !in_array($variable, TreeEvaluator::VARIABLES, true)) {
            flash('Invalid variable.', 'err'); back($campaignId);
        }
        $delivery = 'redirect';
        if ($type === 'redirect') {
            $delivery = ($_POST['delivery_mode'] ?? '') === 'proxy' ? 'proxy' : 'redirect';
        }
        $newId = $db->insert('tree_nodes', [
            'campaign_id'   => $campaignId,
            'node_type'     => $type,
            'variable'      => $variable,
            'redirect_url'  => $type === 'redirect' ? trim((string) ($_POST['redirect_url'] ?? '')) : null,
            'delivery_mode' => $delivery,
            'label'         => trim((string) ($_POST['label'] ?? '')) ?: null,
        ]);

        $linkCaseId = (int) ($_POST['link_case_id'] ?? 0);
        $asRoot = !empty($_POST['as_root']);
        if ($linkCaseId) {
            // Verify the case belongs to this campaign
            $case = $db->one(
                'SELECT c.id FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE c.id = ? AND n.campaign_id = ?',
                [$linkCaseId, $campaignId]
            );
            if ($case) {
                $db->update('tree_cases', ['child_node_id' => $newId], ['id' => $linkCaseId]);
            }
        } elseif ($asRoot) {
            $db->update('campaigns', ['root_node_id' => $newId], ['id' => $campaignId]);
        }
        flash('Node created.');
        back($campaignId);
    }

    if ($action === 'update_node') {
        $id = (int) ($_POST['id'] ?? 0);
        $node = $db->one('SELECT * FROM tree_nodes WHERE id = ? AND campaign_id = ?', [$id, $campaignId]);
        if (!$node) { flash('Node not found.', 'err'); back($campaignId); }
        $data = ['label' => trim((string) ($_POST['label'] ?? '')) ?: null];
        if ($node['node_type'] === 'check') {
            $v = (string) ($_POST['variable'] ?? '');
            if (!in_array($v, TreeEvaluator::VARIABLES, true)) { flash('Invalid variable.', 'err'); back($campaignId); }
            $data['variable'] = $v;
        } else {
            $url = trim((string) ($_POST['redirect_url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                flash('Redirect URL is not valid.', 'err'); back($campaignId);
            }
            $data['redirect_url']  = $url ?: null;
            $data['delivery_mode'] = ($_POST['delivery_mode'] ?? '') === 'proxy' ? 'proxy' : 'redirect';
        }
        $db->update('tree_nodes', $data, ['id' => $id]);
        flash('Node updated.');
        back($campaignId);
    }

    if ($action === 'delete_node') {
        $id = (int) ($_POST['id'] ?? 0);
        $node = $db->one('SELECT * FROM tree_nodes WHERE id = ? AND campaign_id = ?', [$id, $campaignId]);
        if ($node) {
            // Detach from root or from any cases pointing to it
            $db->run('UPDATE campaigns SET root_node_id = NULL WHERE root_node_id = ?', [$id]);
            $db->run('UPDATE tree_cases SET child_node_id = NULL WHERE child_node_id = ?', [$id]);
            $db->delete('tree_nodes', ['id' => $id]); // cascades own cases
            flash('Node deleted.');
        }
        back($campaignId);
    }

    if ($action === 'create_case') {
        $parent = (int) ($_POST['parent_node_id'] ?? 0);
        $node = $db->one('SELECT * FROM tree_nodes WHERE id = ? AND campaign_id = ? AND node_type = "check"', [$parent, $campaignId]);
        if (!$node) { flash('Parent node not found.', 'err'); back($campaignId); }
        $op = (string) ($_POST['match_operator'] ?? '');
        if (!in_array($op, TreeEvaluator::OPERATORS, true)) { flash('Invalid operator.', 'err'); back($campaignId); }
        $val = $op === 'default' ? null : trim((string) ($_POST['match_value'] ?? ''));
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $db->insert('tree_cases', [
            'parent_node_id' => $parent,
            'match_operator' => $op,
            'match_value'    => $val,
            'label'          => trim((string) ($_POST['label'] ?? '')) ?: null,
            'sort_order'     => $sort,
        ]);
        flash('Case added.');
        back($campaignId);
    }

    if ($action === 'update_case') {
        $id = (int) ($_POST['id'] ?? 0);
        $case = $db->one(
            'SELECT c.* FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE c.id = ? AND n.campaign_id = ?',
            [$id, $campaignId]
        );
        if (!$case) { flash('Case not found.', 'err'); back($campaignId); }
        $op = (string) ($_POST['match_operator'] ?? '');
        if (!in_array($op, TreeEvaluator::OPERATORS, true)) { flash('Invalid operator.', 'err'); back($campaignId); }
        $db->update('tree_cases', [
            'match_operator' => $op,
            'match_value'    => $op === 'default' ? null : trim((string) ($_POST['match_value'] ?? '')),
            'label'          => trim((string) ($_POST['label'] ?? '')) ?: null,
            'sort_order'     => (int) ($_POST['sort_order'] ?? 0),
        ], ['id' => $id]);
        flash('Case updated.');
        back($campaignId);
    }

    if ($action === 'delete_case') {
        $id = (int) ($_POST['id'] ?? 0);
        $case = $db->one(
            'SELECT c.* FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE c.id = ? AND n.campaign_id = ?',
            [$id, $campaignId]
        );
        if ($case) {
            $db->delete('tree_cases', ['id' => $id]);
            flash('Case deleted (child node, if any, kept).');
        }
        back($campaignId);
    }

    if ($action === 'detach_case_child') {
        $id = (int) ($_POST['id'] ?? 0);
        $case = $db->one(
            'SELECT c.* FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE c.id = ? AND n.campaign_id = ?',
            [$id, $campaignId]
        );
        if ($case) {
            $db->update('tree_cases', ['child_node_id' => null], ['id' => $id]);
            flash('Child detached.');
        }
        back($campaignId);
    }

    if ($action === 'link_case_child') {
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $nodeId = (int) ($_POST['child_node_id'] ?? 0);
        $case = $db->one(
            'SELECT c.* FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE c.id = ? AND n.campaign_id = ?',
            [$caseId, $campaignId]
        );
        $node = $nodeId ? $db->one('SELECT id FROM tree_nodes WHERE id = ? AND campaign_id = ?', [$nodeId, $campaignId]) : null;
        if ($case && $node) {
            $db->update('tree_cases', ['child_node_id' => $nodeId], ['id' => $caseId]);
            flash('Linked.');
        } else {
            flash('Link failed.', 'err');
        }
        back($campaignId);
    }

    back($campaignId);
}

function back(int $cid): void {
    header('Location: ' . admin_url('/tree.php?campaign=' . $cid));
    exit;
}

// --- LOAD TREE ---
$nodes = $db->all('SELECT * FROM tree_nodes WHERE campaign_id = ? ORDER BY id', [$campaignId]);
$cases = $db->all(
    'SELECT c.* FROM tree_cases c JOIN tree_nodes n ON n.id = c.parent_node_id WHERE n.campaign_id = ? ORDER BY c.sort_order, c.id',
    [$campaignId]
);
$nodesById = [];
foreach ($nodes as $n) {
    $n['cases'] = [];
    $nodesById[(int) $n['id']] = $n;
}
foreach ($cases as $c) {
    $pid = (int) $c['parent_node_id'];
    if (isset($nodesById[$pid])) {
        $nodesById[$pid]['cases'][] = $c;
    }
}

// Track which nodes are referenced as children (for "orphan" listing)
$referenced = [];
foreach ($cases as $c) {
    if ($c['child_node_id']) {
        $referenced[(int) $c['child_node_id']] = true;
    }
}
if ($campaign['root_node_id']) $referenced[(int) $campaign['root_node_id']] = true;
$orphans = [];
foreach ($nodesById as $id => $n) {
    if (empty($referenced[$id])) $orphans[] = $n;
}

$csrf = Auth::csrfToken();
$editNode = isset($_GET['edit_node']) ? ($nodesById[(int) $_GET['edit_node']] ?? null) : null;
$editCase = null;
if (isset($_GET['edit_case'])) {
    foreach ($cases as $c) if ((int) $c['id'] === (int) $_GET['edit_case']) { $editCase = $c; break; }
}

$VAR_LABELS = [
    'country'       => 'Country (2-letter code)',
    'language'      => 'Language (2-letter code)',
    'device'        => 'Device (mobile/tablet/desktop)',
    'os'            => 'OS (Windows/iOS/Android/macOS/Linux)',
    'browser'       => 'Browser (Chrome/Safari/Firefox/...)',
    'bot'           => 'Bot name (GPTBot, Googlebot, ... blank=human)',
    'bot_category'  => 'Bot category (ai / ads_google / ads_meta / ads_tiktok / ads_bing / search / seo / social / monitor / archive / generic; blank=human)',
    'ad_platform'   => 'Ad platform (real users clicking from ads — google_ads / meta_ads / tiktok_ads / bing_ads / twitter_ads / linkedin_ads / pinterest_ads / reddit_ads / snapchat_ads; blank=not from ad)',
    'referrer_host' => 'Referrer host (e.g. google.com)',
];
$OP_LABELS = [
    'equals'      => 'equals',
    'in'          => 'is in list',
    'not_in'      => 'is NOT in list',
    'contains'    => 'contains',
    'starts_with' => 'starts with',
    'regex'       => 'matches regex',
    'default'     => 'default (catch-all)',
];

function render_node(int $id, array $nodesById, string $csrf, array $VAR_LABELS, array $OP_LABELS): void {
    $node = $nodesById[$id] ?? null;
    if (!$node) {
        echo '<div class="tree-node" style="border-left-color:#dc2626"><em>Missing node #' . (int) $id . '</em></div>';
        return;
    }
    $type = $node['node_type'];
    $editUrl = admin_url('/tree.php?campaign=' . (int) $node['campaign_id'] . '&edit_node=' . (int) $node['id']);
    echo '<div class="tree-node ' . h($type) . '">';
    echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">';
    echo '<div>';
    if ($type === 'check') {
        echo '<strong>Check</strong> <code>' . h($node['variable']) . '</code>';
        if ($node['label']) echo ' <span class="muted">— ' . h($node['label']) . '</span>';
    } else {
        echo '<strong>Redirect →</strong> ';
        if ($node['redirect_url']) {
            echo '<a href="' . h($node['redirect_url']) . '" target="_blank" rel="noopener">' . h($node['redirect_url']) . '</a>';
        } else {
            echo '<span class="muted">(no URL set)</span>';
        }
        if (($node['delivery_mode'] ?? 'redirect') === 'proxy') {
            echo ' <span class="badge" style="background:#dbeafe;color:#1e40af">PROXY</span>';
        }
        if ($node['label']) echo ' <span class="muted">— ' . h($node['label']) . '</span>';
    }
    echo ' <span class="muted" style="font-size:11px">#' . (int) $node['id'] . '</span>';
    echo '</div>';
    echo '<div class="actions">';
    echo '<a class="btn btn-sm btn-secondary" href="' . h($editUrl) . '">Edit</a>';
    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this node?\')">';
    echo '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
    echo '<input type="hidden" name="action" value="delete_node">';
    echo '<input type="hidden" name="id" value="' . (int) $node['id'] . '">';
    echo '<button class="btn btn-sm btn-danger">Delete</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>'; // /flex header

    if ($type === 'check') {
        echo '<div class="tree-children">';
        if (!$node['cases']) {
            echo '<div class="muted" style="padding:6px 0">No cases yet. Add one below.</div>';
        }
        foreach ($node['cases'] as $case) {
            echo '<div style="margin-top:8px">';
            echo '<div class="tree-case-label">';
            echo '<strong>Case:</strong> ';
            $op = $case['match_operator'];
            echo h($OP_LABELS[$op] ?? $op);
            if ($op !== 'default') {
                echo ' <code>' . h((string) $case['match_value']) . '</code>';
            }
            if ($case['label']) echo ' <span class="muted">— ' . h($case['label']) . '</span>';
            echo '  <a href="' . h(admin_url('/tree.php?campaign=' . (int) $node['campaign_id'] . '&edit_case=' . (int) $case['id'])) . '">edit</a>';
            echo '  <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this case? (child node stays)\')">';
            echo '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
            echo '<input type="hidden" name="action" value="delete_case">';
            echo '<input type="hidden" name="id" value="' . (int) $case['id'] . '">';
            echo '<button class="btn btn-sm btn-secondary" style="padding:1px 6px;font-size:11px">delete</button>';
            echo '</form>';
            echo '</div>';

            if ($case['child_node_id']) {
                render_node((int) $case['child_node_id'], $nodesById, $csrf, $VAR_LABELS, $OP_LABELS);
            } else {
                echo '<div class="tree-node" style="border-left-color:#9ca3af;background:#fafbfc">';
                echo '<span class="muted">Empty — add a child:</span> ';
                echo '<a class="btn btn-sm" href="?campaign=' . (int) $node['campaign_id'] . '&add_for_case=' . (int) $case['id'] . '&type=redirect">+ Redirect</a> ';
                echo '<a class="btn btn-sm btn-secondary" href="?campaign=' . (int) $node['campaign_id'] . '&add_for_case=' . (int) $case['id'] . '&type=check">+ Check</a>';
                echo '</div>';
            }
            echo '</div>';
        }
        // Form to add a case
        echo '<details style="margin-top:10px"><summary class="muted" style="cursor:pointer">+ Add case</summary>';
        echo '<form method="post" style="margin-top:8px;padding:10px;background:#f9fafb;border-radius:6px">';
        echo '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
        echo '<input type="hidden" name="action" value="create_case">';
        echo '<input type="hidden" name="parent_node_id" value="' . (int) $node['id'] . '">';
        echo '<div class="row">';
        echo '<div class="col"><label>Operator</label><select name="match_operator">';
        foreach ($OP_LABELS as $k => $v) echo '<option value="' . h($k) . '">' . h($v) . '</option>';
        echo '</select></div>';
        echo '<div class="col"><label>Value(s)</label><input type="text" name="match_value" placeholder="e.g. US, CA, DE"></div>';
        echo '<div class="col"><label>Label (optional)</label><input type="text" name="label"></div>';
        echo '<div class="col" style="flex:0 0 80px"><label>Order</label><input type="number" name="sort_order" value="0"></div>';
        echo '</div>';
        echo '<button class="btn btn-sm" style="margin-top:8px">Add case</button>';
        echo '</form></details>';
        echo '</div>'; // /tree-children
    }
    echo '</div>'; // /tree-node
}

layout_head('Tree — ' . $campaign['name']);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <h1 style="margin:0">Decision tree — <?= h($campaign['name']) ?></h1>
  <div class="actions">
    <a class="btn btn-secondary" href="<?= h(admin_url('/campaign.php?id=' . (int) $campaign['id'])) ?>">Settings</a>
    <a class="btn btn-secondary" href="<?= h(admin_url('/test.php?campaign=' . (int) $campaign['id'])) ?>">Test</a>
    <a class="btn btn-secondary" href="<?= h(admin_url('/analytics.php?campaign=' . (int) $campaign['id'])) ?>">Analytics</a>
    <a class="btn btn-secondary" href="<?= h(admin_url('/')) ?>">All campaigns</a>
  </div>
</div>

<div class="card">
  <p>
    URL: <code><?= h(\Trafic\Bootstrap::isHttps() ? 'https://' : 'http://') ?><?= h($_SERVER['HTTP_HOST'] ?? '') ?><?= h(base_url() . '/go/' . $campaign['slug']) ?></code>
    &nbsp;·&nbsp; Default fallback: <?= $campaign['default_redirect_url'] ? '<code>' . h($campaign['default_redirect_url']) . '</code>' : '<em class="muted">none</em>' ?>
  </p>
</div>

<?php
// === Add/edit forms (rendered above the tree when active) ===

if (isset($_GET['add_for_case']) || isset($_GET['add_root'])) {
    $caseId = (int) ($_GET['add_for_case'] ?? 0);
    $asRoot = isset($_GET['add_root']);
    $type = ($_GET['type'] ?? 'redirect') === 'check' ? 'check' : 'redirect';
    ?>
    <div class="card">
      <h2>Add <?= h($type) ?> node <?= $caseId ? ' (link to case #' . (int) $caseId . ')' : ($asRoot ? ' as root' : '') ?></h2>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create_node">
        <input type="hidden" name="node_type" value="<?= h($type) ?>">
        <?php if ($caseId): ?><input type="hidden" name="link_case_id" value="<?= $caseId ?>"><?php endif; ?>
        <?php if ($asRoot): ?><input type="hidden" name="as_root" value="1"><?php endif; ?>
        <?php if ($type === 'check'): ?>
          <div class="field">
            <label>Variable to check</label>
            <select name="variable">
              <?php foreach ($VAR_LABELS as $k => $v): ?>
                <option value="<?= h($k) ?>"><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="field">
            <label>Redirect URL</label>
            <input type="url" name="redirect_url" required placeholder="https://example.com/landing">
          </div>
          <div class="field">
            <label>Delivery mode</label>
            <select name="delivery_mode">
              <option value="redirect">Redirect (302 — fast, visible URL change)</option>
              <option value="proxy">Proxy (server fetches destination, URL bar stays /go/{slug})</option>
            </select>
            <div class="help">Proxy mode hides the destination URL but only works for simple landing pages — complex JS/SPAs may break. See README.</div>
          </div>
        <?php endif; ?>
        <div class="field">
          <label>Label (optional)</label>
          <input type="text" name="label" placeholder="Short note shown in the tree">
        </div>
        <button class="btn">Create</button>
        <a class="btn btn-secondary" href="<?= h(admin_url('/tree.php?campaign=' . (int) $campaign['id'])) ?>">Cancel</a>
      </form>
    </div>
<?php
} elseif ($editNode) {
    ?>
    <div class="card">
      <h2>Edit node #<?= (int) $editNode['id'] ?> (<?= h($editNode['node_type']) ?>)</h2>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="update_node">
        <input type="hidden" name="id" value="<?= (int) $editNode['id'] ?>">
        <?php if ($editNode['node_type'] === 'check'): ?>
          <div class="field">
            <label>Variable</label>
            <select name="variable">
              <?php foreach ($VAR_LABELS as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $editNode['variable'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="field">
            <label>Redirect URL</label>
            <input type="url" name="redirect_url" value="<?= h($editNode['redirect_url'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Delivery mode</label>
            <select name="delivery_mode">
              <option value="redirect" <?= ($editNode['delivery_mode'] ?? 'redirect') === 'redirect' ? 'selected' : '' ?>>Redirect (302 — fast, visible URL change)</option>
              <option value="proxy" <?= ($editNode['delivery_mode'] ?? '') === 'proxy' ? 'selected' : '' ?>>Proxy (server fetches destination, URL bar stays /go/{slug})</option>
            </select>
            <div class="help">Proxy mode hides the destination URL but only works for simple landing pages — complex JS/SPAs may break. See README.</div>
          </div>
        <?php endif; ?>
        <div class="field">
          <label>Label</label>
          <input type="text" name="label" value="<?= h($editNode['label'] ?? '') ?>">
        </div>
        <button class="btn">Save</button>
        <a class="btn btn-secondary" href="<?= h(admin_url('/tree.php?campaign=' . (int) $campaign['id'])) ?>">Cancel</a>
      </form>
    </div>
<?php
} elseif ($editCase) {
    ?>
    <div class="card">
      <h2>Edit case #<?= (int) $editCase['id'] ?></h2>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="update_case">
        <input type="hidden" name="id" value="<?= (int) $editCase['id'] ?>">
        <div class="row">
          <div class="col">
            <label>Operator</label>
            <select name="match_operator">
              <?php foreach ($OP_LABELS as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $editCase['match_operator'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col">
            <label>Value(s)</label>
            <input type="text" name="match_value" value="<?= h($editCase['match_value'] ?? '') ?>" placeholder="e.g. US, CA, DE">
            <div class="help">For "in"/"not_in" use comma-separated values. Country codes are uppercase 2-letter.</div>
          </div>
          <div class="col">
            <label>Label</label>
            <input type="text" name="label" value="<?= h($editCase['label'] ?? '') ?>">
          </div>
          <div class="col" style="flex:0 0 100px">
            <label>Order</label>
            <input type="number" name="sort_order" value="<?= (int) $editCase['sort_order'] ?>">
          </div>
        </div>
        <p style="margin-top:10px">
          <button class="btn">Save</button>
          <a class="btn btn-secondary" href="<?= h(admin_url('/tree.php?campaign=' . (int) $campaign['id'])) ?>">Cancel</a>
        </p>
      </form>
    </div>
<?php
}
?>

<?php if (!$campaign['root_node_id']): ?>
  <div class="card">
    <h2>Start your decision tree</h2>
    <p>This campaign has no root node yet. Add the first one:</p>
    <p class="actions">
      <a class="btn" href="?campaign=<?= (int) $campaign['id'] ?>&add_root=1&type=check">+ Check node (branch by variable)</a>
      <a class="btn btn-secondary" href="?campaign=<?= (int) $campaign['id'] ?>&add_root=1&type=redirect">+ Redirect node (always redirect)</a>
    </p>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Tree</h2>
    <?php render_node((int) $campaign['root_node_id'], $nodesById, $csrf, $VAR_LABELS, $OP_LABELS); ?>
  </div>
<?php endif; ?>

<?php if ($orphans): ?>
  <div class="card">
    <h2>Unlinked nodes <span class="muted" style="font-size:12px">(not reachable from the root)</span></h2>
    <?php foreach ($orphans as $o): ?>
      <?php render_node((int) $o['id'], $nodesById, $csrf, $VAR_LABELS, $OP_LABELS); ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2>How matching works</h2>
  <ul style="margin:0;padding-left:18px">
    <li>The router walks the tree starting at the root.</li>
    <li>At each <em>check</em> node, cases are evaluated <strong>in order of "Order" field</strong>. First matching case wins.</li>
    <li>Use a <code>default</code> case as the catch-all (no value needed).</li>
    <li>For <code>bot</code>: use values like <code>GPTBot, ClaudeBot, Googlebot</code> with <em>"is in list"</em>. Use <em>"equals"</em> with empty value to match humans (no bot detected).</li>
    <li>For <code>bot_category</code>: target whole groups in one case. Values: <code>ai</code>, <code>ads_google</code>, <code>ads_meta</code>, <code>ads_tiktok</code>, <code>ads_bing</code>, <code>search</code>, <code>seo</code>, <code>social</code>, <code>monitor</code>, <code>archive</code>, <code>generic</code>. Empty = human. Use <em>"is in list"</em> with e.g. <code>ads_google, ads_meta, ads_tiktok, ads_bing</code> to match any ad platform's crawler.</li>
    <li><strong>Real ad clicks:</strong> use <code>ad_platform</code> — detected from URL click-IDs (<code>gclid</code> = Google, <code>fbclid</code> = Meta, <code>ttclid</code> = TikTok, <code>msclkid</code> = Bing, etc.). This is the most reliable way to target paid traffic. <code>referrer_host</code> works as fallback for organic social.</li>
    <li>For <code>country</code>: use ISO 2-letter codes (US, GB, DE, TR, ...).</li>
    <li>If no rule matches, the campaign's <strong>default fallback URL</strong> is used.</li>
  </ul>
</div>

<?php layout_foot(); ?>
