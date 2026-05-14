# Trafic Router

A self-hosted traffic router. Visitors hit `/go/{slug}` and get redirected based on a decision tree using:

- **Country** (MaxMind GeoLite2)
- **Language** (`Accept-Language`)
- **Device** (mobile / tablet / desktop)
- **OS** (Windows / iOS / Android / macOS / Linux)
- **Browser** (Chrome / Safari / Firefox / Edge / ...)
- **Bot name** (GPTBot, ClaudeBot, Googlebot, Bingbot, social previews, etc.)
- **Referrer host**

Every hit is logged with an analytics dashboard. PHP 7.4+ / MySQL or MariaDB.

---

## Setup

### 1. Database

```sql
CREATE DATABASE trafic CHARACTER SET utf8mb4;
CREATE USER 'trafic'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL ON trafic.* TO 'trafic'@'localhost';
```

Load the schema:

```bash
mysql -u trafic -p trafic < schema.sql
```

If upgrading an existing install, also apply pending migrations:
```bash
for f in migrations/*.sql; do mysql -u trafic -p trafic < "$f"; done
```

### 2. Config

```bash
cp config.example.php config.php
$EDITOR config.php       # set DB credentials, base_path, etc.
```

### 3. Composer install (for MaxMind GeoIP reader)

```bash
composer install
```

### 4. Download GeoIP database

Free MaxMind account required: <https://www.maxmind.com/en/geolite2/signup>

```bash
export MAXMIND_LICENSE_KEY=xxxxxxxxxxxx
./bin/download-geoip.sh
```

This drops `data/GeoLite2-Country.mmdb`. Re-run monthly to keep it fresh (or cron it).

### 5. Create your admin user

```bash
php bin/create-admin.php youruser yourpassword
```

### 6. Web server

Point your vhost document root at the `public/` directory. Example nginx:

```nginx
server {
  listen 80;
  server_name traffic.example.com;
  root /var/www/trafic/public;
  index index.php;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
  }

  # Block direct access to non-public dirs (extra paranoia; document root is public/ anyway)
  location ~ ^/(src|data|bin|config\.php) { deny all; }
}
```

Apache: `public/.htaccess` already does the rewrite. Just enable `mod_rewrite` and set `AllowOverride All` for the directory.

### 7. Log in

Visit `https://yourdomain.com/admin/login.php` and sign in.

---

## How decision trees work

A campaign has a root node. Each node is one of:

- **Check** — tests a variable (country, language, device, etc.) and has an ordered list of **cases**. The first matching case wins; the router follows that case's child node.
- **Redirect** — final URL the visitor goes to.

Operators on cases: `equals`, `in` (list), `not_in` (list), `contains`, `starts_with`, `regex`, `default` (catch-all).

If the tree doesn't reach a redirect (e.g. no case matched and no `default`), the campaign's **default fallback URL** is used. If that's also missing, the visitor gets `204 No Content`.

### Example tree

```
Campaign: "summer-promo"  →  /go/summer-promo

Check: bot
├── Case: in [GPTBot, ClaudeBot, PerplexityBot, CCBot]  →  Redirect: https://example.com/ai-bots-blocked
├── Case: in [Googlebot, Bingbot]                       →  Redirect: https://example.com/seo-page
└── Case: default
    └── Check: country
        ├── Case: in [US, CA]   →  Check: device
        │                          ├── Case: equals "mobile"   →  Redirect: https://example.com/us-mobile
        │                          └── Case: default           →  Redirect: https://example.com/us-desktop
        ├── Case: in [DE, FR, IT, ES] →  Redirect: https://example.com/eu
        └── Case: default       →  Redirect: https://example.com/world
```

---

## Proxy delivery mode (URL masking)

Each redirect node has a **delivery mode**:

- **Redirect** (default) — a 302 with `Location:` header. Fast, reliable, works with any destination. URL bar changes to the destination.
- **Proxy** — your PHP fetches the destination server-side, rewrites the HTML so all asset URLs flow back through `/go/{slug}/_proxy?t=<signed-token>`, and streams the page to the visitor. The URL bar stays `you.com/go/{slug}`. **The destination URL never appears in any response header, link, asset request, or browser tool.**

### What proxy mode handles

- HTML response body, asset URLs in `src` / `href` / `action` / `srcset` / inline `style="..."` / `<style>` blocks
- External CSS files (also rewritten when fetched through `/_proxy`)
- `@import` rules inside CSS
- Strips `<link rel="canonical">`, `<meta property="og:url">`, `<base href>`, and `<meta http-equiv="refresh">` so the destination isn't given away in metadata
- HMAC-signed asset tokens prevent the proxy from being abused as an open proxy

### What proxy mode does NOT handle

- **JavaScript hardcoded URLs** — `fetch('https://offer.com/api/data')` inside a JS file. We can't easily rewrite arbitrary JS.
- **SPAs (React/Vue/Next.js)** — client-side routing, history.pushState, JSON APIs will mostly break.
- **Forms with cross-origin POSTs**, login flows, anything that needs the destination's cookies.
- **Anti-bot / Cloudflare-protected sites** — the destination may block your server's IP or refuse non-browser fingerprints.
- **WebSockets / streaming responses**.

