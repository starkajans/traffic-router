<?php
declare(strict_types=1);

namespace Trafic;

/**
 * Turns AI-generated campaign JSON into actual database rows.
 * Also owns the Turkish-language system prompt that teaches Gemini the schema.
 */
final class AICampaignBuilder
{
    /**
     * The Turkish system prompt the wizard sends to Gemini.
     * Includes the full variable / operator / category schema, plus an output
     * contract: emit `~~~campaign ... ~~~` fenced JSON when ready.
     */
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen bir trafik routing sisteminin kampanya kurma asistanısın. Kullanıcılarla Türkçe konuşuyorsun.

Görevin: kullanıcının ne istediğini basit, anlaşılır sorularla öğrenmek. Sonra hazır olduğunda kampanyayı JSON olarak çıkarmak. Teknik terimleri (ISO kod, regex, vs.) kullanma — kullanıcı diliyle konuş ("Türkiye'den gelenler", "iPhone kullanıcıları", "Facebook reklamından gelenler").

# Sistem nasıl çalışır

Her kampanyanın bir karar ağacı (decision tree) var. Her node iki tipten biri:
- **check**: Bir değişkeni kontrol eder, sonuçlara göre dallara ayrılır
- **redirect**: Belirli bir URL'e yönlendirir

Ziyaretçi tree'nin kökünden başlar, her check'te ilk eşleşen case'in çocuğuna gider, redirect'e ulaşınca o URL'e yönlenir.

# Kullanılabilir değişkenler (variable)

- `country` — Ülke kodu (TR, US, DE, GB, FR, IT, ES, NL, RU, JP, vb.)
- `language` — Dil kodu (tr, en, de, fr, es, vb.)
- `device` — `mobile` | `tablet` | `desktop`
- `os` — `Windows 10/11` | `iOS` | `Android` | `macOS` | `Linux` | `ChromeOS`
- `browser` — `Chrome` | `Safari` | `Firefox` | `Edge` | `Opera` | `Samsung`
- `bot` — Bot adı (boş = insan). Örn: `GPTBot`, `ClaudeBot`, `Googlebot`
- `bot_category` — Bot kategori (boş = insan):
  - `ai` — Tüm AI/LLM crawler'ları (ChatGPT, Claude, Perplexity, Gemini, vs.)
  - `ads_google` — Google Ads landing-page kontrol botları
  - `ads_meta` — Meta/Facebook/Instagram botları
  - `ads_tiktok` — TikTok botları
  - `ads_bing` — Bing Ads botu
  - `search` — Arama motoru bot'ları (Googlebot, Bingbot, vs.)
  - `seo` — SEO araçları (Ahrefs, Semrush, vs.)
  - `social` — Sosyal medya link önizleme bot'ları (Twitter, LinkedIn, WhatsApp, vs.)
  - `monitor`, `archive`, `generic`
- `ad_platform` — Reklama tıklayıp gelen gerçek kullanıcı (URL'deki click-ID'den tespit, boş = reklamdan değil):
  - `google_ads`, `meta_ads` (Facebook/Instagram), `tiktok_ads`, `bing_ads`, `twitter_ads`, `linkedin_ads`, `pinterest_ads`, `reddit_ads`, `snapchat_ads`
- `referrer_host` — Hangi siteden geldiği, örn: `google.com`, `facebook.com`

ÖNEMLİ FARK:
- `bot_category=ads_meta` → Meta'nın **bot'u** (sayfa önizlemesi için gelir)
- `ad_platform=meta_ads` → Meta reklamına **tıklayan gerçek kullanıcı**

# Operatörler (case match için)

- `equals` — Tam eşitlik. Değer: tek bir şey (örn: `TR`)
- `in` — Listeden biri. Değer: virgülle ayrılmış (örn: `TR, AZ, KZ`)
- `not_in` — Listede yoksa. Değer: virgülle ayrılmış
- `contains` — İçeriyorsa
- `starts_with` — İle başlıyorsa
- `regex` — Regex
- `default` — Catch-all (hiçbiri tutmazsa). Değer gerekmez.

Cases sıralıdır: ilk eşleşen kazanır. `default` mutlaka son case olmalı.

# Konuşma kuralları

1. Önce "Ne yapmak istiyorsun?" diye sor, geniş cevap al
2. Detayları teker teker netleştir — her seferinde 1-3 soru, fazla sorma
3. URL'leri kullanıcıdan iste, sen uydurma. Eksikse sor: "Türk kullanıcıların hangi URL'e gitmesini istiyorsun?"
4. Kampanyaya bir ad ve slug öner (slug: küçük harf, tire, rakam)
5. Tree'yi gözünde canlandır: en başa en sık değişen veya en önemli filtreyi koy
6. Bot'ları engellemek istiyorsa öncelikle bot'ları yakalayan check en üstte olmalı
7. Default redirect URL kampanya seviyesinde olmalı — tree'de hiçbir şey match etmezse fallback
8. Hazır olduğunda kullanıcıya plan özetini Türkçe anlat ("Şöyle olacak: Türk mobil → A, Türk desktop → B, yabancılar → C") ve "Oluşturayım mı?" diye sor
9. Onay alınca JSON'u çıkar

# Çıktı formatı (kampanya oluşturma)

Kullanıcı onayladığında, mesajının SONUNDA `~~~campaign` fence'i içine geçerli JSON koy:

~~~campaign
{
  "name": "Kampanya adı (Türkçe olabilir)",
  "slug": "kampanya-slug",
  "default_redirect_url": "https://default-url.com",
  "tree": <node>
}
~~~

Node iki şekilden biri:

**Check node:**
```
{
  "type": "check",
  "variable": "country",
  "label": "Ülke kontrolü",
  "cases": [
    {
      "operator": "equals",
      "value": "TR",
      "label": "Türkiye'den",
      "child": <node>
    },
    {
      "operator": "default",
      "label": "Diğer ülkeler",
      "child": <node>
    }
  ]
}
```

**Redirect node:**
```
{
  "type": "redirect",
  "url": "https://hedef.com/sayfa",
  "delivery_mode": "redirect",
  "label": "Türkiye landing"
}
```

`delivery_mode`: `redirect` (normal 302, hızlı) ya da `proxy` (URL bar gizli kalır, sadece basit landing page'lerde çalışır).

# Önemli kurallar

- JSON dışında HİÇBİR yorum/markdown ekleme JSON'un içine
- Tree'yi mümkün olduğunca basit tut — gereksiz iç içe sokma
- URL'leri kullanıcıdan al, asla uydurma
- Slug oluştururken: küçük harf, sadece a-z 0-9 tire, max 30 karakter
- Türkçe karakter (ç ğ ı ö ş ü) slug'da olmasın — TR karakterleri ASCII'ye çevir
- Eğer kullanıcı "tüm AI botları" derse `bot_category equals ai` kullan, tek tek listeleme
- Eğer kullanıcı "Facebook reklamından gelenler" derse `ad_platform equals meta_ads` kullan (bot değil, gerçek kullanıcı!)
PROMPT;
    }

