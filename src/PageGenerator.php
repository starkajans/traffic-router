<?php
declare(strict_types=1);

namespace Trafic;

/**
 * Uses Gemini to generate a complete one-page HTML landing page from a Turkish
 * description. Output is a self-contained HTML document with inline CSS, no
 * external JS deps. Designed to look like a real, legitimate page (bots that
 * inspect content should not flag it as obviously fake).
 */
final class PageGenerator
{
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
Sen profesyonel bir web designer + frontend developer'sın. Kullanıcının verdiği açıklamaya göre **tek sayfalık** mobile-responsive bir landing page HTML'i üretiyorsun.

# Kesin kurallar

1. **Tam bir HTML dokümanı çıkar** — `<!doctype html>` ile başlasın, `</html>` ile bitsin
2. **HTML dışında HİÇBİR şey yazma** — markdown wrap (`html ...`) yok, açıklama yok, sadece HTML
3. **Tüm CSS inline `<style>` tag'inde olsun** — external stylesheet kullanma
4. **Tüm JavaScript inline `<script>` tag'inde olsun** — external script yok (gerekirse)
5. **Görseller için** Unsplash veya picsum.photos'tan placeholder kullan, asla broken image bırakma:
   - `https://picsum.photos/seed/{anahtar}/800/600` formatında deterministic seed kullan
   - Avatar için: `https://i.pravatar.cc/150?u={isim}`
6. **Font:** sadece sistem fontları (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`) veya Google Fonts (CSS @import ile)
7. **Mobile-first responsive** — viewport meta zorunlu, media queries kullan (`@media (max-width: 768px)`)

# Görsel kalite

- Modern, temiz tasarım — bol whitespace, net hiyerarşi
- Açıklamaya uygun renkler (kullanıcı renk söylüyorsa onu kullan)
- Hero section + birkaç içerik bölümü + footer yapısı tipik
- CTA butonları, ikonlar (SVG inline) kullan
- Hover effect'leri, smooth transitions
- Box shadow, border radius gibi modern detaylar

# SEO + meta tag'leri (zorunlu)

```html
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>...</title>
  <meta name="description" content="...">
  <meta property="og:title" content="...">
  <meta property="og:description" content="...">
  <meta property="og:type" content="website">
  <meta name="twitter:card" content="summary_large_image">
</head>
```

# Botlar için "gerçek" görünüm

Bot'ların (Google, Facebook, AI crawler'lar) sayfayı meşru sanması için:

- ✅ **Gerçek, anlamlı içerik** yaz — Lorem ipsum değil, ürün/hizmetle alakalı Türkçe metin
- ✅ **Birden fazla bölüm** (en az 3-4 section: hero, features, testimonials/about, footer)
- ✅ **Tutarlı marka kimliği** — bir isim, logo (text tabanlı), tutarlı renk paleti
- ✅ **Yasal-görünür footer** — iletişim, copyright, "Tüm hakları saklıdır"
- ✅ **Schema.org JSON-LD** ekle (Organization veya Product)
- ❌ Spam keyword'ler kullanma, "click here" tekrarı yapma
- ❌ Auto-redirect script ekleme (meta refresh yok, window.location yok)
- ❌ Cloaking yapma, hidden text yok

# Çıktı

SADECE HTML. Açıklama, yorum, markdown fence YOK. İlk karakter `<` olmalı. Son karakter `>` olmalı.
PROMPT;
    }

    /**
     * Generate a landing page from a description.
     * Returns ['html' => ..., 'title' => ...].
     */
    public static function generate(Gemini $gemini, string $description): array
    {
        $userPrompt = trim($description);
        if ($userPrompt === '') {
            throw new \InvalidArgumentException('Açıklama boş olamaz');
        }

        $rawHtml = $gemini->chat(
            [['role' => 'user', 'content' => $userPrompt]],
            self::systemPrompt(),
            0.8
        );

        $html = self::cleanHtml($rawHtml);
        if (stripos($html, '<html') === false || stripos($html, '</html>') === false) {
            throw new \RuntimeException('Gemini geçerli bir HTML dokümanı üretmedi. Açıklamayı netleştirip tekrar dene.');
        }

        return [
            'html'  => $html,
            'title' => self::extractTitle($html) ?: mb_substr($userPrompt, 0, 80),
        ];
    }

    /**
     * Strip markdown fences and leading/trailing junk Gemini sometimes adds.
     */
    public static function cleanHtml(string $raw): string
    {
        $raw = trim($raw);
        // Strip ```html ... ``` or ``` ... ```
        if (preg_match('#^```(?:html)?\s*\n(.*?)\n```\s*$#s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        // Strip leading text before <!doctype or <html
        if (preg_match('#<!doctype[^>]*>.*</html>#is', $raw, $m)) {
            return trim($m[0]);
        }
        if (preg_match('#<html[\s>].*</html>#is', $raw, $m)) {
            return "<!doctype html>\n" . trim($m[0]);
        }
        return $raw;
    }

    public static function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(strip_tags($m[1]));
        }
        return null;
    }

    /**
     * Generate a quick edit of an existing page from a short instruction.
     * Sends the current HTML + instruction to Gemini and returns new HTML.
     */
    public static function edit(Gemini $gemini, string $currentHtml, string $editInstruction): array
    {
        $msg = "Aşağıdaki mevcut sayfayı şu şekilde değiştir:\n\n"
             . trim($editInstruction)
             . "\n\n--- MEVCUT HTML ---\n"
             . $currentHtml;

        $rawHtml = $gemini->chat(
            [['role' => 'user', 'content' => $msg]],
            self::systemPrompt(),
            0.7
        );

        $html = self::cleanHtml($rawHtml);
        if (stripos($html, '<html') === false) {
            throw new \RuntimeException('Gemini geçerli HTML üretmedi');
        }

        return [
            'html'  => $html,
            'title' => self::extractTitle($html),
        ];
    }
}
