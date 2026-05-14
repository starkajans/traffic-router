/**
 * Cloudflare Worker — sosyalmedyaservisleri.com reverse proxy
 *
 * Routing logic:
 *   /admin/*, /p/*, /go/*, /favicon.ico   → pass through to origin nginx
 *   /__api/*                              → proxy to api.socinify.com
 *   /checkout, /payment, /cart, ...       → 302 redirect to real socinify
 *   everything else:
 *     - bot, non-TR, or non-mobile        → fetch our own clean landing
 *     - TR + mobile + human               → proxy socinify.com transparently
 *
 * Deploy:
 *   1. Cloudflare dashboard → Workers & Pages → Create
 *   2. Quick Edit → paste this script → Save and Deploy
 *   3. Add route: sosyalmedyaservisleri.com/*  → this worker
 */

const SOCINIFY = 'https://socinify.com';
const API_BASE = 'https://api.socinify.com';
const OUR_DOMAIN = 'sosyalmedyaservisleri.com';

// Clean landing page path on our origin — shown to bots, non-TR, non-mobile
const CLEAN_LANDING = `https://${OUR_DOMAIN}/p/home`;

// Origin paths that should ALWAYS pass through to our nginx
const PASS_THROUGH = [
  '/admin',
  '/p/',
  '/go/',
  '/favicon.ico',
  '/robots.txt',
  '/sitemap.xml',
];

// Paths that mean "user is going to pay" — redirect to real socinify here
const CHECKOUT_PATHS = [
  '/checkout',
  '/payment',
  '/pay',
  '/odeme',
  '/cart',
  '/sepet',
  '/account',
  '/login',
  '/register',
  '/kayit',
  '/giris',
];

addEventListener('fetch', (event) => {
  event.respondWith(handle(event.request));
});

async function handle(request) {
  const url = new URL(request.url);
  const path = url.pathname;
  const lowerPath = path.toLowerCase();

  // 1) Pass through to our nginx (admin, landing pages, campaigns)
  if (PASS_THROUGH.some((p) => path.startsWith(p))) {
    return fetch(request);
  }

  // 2) socinify API calls (XHR/fetch from cloaked pages) → proxy to api.socinify.com
  if (path.startsWith('/__api/')) {
    return proxyApi(request, url);
  }

  // 3) Checkout / payment / login → redirect to real socinify (URL bar changes)
  if (CHECKOUT_PATHS.some((p) => lowerPath.startsWith(p))) {
    return Response.redirect(SOCINIFY + path + url.search, 302);
  }

  // 4) Smart routing: who's the visitor?
  const cfCountry = request.headers.get('cf-ipcountry') || '';
  const ua = request.headers.get('user-agent') || '';
  const isBot = detectBot(ua);
  const isMobile = /Mobi|Android.*Mobile|iPhone|iPod/i.test(ua) && !/iPad|Tablet/i.test(ua);
  const isTurkey = cfCountry === 'TR';

  // Show clean landing to: bots, non-TR, non-mobile
  if (isBot || !isTurkey || !isMobile) {
    return fetch(CLEAN_LANDING);
  }

  // TR + mobile + human → full socinify proxy
  return proxySocinify(request, url);
}

// ---------------------------------------------------------------------------

function detectBot(ua) {
  if (!ua) return true;
  return /bot|crawler|spider|scraper|curl|wget|python-requests|HeadlessChrome|PhantomJS|axios|node-fetch|libwww-perl|Go-http-client|Java\/|Apache-HttpClient|googlebot|bingbot|yandex|baidu|sogou|duckduckbot|applebot|petalbot|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|slackbot|discordbot|pinterestbot|gptbot|claudebot|perplexitybot|ccbot|bytespider|amazonbot|google-extended|meta-externalagent|adsbot|mediapartners-google|adidxbot|ahrefsbot|semrushbot|mj12bot|dotbot|rogerbot/i.test(ua);
}

// ---------------------------------------------------------------------------

async function proxySocinify(request, url) {
  const target = SOCINIFY + url.pathname + url.search;

  const proxyHeaders = new Headers(request.headers);
  proxyHeaders.set('Host', new URL(SOCINIFY).hostname);
  proxyHeaders.set('Accept-Encoding', 'identity'); // worker handles encoding
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

  // Upstream redirected? Rewrite Location to keep visitor on our domain
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
  // /__api/v1/foo → https://api.socinify.com/v1/foo
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

// ---------------------------------------------------------------------------

function rewriteAbsoluteUrls(s) {
  return s
    .replace(/https?:\/\/api\.socinify\.com/gi, `https://${OUR_DOMAIN}/__api`)
    .replace(/https?:\/\/(www\.)?socinify\.com/gi, `https://${OUR_DOMAIN}`);
}

async function rewriteResponse(response) {
  const contentType = (response.headers.get('content-type') || '').toLowerCase();
  const newHeaders = new Headers(response.headers);

  // Rewrite Set-Cookie: change Domain attribute so cookies stick to our domain
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

  // Headers that would break the cloak — strip
  newHeaders.delete('content-security-policy');
  newHeaders.delete('content-security-policy-report-only');
  newHeaders.delete('x-frame-options');
  newHeaders.delete('strict-transport-security');
  newHeaders.delete('content-encoding'); // we asked for identity
  newHeaders.delete('content-length');   // body may change

  // Don't let upstream tell browsers/CDNs to cache aggressively
  newHeaders.set('cache-control', 'no-store, no-cache, must-revalidate');
  newHeaders.set('x-robots-tag', 'noindex, nofollow');
  newHeaders.set('referrer-policy', 'no-referrer');

  // HTML — rewrite URLs + strip canonical/og tags
  if (contentType.includes('text/html')) {
    let html = await response.text();
    html = rewriteAbsoluteUrls(html);
    html = stripLeakyTags(html);
    return new Response(html, { status: response.status, headers: newHeaders });
  }

  // CSS — rewrite url() references
  if (contentType.includes('text/css')) {
    let css = await response.text();
    css = rewriteAbsoluteUrls(css);
    return new Response(css, { status: response.status, headers: newHeaders });
  }

  // JS or JSON — rewrite hardcoded URLs
  if (contentType.includes('javascript') || contentType.includes('application/json')) {
    let body = await response.text();
    body = rewriteAbsoluteUrls(body);
    return new Response(body, { status: response.status, headers: newHeaders });
  }

  // Binary (images, fonts, etc.) — stream through unchanged
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
