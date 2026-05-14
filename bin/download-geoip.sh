#!/usr/bin/env bash
# Download MaxMind GeoLite2-Country.mmdb
# You need a free MaxMind account: https://www.maxmind.com/en/geolite2/signup
# Then create a license key in your account: https://www.maxmind.com/en/accounts/current/license-key

set -euo pipefail

if [ -z "${MAXMIND_LICENSE_KEY:-}" ]; then
  echo "Set MAXMIND_LICENSE_KEY env var first."
  echo "Get a free key at: https://www.maxmind.com/en/accounts/current/license-key"
  exit 1
fi

DEST_DIR="$(cd "$(dirname "$0")/.." && pwd)/data"
mkdir -p "$DEST_DIR"
cd "$DEST_DIR"

URL="https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz"
echo "Downloading GeoLite2-Country.mmdb..."
curl -fL -o GeoLite2-Country.tar.gz "$URL"

echo "Extracting..."
tar -xzf GeoLite2-Country.tar.gz --strip-components=1 --wildcards '*/GeoLite2-Country.mmdb'
rm -f GeoLite2-Country.tar.gz
echo "Done. File: $DEST_DIR/GeoLite2-Country.mmdb"
