/**
 * Cloudflare Worker — sosyalmedyaservisleri.com
 *
 * Worker is a thin executor. Routing decisions come from the PHP backend
 * at /api-route.php, which runs the campaign decision tree (configured via
 * the admin panel). Worker only:
 *
 *   1. Collects visitor context (CF country, language, UA, ad click-IDs)
 *   2. Asks PHP "what should this visitor get?"
 *   3. Executes the answer (proxy, redirect, serve, passthrough, 404)
 *
 * For asset requests (.css/.js/.png/etc) the worker uses a per-session
 * cookie to skip the PHP roundtrip — once the visitor is locked into
 * "proxy socinify" mode, the same cookie tells the worker to keep proxying.
 *
 * Routing matrix (handled BEFORE consulting PHP):
 *   /admin/* /p/* /api-route* /__api* /favicon.ico → pass through to nginx
 *
 * Deploy: Cloudflare dashboard → Workers → Edit → paste → Save and Deploy.
 */

const SELF_HOST = 'sosyalmedyaservisleri.com';
const ROUTE_API = `https://${SELF_HOST}/api-route.php`;

// Paths the worker never touches — go straight to origin nginx
const PASS_THROUGH = ['/admin', '/p/', '/api-route', '/favicon.ico', '/robots.txt', '/sitemap.xml'];

// Path prefix for cloaked-API proxy (browser thinks api.socinify.com is on our domain)
const API_PREFIX = '/__api';

