#!/bin/zsh
set -euo pipefail

# Runs OUTSIDE Cursor sandbox so az can reach login.microsoftonline.com
# and PHP children inherit a clean (non-proxy) environment.

cd /Users/thilanh/Documents/Projects/tricab-core/tricab-api
LOG=/tmp/tricab-api-local.log

# Strip any Cursor sandbox proxies if somehow inherited
unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy ALL_PROXY all_proxy
unset GIT_HTTP_PROXY GIT_HTTPS_PROXY SOCKS_PROXY SOCKS5_PROXY socks_proxy socks5_proxy
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"
export HOME="/Users/thilanh"

echo "=== TriCab local API repair (outside Cursor) ==="
echo "1) Azure account (no token printed)..."
az account show --query '{user:user.name,sub:name,state:state}' -o json

echo "2) Azure SQL token expiry only..."
EXPIRES="$(az account get-access-token --resource https://database.windows.net/ --query expiresOn -o tsv)"
echo "expiresOn=${EXPIRES}"

echo "3) Restart API on 127.0.0.1:8080..."
pkill -f 'php -S 127.0.0.1:8080' 2>/dev/null || true
sleep 1
# force free port if needed
for pid in $(lsof -nP -iTCP:8080 -sTCP:LISTEN -t 2>/dev/null); do
  kill -9 "$pid" 2>/dev/null || true
done
sleep 1

export PHP_CLI_SERVER_WORKERS=8
nohup php -S 127.0.0.1:8080 -t public public/router.php >"$LOG" 2>&1 &
echo "API_PID=$!"
sleep 2

echo "4) Health checks..."
curl -sS http://127.0.0.1:8080/health; echo
curl -sS http://127.0.0.1:8080/health/database; echo
curl -sS -o /tmp/regions-check.out -w "regions_http=%{http_code}\n" http://127.0.0.1:8080/api/v1/regions
head -c 120 /tmp/regions-check.out; echo

echo
echo "Done. Retry localhost web + Try again on iOS simulator."
echo "Prove SQL token later with:"
echo "  az account get-access-token --resource https://database.windows.net/ --query expiresOn -o tsv"
read -r '?Press Enter to close '
