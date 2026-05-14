/**
 * Cloudflare Worker — sosyalmedyaservisleri.com reverse proxy
 *
 * Routing decision flow (highest priority first):
 *   1. Path is /admin /p/ /go/ etc.     → pass to nginx
 *   2. Path is /__api/*                 → proxy api.socinify.com
 *   3. Path is /checkout /cart etc.     → 302 redirect to real socinify
 *   4. Visitor is a bot                 → clean landing (/p/home)
 *   5. Country != TR                    → clean landing
 *   6. Device != mobile                 → clean landing
 *   7. Language != tr (Accept-Language) → clean landing
 *   8. REQUIRE_AD_CLICK && no click-ID  → clean landing
 *   else                                → proxy socinify (cloak)
 *
 * Every response gets `x-trafic-debug` header so you can inspect decisions
 * with `curl -I https://sosyalmedyaservisleri.com/`
 *
 * Deploy:
 *   Cloudflare dashboard → Workers & Pages → trafic-socinify-proxy → Edit code
 *   Paste this file, Save and Deploy.
 */

const SOCINIFY = 'https://socinify.com';
const API_BASE = 'https://api.socinify.com';
const OUR_DOMAIN = 'sosyalmedyaservisleri.com';
const CLEAN_LANDING = `https://${OUR_DOMAIN}/p/home`;

// Set to true to require a known ad click-ID parameter (ttclid / gclid / fbclid / msclkid)
// before showing socinify. Reviewers / organic visitors without click-ID see clean landing.
// Cookie 'tc_ad' set on first ad click sticks visitor to proxy mode for session.
const REQUIRE_AD_CLICK = false;

const PASS_THROUGH = [
  '/admin',
  '/p/',
  '/go/',
  '/favicon.ico',
  '/robots.txt',
  '/sitemap.xml',
];

const CHECKOUT_PATHS = [
  '/checkout', '/payment', '/pay', '/odeme',
  '/cart', '/sepet', '/account', '/login',
  '/register', '/kayit', '/giris',
];