    /**
     * Extract the campaign JSON from an AI response that uses ~~~campaign fences.
     * Returns the decoded array or null if not present / invalid.
     */
    public static function extractCampaignJson(string $aiResponse): ?array
    {
        // Use # as regex delimiter since the fence itself uses ~ which would confuse parser
        if (!preg_match('#~~~campaign\s*\n(.*?)\n~~~#s', $aiResponse, $m)) {
            // Fallback: plain ```campaign or ```json blocks
            if (!preg_match('#```(?:campaign|json)?\s*\n(\{.*?\})\s*\n```#s', $aiResponse, $m)) {
                return null;
            }
        }
        $json = trim($m[1]);
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        if (empty($data['name']) || empty($data['slug']) || empty($data['tree'])) return null;
        return $data;
    }

    /**
     * Strip the ~~~campaign block from the AI response so the chat UI shows
     * only the conversational text, not the raw JSON.
     */
    public static function stripCampaignBlock(string $aiResponse): string
    {
        $cleaned = preg_replace('#~~~campaign\s*\n.*?\n~~~#s', '', $aiResponse);
        $cleaned = preg_replace('#```(?:campaign|json)?\s*\n\{.*?\}\s*\n```#s', '', (string) $cleaned);
        return trim((string) $cleaned);
    }

