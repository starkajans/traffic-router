<?php
declare(strict_types=1);

namespace Trafic;

final class UserAgent
{
    /**
     * Known bots — name => regex applied to UA.
     * Ordered roughly by specificity (AI crawlers first so they don't get captured by generic patterns).
     */
    private const BOTS = [
        // === AI / LLM crawlers ===
        'GPTBot'              => '~GPTBot~i',
        'OAI-SearchBot'       => '~OAI-SearchBot~i',
        'ChatGPT-User'        => '~ChatGPT-User~i',
        'ClaudeBot'           => '~ClaudeBot~i',
        'Claude-Web'          => '~Claude-Web~i',
        'anthropic-ai'        => '~anthropic-ai~i',
        'PerplexityBot'       => '~PerplexityBot~i',
        'Perplexity-User'     => '~Perplexity-User~i',
        'Google-Extended'     => '~Google-Extended~i',
        'CCBot'               => '~CCBot~i',
        'Bytespider'          => '~Bytespider~i',
        'Amazonbot'           => '~Amazonbot~i',
        'Applebot-Extended'   => '~Applebot-Extended~i',
        'Meta-ExternalAgent'  => '~Meta-ExternalAgent~i',
        'Meta-ExternalFetcher'=> '~Meta-ExternalFetcher~i',
        'Diffbot'             => '~Diffbot~i',
        'cohere-ai'           => '~cohere-ai~i',
        'YouBot'              => '~YouBot~i',
        'PhindBot'            => '~PhindBot~i',
        'MistralAI-User'      => '~MistralAI-User~i',
        'DeepSeekBot'         => '~DeepSeekBot~i',
        'Kagibot'             => '~Kagibot~i',
        'Timpibot'            => '~Timpibot~i',

        // === Google ads / webmaster / specialty bots ===
        'AdsBot-Google-Mobile' => '~AdsBot-Google-Mobile(?:-Apps)?~i',
        'AdsBot-Google'        => '~AdsBot-Google~i',
        'Mediapartners-Google' => '~Mediapartners-Google~i',
        'APIs-Google'          => '~APIs-Google~i',
        'FeedFetcher-Google'   => '~FeedFetcher-Google~i',
        'Google-Read-Aloud'    => '~Google-Read-Aloud~i',
        'Storebot-Google'      => '~Storebot-Google~i',
        'DuplexWeb-Google'     => '~DuplexWeb-Google~i',
        'Google-Site-Verification' => '~Google-Site-Verification~i',
        'GoogleOther'          => '~GoogleOther~i',
        'Googlebot-Image'      => '~Googlebot-Image~i',
        'Googlebot-Video'      => '~Googlebot-Video~i',
        'Googlebot-News'       => '~Googlebot-News~i',
        // Generic Googlebot (must come AFTER the more specific Googlebot-X patterns above)
        'Googlebot'            => '~Googlebot~i',

        // === Bing / Microsoft ===
        'BingPreview'          => '~BingPreview~i',
        'adidxbot'             => '~adidxbot~i',          // Bing Ads landing-page checker
        'MicrosoftPreview'     => '~MicrosoftPreview~i',
        'Bingbot'              => '~bingbot~i',

        // === Meta / Facebook (ads + sharing) ===
        'FacebookBot'          => '~FacebookBot~i',       // Generic Facebook crawler
        'Facebot'              => '~Facebot~i',           // Older Facebook bot

        // === TikTok / ByteDance (ads side) ===
        'TikTokBot'            => '~TikTokBot~i',

        // === Yandex (split into sub-bots) ===
        'YandexImages'         => '~YandexImages~i',
        'YandexMetrika'        => '~YandexMetrika~i',
        'YandexWebmaster'      => '~YandexWebmaster~i',
        'YandexBot'            => '~Yandex(?:Bot)?~i',

        // === Other search engines ===
        'DuckDuckBot'          => '~DuckDuckBot~i',
        'Baiduspider'          => '~Baiduspider~i',
        'Sogou'                => '~Sogou~i',
        'Applebot'             => '~Applebot~i',
        'PetalBot'             => '~PetalBot~i',          // Huawei
        'Naverbot'             => '~Naverbot~i',
        'SeznamBot'            => '~SeznamBot~i',         // Czech

        // === Social previews ===
        'facebookexternalhit'  => '~facebookexternalhit~i',  // categorised under ads_meta (used for ad + share previews)
        'TikTokSpider'         => '~TikTokSpider~i',         // categorised under ads_tiktok
        'Twitterbot'           => '~Twitterbot~i',
        'LinkedInBot'          => '~LinkedInBot~i',
        'WhatsApp'             => '~WhatsApp~i',
        'TelegramBot'          => '~TelegramBot~i',
        'Slackbot'             => '~Slackbot~i',
        'DiscordBot'           => '~Discordbot~i',
        'Pinterestbot'         => '~Pinterest(?:bot)?~i',
        'Snapchat'             => '~Snapchat~i',

        // === SEO / monitoring / link-analysis ===
        'AhrefsBot'            => '~AhrefsBot~i',
        'AhrefsSiteAudit'      => '~AhrefsSiteAudit~i',
        'SemrushBot'           => '~SemrushBot~i',
        'MJ12bot'              => '~MJ12bot~i',
        'DotBot'               => '~DotBot~i',
        'rogerbot'             => '~rogerbot~i',          // Moz
        'BLEXBot'              => '~BLEXBot~i',
        'DataForSeoBot'        => '~DataForSeoBot~i',
        'Sogou web spider'     => '~Sogou web spider~i',
        'SiteAuditBot'         => '~SiteAuditBot~i',
        'Screaming Frog SEO'   => '~Screaming Frog SEO Spider~i',
        'serpstatbot'          => '~serpstatbot~i',
        'BacklinksBot'         => '~Backlinks bot~i',
        'UptimeRobot'          => '~UptimeRobot~i',
        'StatusCake'           => '~StatusCake~i',
        'Pingdom'              => '~Pingdom~i',

        // === Archivers / research ===
        'ia_archiver'          => '~ia_archiver~i',       // Alexa / Wayback
        'archive.org_bot'      => '~archive\.org_bot~i',

        // === Catch-all generic bot detection — must be LAST ===
        'Generic'              => '~(bot|crawler|spider|scraper|curl/|wget/|python-requests|HeadlessChrome|PhantomJS|Go-http-client|axios/|node-fetch|libwww-perl|HTTP_Request|Scrapy|Java/\d|Apache-HttpClient)~i',
    ];