**Recommended use:** simple static landing pages, affiliate offer pages, sites you control.

### Setup

1. Generate a proxy secret and put it in `config.php`:
   ```bash
   openssl rand -hex 32
   ```
2. In the tree editor, when creating or editing a redirect node, set **Delivery mode** to **Proxy**.
3. Test it with the **Test redirect** page first.

### Trade-offs to be aware of

- Bandwidth: every visitor's bytes flow through your server twice (download from destination + upload to visitor). Plus assets.
- Latency: adds the destination's response time + your server's time on top of a normal redirect.
- The visitor's browser sees your domain set the cookies, so destination-set cookies for `offer.com` won't work for the visitor.

---

## Running behind Cloudflare

This is the recommended deployment. With Cloudflare in front:

- **Real visitor IP** comes from `CF-Connecting-IP` (we use this for GeoIP + hit logs).
- **Country** comes from `CF-IPCountry` — no MaxMind read needed per request. MaxMind is used only as a fallback if CF didn't provide a code (and you can skip the GeoLite2 download entirely if you only ever serve traffic through CF).
- **HTTPS detection** uses `CF-Visitor` / `X-Forwarded-Proto` (CF often terminates TLS at the edge and proxies HTTP to your origin in Flexible mode — use Full or Full (strict) for safety).

### Required config

In `config.php`:
```php
'trust_proxy_headers' => true,  // MUST be true behind CF
```

### Cloudflare panel settings to check

1. **SSL/TLS → Overview**: set to **Full (strict)** if your origin has a valid cert (Let's Encrypt is enough). Avoid **Flexible** — that's CF → user encrypted but origin → CF in plaintext.
2. **Caching → Cache Rules / Page Rules**: do NOT cache `/go/*`. The redirect logic depends on per-visitor headers (country, UA, referrer). If CF serves a cached response, everyone gets the same redirect.
   - Quick fix: a Page Rule for `*yourdomain.com/go/*` with **Cache Level: Bypass**.
3. **Security → Bots → Bot Fight Mode**: ⚠️ **Disable for /go/* paths.** CF will challenge/block bots before they ever reach our app — which defeats the point of our bot-targeting rules. Either turn off entirely, or use a **Configuration Rule** to disable bot management for the `/go/*` URL pattern.
4. **Speed → Optimization → Auto Minify / Rocket Loader**: turn off for `/go/*` if you use **proxy mode** — they can break the rewritten HTML.
5. **Network → IP Geolocation**: must be **On** (it usually is by default) so we receive `CF-IPCountry`.

### Lock origin to Cloudflare IPs only (highly recommended)

Otherwise attackers can find your origin IP and bypass CF entirely. On Ubuntu:

```bash
# Get current CF IPv4 ranges
curl -s https://www.cloudflare.com/ips-v4 | while read ip; do
  ufw allow from "$ip" to any port 443 proto tcp
  ufw allow from "$ip" to any port 80 proto tcp
done
curl -s https://www.cloudflare.com/ips-v6 | while read ip; do
  ufw allow from "$ip" to any port 443 proto tcp
  ufw allow from "$ip" to any port 80 proto tcp
done

# Remove the public allow rules
ufw delete allow 'Nginx Full'
ufw reload
```

Run this monthly via cron — CF's IP ranges change occasionally.

### nginx — real visitor IP

Add to your server block so `$remote_addr` and `access_log` show the real visitor (not Cloudflare):

```nginx
# CF IPv4 ranges (regenerate periodically: https://www.cloudflare.com/ips-v4)
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
# CF IPv6 ranges
set_real_ip_from 2400:cb00::/32;
set_real_ip_from 2606:4700::/32;
set_real_ip_from 2803:f800::/32;
set_real_ip_from 2405:b500::/32;
set_real_ip_from 2405:8100::/32;
set_real_ip_from 2a06:98c0::/29;
set_real_ip_from 2c0f:f248::/32;

real_ip_header CF-Connecting-IP;
```

---

## Maintenance

- **Hit logs grow forever.** Periodically prune:
  ```sql
  DELETE FROM hits WHERE hit_at < NOW() - INTERVAL 90 DAY;
  ```
  (Or cron it.)

- **GeoIP refresh.** MaxMind updates GeoLite2 weekly. Re-run `bin/download-geoip.sh` from cron:
  ```cron
  17 4 * * 3 cd /var/www/trafic && MAXMIND_LICENSE_KEY=xxx ./bin/download-geoip.sh > /dev/null
  ```

- **Behind Cloudflare / nginx proxy?** Set `trust_proxy_headers => true` in `config.php` so the router uses `CF-Connecting-IP` / `X-Forwarded-For` for the real visitor IP.

---

## Project layout

```
trafic/
├── bin/                  CLI helpers (create-admin, download-geoip)
├── data/                 GeoLite2-Country.mmdb (gitignored)
├── public/               Document root
│   ├── index.php         Public router (/go/{slug})
│   ├── .htaccess         URL rewrite
│   └── admin/            Admin panel
├── src/                  PHP classes (Database, GeoIP, UserAgent, TreeEvaluator, Auth)
├── config.example.php    Copy to config.php
├── composer.json
└── schema.sql            MySQL schema
```