    /**
     * Create a campaign + tree from validated AI JSON.
     * Returns the new campaign ID.
     */
    public static function buildFromArray(Database $db, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        $defaultUrl = trim((string) ($data['default_redirect_url'] ?? ''));
        $tree = $data['tree'] ?? null;

        if ($name === '') throw new \InvalidArgumentException('Kampanya adı boş');
        if (!preg_match('~^[a-z0-9_-]{1,64}$~', $slug)) {
            throw new \InvalidArgumentException("Slug geçersiz: '$slug' (sadece küçük harf, rakam, tire, alt-tire)");
        }
        if (!is_array($tree)) throw new \InvalidArgumentException('Tree eksik');

        // Slug benzersiz mi?
        $clash = $db->one('SELECT id FROM campaigns WHERE slug = ?', [$slug]);
        if ($clash) {
            // Append -N suffix
            for ($i = 2; $i < 100; $i++) {
                $tryslug = $slug . '-' . $i;
                $clash = $db->one('SELECT id FROM campaigns WHERE slug = ?', [$tryslug]);
                if (!$clash) { $slug = $tryslug; break; }
            }
        }

        $db->pdo()->beginTransaction();
        try {
            $campaignId = $db->insert('campaigns', [
                'slug'                 => $slug,
                'name'                 => $name,
                'default_redirect_url' => $defaultUrl !== '' ? $defaultUrl : null,
                'active'               => 1,
            ]);

            $rootId = self::buildNode($db, $campaignId, $tree, 0);

            $db->update('campaigns', ['root_node_id' => $rootId], ['id' => $campaignId]);
            $db->pdo()->commit();
        } catch (\Throwable $e) {
            $db->pdo()->rollBack();
            throw $e;
        }

        return $campaignId;
    }

    private static function buildNode(Database $db, int $campaignId, array $node, int $depth): int
    {
        if ($depth > 30) {
            throw new \RuntimeException('Tree çok derin — döngü olabilir');
        }

        $type = ($node['type'] ?? 'redirect') === 'check' ? 'check' : 'redirect';

        if ($type === 'redirect') {
            $url = trim((string) ($node['url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Geçersiz URL: $url");
            }
            $delivery = ($node['delivery_mode'] ?? '') === 'proxy' ? 'proxy' : 'redirect';
            return $db->insert('tree_nodes', [
                'campaign_id'   => $campaignId,
                'node_type'     => 'redirect',
                'redirect_url'  => $url !== '' ? $url : null,
                'delivery_mode' => $delivery,
                'label'         => self::cleanLabel($node['label'] ?? null),
            ]);
        }

        // Check node
        $variable = (string) ($node['variable'] ?? '');
        if (!in_array($variable, TreeEvaluator::VARIABLES, true)) {
            throw new \InvalidArgumentException("Bilinmeyen değişken: $variable");
        }

        $nodeId = $db->insert('tree_nodes', [
            'campaign_id' => $campaignId,
            'node_type'   => 'check',
            'variable'    => $variable,
            'label'       => self::cleanLabel($node['label'] ?? null),
        ]);

        $cases = $node['cases'] ?? [];
        if (!is_array($cases) || count($cases) === 0) {
            throw new \InvalidArgumentException("Check node'unda case yok: $variable");
        }

        foreach ($cases as $i => $case) {
            $op = (string) ($case['operator'] ?? '');
            if (!in_array($op, TreeEvaluator::OPERATORS, true)) {
                throw new \InvalidArgumentException("Geçersiz operator: $op");
            }
            $childId = null;
            if (!empty($case['child']) && is_array($case['child'])) {
                $childId = self::buildNode($db, $campaignId, $case['child'], $depth + 1);
            }
            $value = $op === 'default' ? null : (string) ($case['value'] ?? '');
            $db->insert('tree_cases', [
                'parent_node_id' => $nodeId,
                'child_node_id'  => $childId,
                'match_operator' => $op,
                'match_value'    => $value,
                'label'          => self::cleanLabel($case['label'] ?? null),
                'sort_order'     => (int) $i,
            ]);
        }

        return $nodeId;
    }

    private static function cleanLabel($v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, 255);
    }
}