    /**
     * Maps bot name (from BOTS keys above) → category.
     * Categories:
     *   ai         — LLM crawlers (training + answer)
     *   ads_google — Google Ads + AdSense landing-page checkers
     *   ads_bing   — Bing Ads landing-page checker
     *   ads_meta   — Meta / Facebook / Instagram crawlers (ad previews + sharing)
     *   ads_tiktok — TikTok crawlers
     *   search     — search engine crawlers + specialty service bots
     *   seo        — SEO / link analysis tools
     *   social     — other social-platform link preview bots
     *   monitor    — uptime monitoring
     *   archive    — archivers
     *   generic    — caught by the catch-all regex
     */
    private const BOT_CATEGORIES = [
        // AI / LLM
        'GPTBot' => 'ai', 'OAI-SearchBot' => 'ai', 'ChatGPT-User' => 'ai',
        'ClaudeBot' => 'ai', 'Claude-Web' => 'ai', 'anthropic-ai' => 'ai',
        'PerplexityBot' => 'ai', 'Perplexity-User' => 'ai',
        'Google-Extended' => 'ai',
        'CCBot' => 'ai', 'Bytespider' => 'ai', 'Amazonbot' => 'ai',
        'Applebot-Extended' => 'ai',
        'Meta-ExternalAgent' => 'ai', 'Meta-ExternalFetcher' => 'ai',
        'Diffbot' => 'ai', 'cohere-ai' => 'ai',
        'YouBot' => 'ai', 'PhindBot' => 'ai',
        'MistralAI-User' => 'ai', 'DeepSeekBot' => 'ai',
        'Kagibot' => 'ai', 'Timpibot' => 'ai',

        // Google Ads (Google Ads landing-page checks + AdSense)
        'AdsBot-Google'        => 'ads_google',
        'AdsBot-Google-Mobile' => 'ads_google',
        'Mediapartners-Google' => 'ads_google',

        // Bing Ads
        'adidxbot' => 'ads_bing',

        // Meta / Facebook / Instagram (ad previews + share previews — Meta uses same UA for both)
        'facebookexternalhit' => 'ads_meta',
        'FacebookBot'         => 'ads_meta',
        'Facebot'             => 'ads_meta',

        // TikTok
        'TikTokSpider' => 'ads_tiktok',
        'TikTokBot'    => 'ads_tiktok',

        // Search engines + their specialty crawlers
        'Googlebot' => 'search', 'Googlebot-Image' => 'search',
        'Googlebot-Video' => 'search', 'Googlebot-News' => 'search',
        'APIs-Google' => 'search', 'FeedFetcher-Google' => 'search',
        'Google-Read-Aloud' => 'search', 'Storebot-Google' => 'search',
        'DuplexWeb-Google' => 'search', 'Google-Site-Verification' => 'search',
        'GoogleOther' => 'search',
        'Bingbot' => 'search', 'BingPreview' => 'search', 'MicrosoftPreview' => 'search',
        'YandexBot' => 'search', 'YandexImages' => 'search',
        'YandexMetrika' => 'search', 'YandexWebmaster' => 'search',
        'DuckDuckBot' => 'search', 'Baiduspider' => 'search',
        'Sogou' => 'search', 'Sogou web spider' => 'search',
        'Applebot' => 'search', 'PetalBot' => 'search',
        'Naverbot' => 'search', 'SeznamBot' => 'search',

        // SEO / link analysis tools
        'AhrefsBot' => 'seo', 'AhrefsSiteAudit' => 'seo',
        'SemrushBot' => 'seo', 'MJ12bot' => 'seo',
        'DotBot' => 'seo', 'rogerbot' => 'seo',
        'BLEXBot' => 'seo', 'DataForSeoBot' => 'seo',
        'SiteAuditBot' => 'seo', 'Screaming Frog SEO' => 'seo',
        'serpstatbot' => 'seo', 'BacklinksBot' => 'seo',

        // Other social-platform link previews
        'Twitterbot' => 'social', 'LinkedInBot' => 'social',
        'WhatsApp' => 'social', 'TelegramBot' => 'social',
        'Slackbot' => 'social', 'DiscordBot' => 'social',
        'Pinterestbot' => 'social', 'Snapchat' => 'social',

        // Monitoring
        'UptimeRobot' => 'monitor', 'StatusCake' => 'monitor', 'Pingdom' => 'monitor',

        // Archive
        'ia_archiver' => 'archive', 'archive.org_bot' => 'archive',

        // Generic catch-all
        'Generic' => 'generic',
    ];