const AD_CLICK_PARAMS = ['ttclid', 'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'twclid', 'li_fat_id', 'epik', 'rdt_cid'];

// ----------------------------------------------------------------------------

addEventListener('fetch', (event) => {
  event.respondWith(handle(event.request));
});

async function handle(request) {
  const url = new URL(request.url);
  const path = url.pathname;
  const lowerPath = path.toLowerCase();

  // 1) Pass through to origin nginx
  if (PASS_THROUGH.some((p) => path.startsWith(p))) {
    return fetch(request);
  }

  // 2) /__api → api.socinify.com proxy
  if (path.startsWith('/__api/') || path === '/__api') {
    return proxyApi(request, url);
  }

  // 3) Checkout → 302 to real socinify
  if (CHECKOUT_PATHS.some((p) => lowerPath.startsWith(p))) {
    return Response.redirect(SOCINIFY + path + url.search, 302);
  }

  // 4) Decision
  const decision = decideAction(request, url);
  let response;
  if (decision.action === 'clean') {
    response = await fetch(CLEAN_LANDING);
  } else {
    response = await proxySocinify(request, url);
  }

  // Tag with debug header for inspection via `curl -I`
  const newHeaders = new Headers(response.headers);
  newHeaders.set('x-trafic-debug', decision.debug);

  // If proxy-mode entered with a click-ID, set cookie so subsequent in-session
  // requests stay on proxy even after the click-ID drops out of the URL.
  if (decision.setAdCookie) {
    newHeaders.append('set-cookie', `tc_ad=1; Path=/; Max-Age=86400; SameSite=Lax; Secure`);
  }

  return new Response(response.body, { status: response.status, headers: newHeaders });
}

// ----------------------------------------------------------------------------

function decideAction(request, url) {
  const cfCountry = request.headers.get('cf-ipcountry') || '';
  const acceptLang = (request.headers.get('accept-language') || '').toLowerCase();
  const ua = request.headers.get('user-agent') || '';
  const cookieHeader = request.headers.get('cookie') || '';

  const isBot = detectBot(ua);
  const isMobile = /Mobi|Android.*Mobile|iPhone|iPod/i.test(ua) && !/iPad|Tablet/i.test(ua);
  const isTurkey = cfCountry === 'TR';
  const isTrLang = acceptLang.startsWith('tr');

  // Did this visitor come via an ad? Check URL params + cookie
  const hasAdClick = AD_CLICK_PARAMS.some((p) => url.searchParams.has(p));
  const hasAdCookie = /(^|;\s*)tc_ad=1/.test(cookieHeader);
  const cameFromAd = hasAdClick || hasAdCookie;

  let action = 'proxy';
  let reason = '';
  let setAdCookie = false;

  if (isBot) {
    action = 'clean'; reason = 'bot';
  } else if (!isTurkey) {
    action = 'clean'; reason = `country=${cfCountry || 'unknown'}`;
  } else if (!isMobile) {
    action = 'clean'; reason = 'not-mobile';
  } else if (!isTrLang) {
    action = 'clean'; reason = `lang=${acceptLang.substring(0, 5)}`;
  } else if (REQUIRE_AD_CLICK && !cameFromAd) {
    action = 'clean'; reason = 'no-ad-click';
  } else {
    action = 'proxy'; reason = 'tr+mobile+tr-lang';
    if (hasAdClick) setAdCookie = true; // remember for session
  }

  const debug = `country=${cfCountry || '?'} lang=${acceptLang.substring(0, 5) || '?'} mobile=${isMobile} bot=${isBot} adClick=${hasAdClick} adCookie=${hasAdCookie} → ${action} (${reason})`;
  return { action, debug, setAdCookie };
}

function detectBot(ua) {
  if (!ua) return true;
  return /bot|crawler|spider|scraper|curl|wget|python-requests|HeadlessChrome|PhantomJS|axios|node-fetch|libwww-perl|Go-http-client|Java\/|Apache-HttpClient|googlebot|bingbot|yandex|baidu|sogou|duckduckbot|applebot|petalbot|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|slackbot|discordbot|pinterestbot|gptbot|claudebot|perplexitybot|ccbot|bytespider|amazonbot|google-extended|meta-externalagent|adsbot|mediapartners-google|adidxbot|ahrefsbot|semrushbot|mj12bot|dotbot|rogerbot|tiktokspider/i.test(ua);
}

// ----------------------------------------------------------------------------

async function proxySocinify(request, url) {
  const target = SOCINIFY + url.pathname + url.search;

  const proxyHeaders = new Headers(request.headers);
  proxyHeaders.set('Host', new URL(SOCINIFY).hostname);
  proxyHeaders.set('Accept-Encoding', 'identity');
  proxyHeaders.set('Referer', SOCINIFY + url.pathname);
  proxyHeaders.delete('cf-connecting-ip');
  proxyHeaders.delete('cf-ipcountry');
  proxyHeaders.delete('cf-ray');
  proxyHeaders.delete('cf-visitor');
  proxyHeaders.delete('cf-worker');

  let response;
  try {
    response = await fetch(target, {
      method: request.method,
      headers: proxyHeaders,
      body: ['GET', 'HEAD'].includes(request.method) ? undefined : request.body,
      redirect: 'manual',
    });
  } catch (e) {
    return new Response('Upstream error: ' + e.message, { status: 502 });
  }

  // Pass redirects through, rewriting Location
  if (response.status >= 300 && response.status < 400) {
    const loc = response.headers.get('location');
    if (loc) {
      const newHeaders = new Headers(response.headers);
      newHeaders.set('location', rewriteAbsoluteUrls(loc));
      return new Response(response.body, { status: response.status, headers: newHeaders });
    }
  }

  return rewriteResponse(response);
}

async function proxyApi(request, url) {
  const targetPath = url.pathname.replace(/^\/__api/, '');
  const target = API_BASE + targetPath + url.search;

  const proxyHeaders = new Headers(request.headers);
  proxyHeaders.set('Host', new URL(API_BASE).hostname);
  proxyHeaders.set('Origin', SOCINIFY);
  proxyHeaders.set('Referer', SOCINIFY + '/');
  proxyHeaders.delete('cf-connecting-ip');
  proxyHeaders.delete('cf-ipcountry');
  proxyHeaders.delete('cf-ray');
  proxyHeaders.delete('cf-visitor');
  proxyHeaders.delete('cf-worker');

  let response;
  try {
    response = await fetch(target, {
      method: request.method,
      headers: proxyHeaders,
      body: ['GET', 'HEAD'].includes(request.method) ? undefined : request.body,
      redirect: 'manual',
    });
  } catch (e) {
    return new Response('API error: ' + e.message, { status: 502 });
  }

  return rewriteResponse(response);
}

// ----------------------------------------------------------------------------

function rewriteAbsoluteUrls(s) {
  return s
    .replace(/https?:\/\/api\.socinify\.com/gi, `https://${OUR_DOMAIN}/__api`)
    .replace(/https?:\/\/(www\.)?socinify\.com/gi, `https://${OUR_DOMAIN}`);
}

async function rewriteResponse(response) {
  const contentType = (response.headers.get('content-type') || '').toLowerCase();
  const newHeaders = new Headers(response.headers);

  const setCookies = [];
  for (const [name, value] of response.headers) {
    if (name.toLowerCase() === 'set-cookie') setCookies.push(value);
  }
  if (setCookies.length) {
    newHeaders.delete('set-cookie');
    setCookies.forEach((c) => {
      const rewritten = c
        .replace(/;\s*Domain=[^;]+/i, `; Domain=${OUR_DOMAIN}`)
        .replace(/;\s*SameSite=None\b/i, '; SameSite=Lax');
      newHeaders.append('set-cookie', rewritten);
    });
  }

  newHeaders.delete('content-security-policy');
  newHeaders.delete('content-security-policy-report-only');
  newHeaders.delete('x-frame-options');
  newHeaders.delete('strict-transport-security');
  newHeaders.delete('content-encoding');
  newHeaders.delete('content-length');

  newHeaders.set('cache-control', 'no-store, no-cache, must-revalidate');
  newHeaders.set('x-robots-tag', 'noindex, nofollow');
  newHeaders.set('referrer-policy', 'no-referrer');

  if (contentType.includes('text/html')) {
    let html = await response.text();
    html = rewriteAbsoluteUrls(html);
    html = stripLeakyTags(html);
    return new Response(html, { status: response.status, headers: newHeaders });
  }
  if (contentType.includes('text/css')) {
    let css = await response.text();
    css = rewriteAbsoluteUrls(css);
    return new Response(css, { status: response.status, headers: newHeaders });
  }
  if (contentType.includes('javascript') || contentType.includes('application/json')) {
    let body = await response.text();
    body = rewriteAbsoluteUrls(body);
    return new Response(body, { status: response.status, headers: newHeaders });
  }
  return new Response(response.body, { status: response.status, headers: newHeaders });
}

function stripLeakyTags(html) {
  return html
    .replace(/<link[^>]+rel\s*=\s*["']canonical["'][^>]*>/gi, '')
    .replace(/<link[^>]+rel\s*=\s*["']alternate["'][^>]*>/gi, '')
    .replace(/<meta[^>]+property\s*=\s*["']og:url["'][^>]*>/gi, '')
    .replace(/<meta[^>]+name\s*=\s*["']twitter:url["'][^>]*>/gi, '')
    .replace(/<base[^>]*>/gi, '')
    .replace(/<meta[^>]+http-equiv\s*=\s*["']refresh["'][^>]*>/gi, '');
}
