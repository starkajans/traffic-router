#!/usr/bin/env bash
# ============================================================================
# Trafic Router — automated install for Ubuntu 24.04 LTS
# Run as root:
#   wget https://raw.githubusercontent.com/starkajans/traffic-router/main/bin/install.sh
#   bash install.sh
# ============================================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log()  { echo -e "${BLUE}==>${NC} $*"; }
ok()   { echo -e "${GREEN}✓${NC} $*"; }
warn() { echo -e "${YELLOW}!${NC} $*"; }
err()  { echo -e "${RED}✗${NC} $*"; exit 1; }

REPO_URL="https://github.com/starkajans/traffic-router.git"
APP_DIR="/var/www/trafic"

# ---- preflight ------------------------------------------------------------
[[ $EUID -eq 0 ]] || err "Bu script root olarak çalıştırılmalı. Try: sudo bash install.sh"

if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    if [[ "$ID" != "ubuntu" || "$VERSION_ID" != "24.04" ]]; then
        warn "Bu script Ubuntu 24.04 için yazıldı. Mevcut: ${PRETTY_NAME:-bilinmiyor}. Devam ediyorum..."
    fi
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "  Trafic Router — Otomatik Kurulum"
echo "════════════════════════════════════════════════════════════════"
echo

# ---- inputs ---------------------------------------------------------------
read -rp "Domain (örn: traffic.mysite.com): " DOMAIN
[[ -z "$DOMAIN" ]] && err "Domain boş olamaz"

read -rp "Admin kullanıcı adı: " ADMIN_USER
[[ -z "$ADMIN_USER" ]] && err "Admin kullanıcı adı boş olamaz"