    /**
     * URL query parameters that signal an ad click. Lowercase keys.
     * Detecting one of these in the request URL means the visitor came from a paid ad
     * on that platform (the platforms add these click-tracking IDs automatically).
     */
    private const AD_CLICK_PARAMS = [
        'gclid'     => 'google_ads',   // Google Ads
        'gbraid'    => 'google_ads',   // Google Ads (iOS app conversion linker)
        'wbraid'    => 'google_ads',   // Google Ads (web→app linker)
        'dclid'     => 'google_ads',   // Display & Video 360 / Campaign Manager
        'fbclid'    => 'meta_ads',     // Facebook / Instagram
        'ttclid'    => 'tiktok_ads',   // TikTok Ads
        'msclkid'   => 'bing_ads',     // Microsoft / Bing Ads
        'twclid'    => 'twitter_ads',  // X / Twitter Ads
        'li_fat_id' => 'linkedin_ads', // LinkedIn Ads
        'epik'      => 'pinterest_ads',// Pinterest Ads
        'rdt_cid'   => 'reddit_ads',   // Reddit Ads
        'sccid'     => 'snapchat_ads', // Snapchat Ads
        'irclickid' => 'impact_ads',   // Impact (affiliate)
        'yclid'     => 'yandex_ads',   // Yandex.Direct
    ];

