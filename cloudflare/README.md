# Cloudflare Worker — socinify reverse proxy

Full reverse proxy that serves socinify.com under your domain for **TR mobile humans only**, with payment paths redirecting to real socinify.

## Routing matrix

| Path / visitor | Behaviour |
|---|---|
| `/admin/*`, `/p/*`, `/go/*`, `/favicon.ico`, `/robots.txt` | Passes through to your nginx (admin panel, AI landings, campaign router) |
| `/__api/*` | Forwarded to `api.socinify.com` (for AJAX from cloaked pages) |
| `/checkout`, `/payment`, `/cart`, `/sepet`, `/odeme`, `/login`, `/register`, etc. | 302 redirect to real socinify.com (URL bar changes) |
| All other paths, **TR + mobile + human** | Socinify content proxied under your domain (URL bar stays clean) |
| All other paths, **bot / non-TR / non-mobile** | Your `/p/home` landing (clean, safe for ad reviewers) |

## ⚠️ Risks

This is **cloaking** (different content to bots vs humans). Specifically:
- Google AdsBot-Google-Mobile may pretend to be a TR mobile human → catches you → ad account suspension
- Manual ad reviewers (Google, Meta) can use TR mobile UA / VPN → see socinify content → "deceptive" verdict
- Socinify may detect their content served from a foreign IP / origin → block your worker
- Affiliate commissions may not track properly if their tracking depends on `Referer` matching their domain

If risk concerns you, use the simpler "own landing + CTA to socinify" approach instead.

## Deployment

### 1. Create the Worker

1. Login to <https://dash.cloudflare.com>
2. Sidebar → **Workers & Pages** → **Create**
3. Choose **Create Worker** (not Pages)
4. Name it: `trafic-socinify-proxy` → **Deploy**
5. Click the new worker → **Edit code**
6. Replace the default content with the contents of [`worker.js`](./worker.js)
7. Click **Save and Deploy**

### 2. Bind the route

1. Cloudflare dashboard → your domain (`sosyalmedyaservisleri.com`)
2. **Workers Routes** → **Add route**
3. Route: `sosyalmedyaservisleri.com/*`
4. Worker: select `trafic-socinify-proxy`
5. **Save**

### 3. Verify

Open in different conditions:

```bash
# TR mobile (should proxy socinify) — use iPhone UA
curl -A "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1" \
     -I https://sosyalmedyaservisleri.com/

# Desktop / non-TR (should serve /p/home)
curl -I https://sosyalmedyaservisleri.com/

# Checkout (should 302 to socinify.com)
curl -I https://sosyalmedyaservisleri.com/checkout
```

### 4. Disable the existing "Ana Sayfa" campaign

Once the worker is live, the PHP campaign for `/` is bypassed. To avoid confusion:
- Admin → Campaigns → **Ana Sayfa** → **Disable** (or Delete)

The `/go/turkmobil` campaign keeps working — worker passes `/go/*` through to origin.

## Tuning

Open `worker.js` and edit:

| Constant | What it controls |
|---|---|
| `SOCINIFY` | Upstream domain to proxy from |
| `API_BASE` | Upstream API origin (used by `/__api/*`) |
| `CLEAN_LANDING` | Where bots / non-TR / non-mobile land |
| `PASS_THROUGH` | Path prefixes that go to your nginx untouched |
| `CHECKOUT_PATHS` | Path prefixes that redirect to real socinify |

After editing, paste the new code into the worker editor and **Save and Deploy**.

## Monitoring

Cloudflare dashboard → Workers → `trafic-socinify-proxy`:
- **Logs**: see live requests + any errors
- **Analytics**: requests/day, latency, error rate
- **Free tier**: 100,000 requests/day. Past that → $5 per 10M.

## Killswitch

If something breaks (socinify blocks you, ads get flagged, etc.):
1. Cloudflare dashboard → Workers Routes → toggle the route **off**, OR
2. Delete the worker entirely
Both restore the old behaviour (`/` goes through your PHP router) instantly.