while true; do
    read -rsp "Admin şifresi (en az 8 karakter): " ADMIN_PASS && echo
    if [[ ${#ADMIN_PASS} -ge 8 ]]; then break; fi
    warn "Şifre çok kısa, tekrar dene"
done

echo
echo "SSL yöntemi:"
echo "  1) Let's Encrypt (otomatik, Cloudflare proxy GRİ olmalı şu an)"
echo "  2) Cloudflare Origin Certificate (cert + key'i yapıştır, CF proxy TURUNCU kalır)"
echo "  3) Atla (SSL'i sonra kuracaksın)"
read -rp "Seçim [1/2/3, default=2]: " SSL_MODE
SSL_MODE=${SSL_MODE:-2}

LE_EMAIL=""
if [[ "$SSL_MODE" == "1" ]]; then
    read -rp "Let's Encrypt için email: " LE_EMAIL
    [[ -z "$LE_EMAIL" ]] && err "Email boş olamaz"
fi

read -rp "MaxMind GeoIP license key (boş bırak = atla, CF arkasındaysan gerekmez): " MAXMIND_KEY

read -rp "Cloudflare arkasında mı? Origin'i sadece CF IP'lerine kilitleyeyim mi? [Y/n]: " USE_CF
USE_CF=${USE_CF:-Y}

# Random secrets
DB_PASS=$(openssl rand -hex 16)
PROXY_SECRET=$(openssl rand -hex 32)

echo
log "Kurulum başlıyor — bu birkaç dakika sürer..."
echo

# ---- 1. packages ----------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive

log "Sistem güncelleniyor..."
apt-get update -qq
apt-get upgrade -y -qq

log "Paketler kuruluyor (nginx, MariaDB, PHP 8.3, composer, certbot, ufw, fail2ban)..."
apt-get install -y -qq \
    nginx mariadb-server \
    php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl \
    composer git unzip curl ca-certificates \
    certbot python3-certbot-nginx \
    ufw fail2ban cron \
    >/dev/null
ok "Paketler kuruldu"

# ---- 2. firewall ----------------------------------------------------------
log "Firewall ayarlanıyor (ufw)..."
ufw --force reset >/dev/null
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
ok "Firewall aktif (SSH + nginx açık)"

# ---- 3. MariaDB -----------------------------------------------------------
log "MariaDB hazırlanıyor..."
systemctl enable --now mariadb >/dev/null
# Idempotent — drop+recreate user to ensure password matches
mysql <<SQL
CREATE DATABASE IF NOT EXISTS trafic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
DROP USER IF EXISTS 'trafic'@'localhost';
CREATE USER 'trafic'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL ON trafic.* TO 'trafic'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "DB ve kullanıcı oluşturuldu"

# ---- 4. clone repo --------------------------------------------------------
log "Repo klonlanıyor: $REPO_URL"
mkdir -p /var/www
if [[ -d "$APP_DIR/.git" ]]; then
    cd "$APP_DIR"
    git fetch --quiet origin
    git reset --hard origin/main
else
    git clone --quiet "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
ok "Repo: $APP_DIR"

# ---- 5. composer ----------------------------------------------------------
log "Composer dependencies kuruluyor..."
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction --quiet 2>&1 | grep -v "^$" || true
ok "Composer install tamam"

# ---- 6. schema + migrations ------------------------------------------------
log "DB schema yükleniyor..."
mysql -u trafic -p"$DB_PASS" trafic < "$APP_DIR/schema.sql"
# Migrations are idempotent for fresh installs — schema already has columns, so these no-op
for f in "$APP_DIR"/migrations/*.sql; do
    mysql -u trafic -p"$DB_PASS" trafic < "$f" 2>/dev/null || true
done
ok "Schema yüklendi"

# ---- 7. config.php --------------------------------------------------------
log "config.php oluşturuluyor..."
cat > "$APP_DIR/config.php" <<PHP
<?php
// Auto-generated by install.sh — DB password and proxy_secret are random.
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'trafic',
        'user'     => 'trafic',
        'password' => '$DB_PASS',
        'charset'  => 'utf8mb4',
    ],
    'geoip_db' => __DIR__ . '/data/GeoLite2-Country.mmdb',
    'trust_proxy_headers' => true,
    'session_name' => 'trafic_admin',
    'base_path' => '',
    'proxy_secret' => '$PROXY_SECRET',
    'proxy_timeout' => 15,
    'proxy_max_bytes' => 8 * 1024 * 1024,
];
PHP
chown www-data:www-data "$APP_DIR/config.php"
chmod 640 "$APP_DIR/config.php"
ok "config.php yazıldı"

# ---- 8. permissions -------------------------------------------------------
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod +x "$APP_DIR/bin/"*.sh

# ---- 9. admin user --------------------------------------------------------
log "Admin kullanıcı oluşturuluyor..."
sudo -u www-data php "$APP_DIR/bin/create-admin.php" "$ADMIN_USER" "$ADMIN_PASS"
ok "Admin: $ADMIN_USER"

# ---- 10. GeoIP ------------------------------------------------------------
if [[ -n "$MAXMIND_KEY" ]]; then
    log "MaxMind GeoIP database indiriliyor..."
    cd "$APP_DIR"
    if MAXMIND_LICENSE_KEY="$MAXMIND_KEY" sudo -u www-data bash bin/download-geoip.sh; then
        ok "GeoIP indirildi"
    else
        warn "GeoIP download başarısız (key yanlış?). Atlandı. CF arkasındaysan sorun değil."
    fi
else
    warn "MaxMind key girilmedi — GeoIP atlandı. CF-IPCountry header'ı kullanılacak."
fi

# ---- 11. nginx vhost ------------------------------------------------------
log "nginx vhost ayarlanıyor: $DOMAIN"
cat > /etc/nginx/sites-available/trafic <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;

    # ---- Cloudflare real IP -----------------------------------------------
    # CF IP ranges (regenerate via cron from https://www.cloudflare.com/ips-v4)
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
    set_real_ip_from 2400:cb00::/32;
    set_real_ip_from 2606:4700::/32;
    set_real_ip_from 2803:f800::/32;
    set_real_ip_from 2405:b500::/32;
    set_real_ip_from 2405:8100::/32;
    set_real_ip_from 2a06:98c0::/29;
    set_real_ip_from 2c0f:f248::/32;
    real_ip_header CF-Connecting-IP;

    # ---- Routing ----------------------------------------------------------
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    # Block direct access to non-public paths
    location ~ ^/(src|data|bin|config\.php|migrations|composer\.|schema\.sql) { deny all; return 404; }
    location ~ /\.(git|env|ht) { deny all; return 404; }

    add_header X-Robots-Tag "noindex, nofollow" always;

    client_max_body_size 1m;
    access_log /var/log/nginx/trafic-access.log;
    error_log  /var/log/nginx/trafic-error.log;
}
NGINX

ln -sf /etc/nginx/sites-available/trafic /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

nginx -t >/dev/null 2>&1 || err "nginx config hatası — manuel kontrol: nginx -t"
systemctl reload nginx
ok "nginx aktif: http://$DOMAIN"

# ---- 12. SSL --------------------------------------------------------------
case "$SSL_MODE" in
    1)
        log "SSL sertifikası alınıyor (Let's Encrypt)..."
        if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LE_EMAIL" --redirect --no-eff-email 2>&1 | tail -5; then
            ok "HTTPS aktif: https://$DOMAIN"
        else
            warn "Certbot başarısız. DNS henüz $DOMAIN için bu sunucuyu göstermiyor olabilir."
            warn "Manuel: certbot --nginx -d $DOMAIN"
        fi
        ;;
    2)
        log "Cloudflare Origin Certificate kuruluyor..."
        mkdir -p /etc/ssl/cloudflare
        chmod 700 /etc/ssl/cloudflare

        echo
        echo "─────────────────────────────────────────────────────────────"
        echo "  Cloudflare Origin Certificate'ı yapıştır."
        echo "  Başlangıç: -----BEGIN CERTIFICATE-----"
        echo "  Bitiş:     -----END CERTIFICATE-----"
        echo "  YAPIŞTIRDIKTAN SONRA: yeni satıra geç, Ctrl-D ile bitir"
        echo "─────────────────────────────────────────────────────────────"
        cat > /etc/ssl/cloudflare/origin.pem

        echo
        echo "─────────────────────────────────────────────────────────────"
        echo "  Şimdi Private Key'i yapıştır."
        echo "  Başlangıç: -----BEGIN PRIVATE KEY-----  (veya -----BEGIN RSA PRIVATE KEY-----)"
        echo "  Bitiş:     -----END PRIVATE KEY-----    (veya -----END RSA PRIVATE KEY-----)"
        echo "  YAPIŞTIRDIKTAN SONRA: yeni satıra geç, Ctrl-D ile bitir"
        echo "─────────────────────────────────────────────────────────────"
        cat > /etc/ssl/cloudflare/origin.key

        chmod 600 /etc/ssl/cloudflare/origin.key
        chmod 644 /etc/ssl/cloudflare/origin.pem
        chown root:root /etc/ssl/cloudflare/origin.*

        # Validate
        if ! openssl x509 -in /etc/ssl/cloudflare/origin.pem -noout >/dev/null 2>&1; then
            err "Certificate geçersiz görünüyor — /etc/ssl/cloudflare/origin.pem dosyasını kontrol et"
        fi
        if ! openssl pkey -in /etc/ssl/cloudflare/origin.key -noout >/dev/null 2>&1; then
            err "Private key geçersiz görünüyor — /etc/ssl/cloudflare/origin.key dosyasını kontrol et"
        fi

        # Rewrite nginx config with HTTPS server + HTTP→HTTPS redirect
        cat > /etc/nginx/sites-available/trafic <<NGINX
# HTTP → HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}

# HTTPS — Cloudflare Origin Certificate
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # ---- Cloudflare real IP -----------------------------------------------
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
    set_real_ip_from 2400:cb00::/32;
    set_real_ip_from 2606:4700::/32;
    set_real_ip_from 2803:f800::/32;
    set_real_ip_from 2405:b500::/32;
    set_real_ip_from 2405:8100::/32;
    set_real_ip_from 2a06:98c0::/29;
    set_real_ip_from 2c0f:f248::/32;
    real_ip_header CF-Connecting-IP;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ ^/(src|data|bin|config\.php|migrations|composer\.|schema\.sql) { deny all; return 404; }
    location ~ /\.(git|env|ht) { deny all; return 404; }

    add_header X-Robots-Tag "noindex, nofollow" always;
    client_max_body_size 1m;
    access_log /var/log/nginx/trafic-access.log;
    error_log  /var/log/nginx/trafic-error.log;
}
NGINX
        nginx -t >/dev/null 2>&1 || err "nginx config hatası — manuel kontrol: nginx -t"
        systemctl reload nginx
        ok "HTTPS aktif (Cloudflare Origin Cert): https://$DOMAIN"
        echo
        echo "  → Cloudflare panelinde: SSL/TLS → Overview → Full (strict) seç"
        ;;
    3)
        warn "SSL atlandı. Site sadece HTTP üzerinden çalışacak (http://$DOMAIN)"
        warn "Sonra kurmak için: certbot --nginx -d $DOMAIN  (LE)"
        ;;
    *)
        err "Geçersiz SSL seçimi: $SSL_MODE"
        ;;
esac

# ---- 13. Cloudflare lock-down ---------------------------------------------
if [[ "$USE_CF" =~ ^[Yy]$ ]]; then
    log "Origin Cloudflare IP'lerine kilitleniyor..."
    while IFS= read -r ip; do
        [[ -z "$ip" ]] && continue
        ufw allow from "$ip" to any port 80 proto tcp >/dev/null 2>&1 || true
        ufw allow from "$ip" to any port 443 proto tcp >/dev/null 2>&1 || true
    done < <(curl -fsS https://www.cloudflare.com/ips-v4; echo; curl -fsS https://www.cloudflare.com/ips-v6)
    # Remove public allow rules
    ufw delete allow 'Nginx Full' >/dev/null 2>&1 || true
    ufw delete allow 'Nginx HTTP' >/dev/null 2>&1 || true
    ufw delete allow 'Nginx HTTPS' >/dev/null 2>&1 || true
    ufw reload >/dev/null
    ok "Origin sadece Cloudflare IP'lerinden erişilebilir"
fi

# ---- 14. cron jobs --------------------------------------------------------
log "Cron job'ları kuruluyor..."

# 90 günden eski hit log'larını temizle
cat > /etc/cron.d/trafic-cleanup <<CRON
30 3 * * * www-data mysql -u trafic -p$DB_PASS trafic -e "DELETE FROM hits WHERE hit_at < NOW() - INTERVAL 90 DAY;" >/dev/null 2>&1
CRON

# GeoIP haftalık güncelleme
if [[ -n "$MAXMIND_KEY" ]]; then
    cat > /etc/cron.d/trafic-geoip <<CRON
17 4 * * 3 www-data cd $APP_DIR && MAXMIND_LICENSE_KEY=$MAXMIND_KEY ./bin/download-geoip.sh >/dev/null 2>&1
CRON
fi

# Otomatik güvenlik güncellemeleri
apt-get install -y -qq unattended-upgrades >/dev/null
echo 'APT::Periodic::Update-Package-Lists "1"; APT::Periodic::Unattended-Upgrade "1";' \
    > /etc/apt/apt.conf.d/20auto-upgrades

chmod 600 /etc/cron.d/trafic-* 2>/dev/null || true
ok "Cron'lar kuruldu"

# ---- 15. done -------------------------------------------------------------
echo
echo "════════════════════════════════════════════════════════════════"
ok "KURULUM TAMAM"
echo "════════════════════════════════════════════════════════════════"
echo
echo "  Admin paneli:  https://$DOMAIN/admin/login.php"
echo "  Kullanıcı:     $ADMIN_USER"
echo "  Repo:          $APP_DIR"
echo "  Nginx logs:    /var/log/nginx/trafic-{access,error}.log"
echo
echo "  DB şifresi (güvenli yerde sakla):"
echo "    $DB_PASS"
echo
echo "  Cloudflare panelinde kontrol et:"
echo "    1) DNS A kaydı $DOMAIN → bu sunucu IP, proxy turuncu"
echo "    2) SSL/TLS → Full (strict)"
echo "    3) Caching → Cache Rules → /go/* → Bypass cache"
echo "    4) Security → Bots → Bot Fight Mode → OFF"
echo "    5) Speed → Auto Minify, Rocket Loader → /go/* için OFF (proxy mode kullanırsan)"
echo
echo "  Güncelleme yapmak için:"
echo "    cd $APP_DIR && git pull && sudo -u www-data composer install --no-dev"
echo