    /**
     * Detect which ad platform the visitor clicked from, based on URL click-ID params.
     * Returns the platform key (e.g. 'meta_ads') or null if no click ID present.
     */
    public static function adPlatform(): ?string
    {
        foreach ($_GET as $key => $value) {
            if ($value === '' || $value === null) continue;
            $lower = strtolower((string) $key);
            if (isset(self::AD_CLICK_PARAMS[$lower])) {
                return self::AD_CLICK_PARAMS[$lower];
            }
        }
        return null;
    }

    public static function categoryOf(?string $botName): ?string
    {
        if ($botName === null || $botName === '') return null;
        return self::BOT_CATEGORIES[$botName] ?? 'generic';
    }

    /**
     * @return array{
     *   device: string, os: string, browser: string,
     *   is_bot: bool, bot_name: ?string, bot_category: ?string,
     *   ad_platform: ?string,
     *   language: ?string, referrer: ?string, referrer_host: ?string,
     *   user_agent: string
     * }
     */
    public static function parse(): array
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $bot = self::detectBot($ua);
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $referrerHost = null;
        if ($referrer) {
            $host = parse_url($referrer, PHP_URL_HOST);
            $referrerHost = $host ? strtolower($host) : null;
        }
        return [
            'device'        => self::detectDevice($ua),
            'os'            => self::detectOS($ua),
            'browser'       => self::detectBrowser($ua),
            'is_bot'        => $bot !== null,
            'bot_name'      => $bot,
            'bot_category'  => self::categoryOf($bot),
            'ad_platform'   => self::adPlatform(),
            'language'      => self::primaryLanguage(),
            'referrer'      => $referrer ? mb_substr($referrer, 0, 1000) : null,
            'referrer_host' => $referrerHost,
            'user_agent'    => mb_substr($ua, 0, 500),
        ];
    }

    public static function detectBot(string $ua): ?string
    {
        if ($ua === '') {
            return 'Unknown'; // No UA → treat as bot
        }
        foreach (self::BOTS as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }
        return null;
    }

    public static function detectDevice(string $ua): string
    {
        if ($ua === '') return 'unknown';
        if (preg_match('~iPad|Tablet|Kindle|PlayBook|Silk~i', $ua)) return 'tablet';
        if (preg_match('~Mobi|Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini~i', $ua)) return 'mobile';
        if (preg_match('~Android~i', $ua)) return 'tablet'; // Android without "Mobile" usually = tablet
        return 'desktop';
    }

    public static function detectOS(string $ua): string
    {
        if (preg_match('~Windows NT 10~i', $ua)) return 'Windows 10/11';
        if (preg_match('~Windows NT 6\.3~i', $ua)) return 'Windows 8.1';
        if (preg_match('~Windows NT 6\.[12]~i', $ua)) return 'Windows 7/8';
        if (preg_match('~Windows~i', $ua)) return 'Windows';
        if (preg_match('~iPhone|iPad|iPod~i', $ua)) return 'iOS';
        if (preg_match('~Mac OS X|Macintosh~i', $ua)) return 'macOS';
        if (preg_match('~Android~i', $ua)) return 'Android';
        if (preg_match('~CrOS~i', $ua)) return 'ChromeOS';
        if (preg_match('~Linux~i', $ua)) return 'Linux';
        return 'Other';
    }

    public static function detectBrowser(string $ua): string
    {
        if (preg_match('~Edg/~i', $ua)) return 'Edge';
        if (preg_match('~OPR/|Opera~i', $ua)) return 'Opera';
        if (preg_match('~SamsungBrowser~i', $ua)) return 'Samsung';
        if (preg_match('~UCBrowser~i', $ua)) return 'UC';
        if (preg_match('~Firefox/~i', $ua)) return 'Firefox';
        if (preg_match('~Chrome/~i', $ua) && !preg_match('~Edg/|OPR/|Chromium~i', $ua)) return 'Chrome';
        if (preg_match('~Chromium~i', $ua)) return 'Chromium';
        if (preg_match('~Safari/~i', $ua)) return 'Safari';
        if (preg_match('~MSIE|Trident~i', $ua)) return 'IE';
        return 'Other';
    }

    /** Two-letter primary language from Accept-Language */
    public static function primaryLanguage(): ?string
    {
        $h = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($h === '') return null;
        if (preg_match('~^([a-zA-Z]{2,3})~', $h, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }
}