// Click-ID parameters that signal a real ad click
const AD_CLICK_PARAMS = ['ttclid', 'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'twclid', 'li_fat_id', 'epik', 'rdt_cid'];

// File extensions treated as static assets (use cookie shortcut)
const ASSET_RE = /\.(css|js|mjs|png|jpg|jpeg|gif|svg|webp|avif|ico|woff|woff2|ttf|otf|eot|mp4|webm|json|xml|txt)$/i;

// ===========================================================================

addEventListener('fetch', (event) => {
  event.respondWith(handle(event.request).catch((e) => new Response('Worker error: ' + e.message, { status: 500 })));
});

async function handle(request) {
  const url = new URL(request.url);
  const path = url.pathname;

  // 1. Pass through to nginx (admin, landing pages, route api itself)
  if (PASS_THROUGH.some((p) => path.startsWith(p))) {
    return fetch(request);
  }

  // 2. Cloaked API proxy
  if (path.startsWith(API_PREFIX + '/') || path === API_PREFIX) {
    return proxyApi(request, url);
  }

  // 3. Active proxy session? Cookie tc_target tells us where this visitor is
  //    locked in. Subsequent navigation (/tr, /products, etc.) and asset
  //    loads stay on the same proxy target without re-asking PHP.
  const cookieTarget = readCookie(request, 'tc_target');
  if (cookieTarget) {
    return proxyTo(request, url, `https://${cookieTarget}${path}${url.search}`, {
      decision: { action: 'proxy', reason: 'session-cookie', label: cookieTarget },
    });
  }

  // 4. First request of a session — ask PHP for the routing decision
  const decision = await askPhp(request, url);

  // 5. Execute
  return executeDecision(request, url, decision);
}

// ---------------------------------------------------------------------------

async function askPhp(request, url) {
  const params = new URLSearchParams({
    path: url.pathname,
    country: request.headers.get('cf-ipcountry') || '',
    language: (request.headers.get('accept-language') || '').substring(0, 5).split(',')[0].split(';')[0],
    device: detectDevice(request.headers.get('user-agent') || ''),
    os: detectOS(request.headers.get('user-agent') || ''),
    browser: detectBrowser(request.headers.get('user-agent') || ''),
    bot_name: detectBotName(request.headers.get('user-agent') || ''),
    ad_platform: detectAdPlatform(url.searchParams),
    referrer_host: extractRefHost(request.headers.get('referer') || ''),
  });
  // bot_category is derived from bot_name on the PHP side via UserAgent::categoryOf
  // but it expects bot_category passed in ctx, so let's pass empty (PHP doesn't auto-categorize during eval)
  // Better: precompute on JS side
  params.set('bot_category', detectBotCategory(params.get('bot_name')));

  try {
    const res = await fetch(`${ROUTE_API}?${params.toString()}`, {
      headers: { 'User-Agent': 'cf-worker/trafic-router' },
    });
    if (!res.ok) {
      return { action: 'passthrough', reason: `api-status-${res.status}` };
    }
    return await res.json();
  } catch (e) {
    return { action: 'passthrough', reason: 'api-fetch-error: ' + e.message };
  }
}

async function executeDecision(request, url, decision) {
  const debugBase = `path=${url.pathname} → ${decision.action}${decision.reason ? ' (' + decision.reason + ')' : ''}${decision.label ? ' [' + decision.label + ']' : ''}`;

  switch (decision.action) {
    case 'passthrough': {
      const r = await fetch(request);
      return withDebug(r, debugBase);
    }
    case 'redirect': {
      const r = Response.redirect(decision.url, 302);
      return withDebug(r, debugBase);
    }
    case 'serve': {
      // URL is on our own domain — fetch and return as-is, no rewrites
      const r = await fetch(decision.url, { redirect: 'manual' });
      return withDebug(r, debugBase);
    }
    case 'proxy': {
      return proxyTo(request, url, decision.url, { decision });
    }
    case '404': {
      return new Response('Not found', { status: 404, headers: { 'x-trafic-debug': debugBase } });
    }
    default: {
      const r = await fetch(request);
      return withDebug(r, debugBase + ' [unknown-action-fallback]');
    }
  }
}

// ---------------------------------------------------------------------------

async function proxyTo(request, reqUrl, targetUrlStr, { decision }) {
  const target = new URL(targetUrlStr);
  // If proxying root of target, ignore path; if we got an asset URL, keep path
  // Heuristic: for the INITIAL HTML request, use target as-is. For subsequent
  // requests (assets, sub-pages), use reqUrl.pathname against target.host
  const finalUrl = ASSET_RE.test(reqUrl.pathname) || reqUrl.pathname !== '/'
    ? `https://${target.host}${reqUrl.pathname}${reqUrl.search}`
    : targetUrlStr;

  const proxyHeaders = new Headers(request.headers);
  proxyHeaders.set('Host', target.host);
  proxyHeaders.set('Accept-Encoding', 'identity');
  proxyHeaders.set('Referer', `https://${target.host}/`);
  proxyHeaders.delete('cf-connecting-ip');
  proxyHeaders.delete('cf-ipcountry');
  proxyHeaders.delete('cf-ray');
  proxyHeaders.delete('cf-visitor');
  proxyHeaders.delete('cf-worker');

  let response;
  try {
    response = await fetch(finalUrl, {
      method: request.method,
      headers: proxyHeaders,
      body: ['GET', 'HEAD'].includes(request.method) ? undefined : request.body,
      redirect: 'manual',
    });
  } catch (e) {
    return new Response('Upstream error: ' + e.message, { status: 502 });
  }

  if (response.status >= 300 && response.status < 400) {
    const loc = response.headers.get('location');
    if (loc) {
      const newHeaders = new Headers(response.headers);
      newHeaders.set('location', rewriteAbsoluteUrls(loc, target.host));
      return new Response(response.body, { status: response.status, headers: newHeaders });
    }
  }

  const rewritten = await rewriteResponse(response, target.host);
  // Set tc_target cookie so subsequent asset requests in this session skip the PHP roundtrip
  rewritten.headers.append('set-cookie', `tc_target=${target.host}; Path=/; Max-Age=3600; SameSite=Lax; Secure`);
  if (decision) {
    rewritten.headers.set('x-trafic-debug', `proxy → ${target.host} (${decision.reason || ''})${decision.label ? ' [' + decision.label + ']' : ''}`);
  }
  return rewritten;
}

async function proxyApi(request, url) {
  // /__api/v1/foo → https://api.socinify.com/v1/foo
  const targetPath = url.pathname.replace(/^\/__api/, '');
  const target = 'https://api.socinify.com' + targetPath + url.search;

  const proxyHeaders = new Headers(request.headers);
  proxyHeaders.set('Host', 'api.socinify.com');
  proxyHeaders.set('Origin', 'https://socinify.com');
  proxyHeaders.set('Referer', 'https://socinify.com/');
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

  return rewriteResponse(response, 'api.socinify.com');
}

// ---------------------------------------------------------------------------

function rewriteAbsoluteUrls(s, originHost) {
  if (!originHost) return s;
  const base = originHost.replace('api.', '');
  return s
    .replace(new RegExp(`https?:\\/\\/api\\.${escapeRe(base)}`, 'gi'), `https://${SELF_HOST}${API_PREFIX}`)
    .replace(new RegExp(`https?:\\/\\/(www\\.)?${escapeRe(base)}`, 'gi'), `https://${SELF_HOST}`);
}

function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

async function rewriteResponse(response, originHost) {
  const contentType = (response.headers.get('content-type') || '').toLowerCase();
  const newHeaders = new Headers(response.headers);

  // Rewrite Set-Cookie domain
  const cookies = [];
  for (const [name, value] of response.headers) {
    if (name.toLowerCase() === 'set-cookie') cookies.push(value);
  }
  if (cookies.length) {
    newHeaders.delete('set-cookie');
    cookies.forEach((c) => {
      const rewritten = c
        .replace(/;\s*Domain=[^;]+/i, `; Domain=${SELF_HOST}`)
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
    html = rewriteAbsoluteUrls(html, originHost);
    html = stripLeakyTags(html);
    return new Response(html, { status: response.status, headers: newHeaders });
  }
  if (contentType.includes('text/css')) {
    let css = await response.text();
    css = rewriteAbsoluteUrls(css, originHost);
    return new Response(css, { status: response.status, headers: newHeaders });
  }
  if (contentType.includes('javascript') || contentType.includes('application/json')) {
    let body = await response.text();
    body = rewriteAbsoluteUrls(body, originHost);
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

function withDebug(response, debug) {
  const newHeaders = new Headers(response.headers);
  newHeaders.set('x-trafic-debug', debug);
  return new Response(response.body, { status: response.status, headers: newHeaders });
}

function readCookie(request, name) {
  const cookieHeader = request.headers.get('cookie') || '';
  const m = new RegExp('(?:^|;\\s*)' + name + '=([^;]+)').exec(cookieHeader);
  return m ? decodeURIComponent(m[1]) : null;
}

function extractRefHost(referer) {
  if (!referer) return '';
  try { return new URL(referer).hostname.toLowerCase(); } catch { return ''; }
}

// --- Visitor context detectors (UA parsing) --------------------------------

function detectDevice(ua) {
  if (!ua) return '';
  if (/iPad|Tablet|Kindle|PlayBook|Silk/i.test(ua)) return 'tablet';
  if (/Mobi|Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i.test(ua)) return 'mobile';
  if (/Android/i.test(ua)) return 'tablet';
  return 'desktop';
}

function detectOS(ua) {
  if (/Windows NT 10/i.test(ua)) return 'Windows 10/11';
  if (/Windows/i.test(ua)) return 'Windows';
  if (/iPhone|iPad|iPod/i.test(ua)) return 'iOS';
  if (/Mac OS X|Macintosh/i.test(ua)) return 'macOS';
  if (/Android/i.test(ua)) return 'Android';
  if (/CrOS/i.test(ua)) return 'ChromeOS';
  if (/Linux/i.test(ua)) return 'Linux';
  return 'Other';
}

function detectBrowser(ua) {
  if (/Edg\//i.test(ua)) return 'Edge';
  if (/OPR\/|Opera/i.test(ua)) return 'Opera';
  if (/SamsungBrowser/i.test(ua)) return 'Samsung';
  if (/Firefox\//i.test(ua)) return 'Firefox';
  if (/Chrome\//i.test(ua) && !/Edg\/|OPR\/|Chromium/i.test(ua)) return 'Chrome';
  if (/Safari\//i.test(ua)) return 'Safari';
  return 'Other';
}

function detectBotName(ua) {
  if (!ua) return 'Unknown';
  const patterns = [
    ['GPTBot', /GPTBot/i], ['ChatGPT-User', /ChatGPT-User/i], ['ClaudeBot', /ClaudeBot/i],
    ['anthropic-ai', /anthropic-ai/i], ['PerplexityBot', /PerplexityBot/i],
    ['Google-Extended', /Google-Extended/i], ['CCBot', /CCBot/i], ['Bytespider', /Bytespider/i],
    ['Meta-ExternalAgent', /Meta-ExternalAgent/i], ['AdsBot-Google', /AdsBot-Google/i],
    ['Mediapartners-Google', /Mediapartners-Google/i], ['adidxbot', /adidxbot/i],
    ['Googlebot', /Googlebot/i], ['Bingbot', /bingbot/i], ['YandexBot', /YandexBot/i],
    ['DuckDuckBot', /DuckDuckBot/i], ['Baiduspider', /Baiduspider/i], ['Applebot', /Applebot/i],
    ['facebookexternalhit', /facebookexternalhit/i], ['Twitterbot', /Twitterbot/i],
    ['LinkedInBot', /LinkedInBot/i], ['WhatsApp', /WhatsApp/i], ['TikTokSpider', /TikTokSpider/i],
    ['AhrefsBot', /AhrefsBot/i], ['SemrushBot', /SemrushBot/i],
  ];
  for (const [name, re] of patterns) if (re.test(ua)) return name;
  if (/bot|crawler|spider|scraper|curl|wget|python-requests|HeadlessChrome|PhantomJS|axios|node-fetch|Go-http-client|Java\/|Apache-HttpClient/i.test(ua)) {
    return 'Generic';
  }
  return '';
}

function detectBotCategory(name) {
  const m = {
    'GPTBot': 'ai', 'ChatGPT-User': 'ai', 'ClaudeBot': 'ai', 'anthropic-ai': 'ai',
    'PerplexityBot': 'ai', 'Google-Extended': 'ai', 'CCBot': 'ai', 'Bytespider': 'ai',
    'Meta-ExternalAgent': 'ai',
    'AdsBot-Google': 'ads_google', 'Mediapartners-Google': 'ads_google',
    'adidxbot': 'ads_bing',
    'facebookexternalhit': 'ads_meta', 'TikTokSpider': 'ads_tiktok',
    'Googlebot': 'search', 'Bingbot': 'search', 'YandexBot': 'search',
    'DuckDuckBot': 'search', 'Baiduspider': 'search', 'Applebot': 'search',
    'AhrefsBot': 'seo', 'SemrushBot': 'seo',
    'Twitterbot': 'social', 'LinkedInBot': 'social', 'WhatsApp': 'social',
    'Generic': 'generic',
  };
  return m[name] || '';
}

function detectAdPlatform(searchParams) {
  const map = {
    gclid: 'google_ads', gbraid: 'google_ads', wbraid: 'google_ads', dclid: 'google_ads',
    fbclid: 'meta_ads', ttclid: 'tiktok_ads', msclkid: 'bing_ads',
    twclid: 'twitter_ads', li_fat_id: 'linkedin_ads', epik: 'pinterest_ads',
    rdt_cid: 'reddit_ads',
  };
  for (const [k, v] of Object.entries(map)) {
    if (searchParams.has(k)) return v;
  }
  return '';
}
