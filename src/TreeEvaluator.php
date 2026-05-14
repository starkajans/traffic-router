<?php
declare(strict_types=1);

namespace Trafic;

/**
 * Evaluates a campaign's decision tree against the visitor context.
 *
 * Tree shape (loaded into memory):
 *   node = ['id', 'node_type', 'variable', 'redirect_url', 'label', 'cases' => [case, ...]]
 *   case = ['id', 'child_node_id', 'match_operator', 'match_value', 'label', 'sort_order']
 *
 * Variables: country, language, device, os, browser, bot, referrer_host
 * Operators: equals, in, not_in, contains, starts_with, regex, default
 */
final class TreeEvaluator
{
    public const VARIABLES = ['country', 'language', 'device', 'os', 'browser', 'bot', 'bot_category', 'ad_platform', 'referrer_host'];
    public const OPERATORS = ['equals', 'in', 'not_in', 'contains', 'starts_with', 'regex', 'default'];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Walk the tree. Returns ['url' => string|null, 'matched_node_id' => int|null, 'delivery_mode' => string|null].
     */
    public function evaluate(int $rootNodeId, array $context): array
    {
        $nodes = $this->loadTree($rootNodeId);
        if (!isset($nodes[$rootNodeId])) {
            return ['url' => null, 'matched_node_id' => null, 'delivery_mode' => null];
        }

        $currentId = $rootNodeId;
        $guard = 0;
        while ($currentId !== null && isset($nodes[$currentId])) {
            if (++$guard > 100) break; // safety against cycles
            $node = $nodes[$currentId];

            if ($node['node_type'] === 'redirect') {
                return [
                    'url'             => $node['redirect_url'] ?: null,
                    'matched_node_id' => (int) $node['id'],
                    'delivery_mode'   => $node['delivery_mode'] ?? 'redirect',
                ];
            }

            // check node — pick the first matching case
            $value = self::contextValue($context, (string) $node['variable']);
            $nextId = null;
            $defaultId = null;
            foreach ($node['cases'] as $case) {
                if ($case['match_operator'] === 'default') {
                    $defaultId = $case['child_node_id'] !== null ? (int) $case['child_node_id'] : null;
                    continue;
                }
                if (self::matches($value, $case['match_operator'], (string) ($case['match_value'] ?? ''))) {
                    $nextId = $case['child_node_id'] !== null ? (int) $case['child_node_id'] : null;
                    break;
                }
            }
            if ($nextId === null) {
                $nextId = $defaultId;
            }
            if ($nextId === null) {
                return ['url' => null, 'matched_node_id' => (int) $node['id'], 'delivery_mode' => null];
            }
            $currentId = $nextId;
        }

        return ['url' => null, 'matched_node_id' => null, 'delivery_mode' => null];
    }

    private static function contextValue(array $ctx, string $variable): string
    {
        $map = [
            'country'       => 'country_code',
            'language'      => 'language',
            'device'        => 'device_type',
            'os'            => 'os',
            'browser'       => 'browser',
            'bot'           => 'bot_name',
            'bot_category'  => 'bot_category',
            'ad_platform'   => 'ad_platform',
            'referrer_host' => 'referrer_host',
        ];
        $key = $map[$variable] ?? $variable;
        $v = $ctx[$key] ?? null;
        return $v === null ? '' : (string) $v;
    }

    public static function matches(string $value, string $op, string $raw): bool
    {
        $value = strtolower(trim($value));
        switch ($op) {
            case 'equals':
                return $value === strtolower(trim($raw));
            case 'in':
            case 'not_in':
                $list = self::parseList($raw);
                $hit = in_array($value, $list, true);
                return $op === 'in' ? $hit : !$hit;
            case 'contains':
                return $raw !== '' && strpos($value, strtolower($raw)) !== false;
            case 'starts_with':
                return $raw !== '' && strpos($value, strtolower($raw)) === 0;
            case 'regex':
                $pattern = '~' . str_replace('~', '\~', $raw) . '~i';
                return @preg_match($pattern, $value) === 1;
            case 'default':
                return true;
        }
        return false;
    }

    /** Parse "US, CA, DE" or JSON array into lowercase trimmed list */
    public static function parseList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            return array_map(static fn($v) => strtolower(trim((string) $v)), $parsed);
        }
        $parts = preg_split('~[,;\n\r]+~', $raw) ?: [];
        return array_values(array_filter(array_map(
            static fn($v) => strtolower(trim($v)),
            $parts
        ), static fn($v) => $v !== ''));
    }

    /**
     * Load all nodes for a campaign reachable from $rootNodeId in one shot.
     * Returns id => node-with-cases.
     */
    private function loadTree(int $rootNodeId): array
    {
        // Find the campaign for the root node, then load everything in two queries
        $root = $this->db->one('SELECT campaign_id FROM tree_nodes WHERE id = ?', [$rootNodeId]);
        if (!$root) return [];
        $campaignId = (int) $root['campaign_id'];

        $rows = $this->db->all(
            'SELECT id, node_type, variable, redirect_url, delivery_mode, label FROM tree_nodes WHERE campaign_id = ?',
            [$campaignId]
        );
        $nodes = [];
        foreach ($rows as $r) {
            $r['id'] = (int) $r['id'];
            $r['cases'] = [];
            $nodes[$r['id']] = $r;
        }

        $cases = $this->db->all(
            'SELECT c.id, c.parent_node_id, c.child_node_id, c.match_operator, c.match_value, c.label, c.sort_order
             FROM tree_cases c
             INNER JOIN tree_nodes n ON n.id = c.parent_node_id
             WHERE n.campaign_id = ?
             ORDER BY c.sort_order, c.id',
            [$campaignId]
        );
        foreach ($cases as $c) {
            $pid = (int) $c['parent_node_id'];
            if (isset($nodes[$pid])) {
                $nodes[$pid]['cases'][] = $c;
            }
        }
        return $nodes;
    }
}
