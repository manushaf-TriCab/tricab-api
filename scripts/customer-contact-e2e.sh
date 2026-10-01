#!/usr/bin/env bash
# Explicit Customer Contact browser harness.
# Uses an in-memory user repository and a temp-file Customer Contact store.
# It refuses ports 8080 and 5173 so it cannot replace the normal API or web app.
# Stopping this script stops the listener. It does not edit .env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8091}"

if [[ "$PORT" == "8080" || "$PORT" == "5173" ]]; then
  echo "Customer Contact E2E refuses ports 8080 and 5173. Use composer start and npm run dev for normal development." >&2
  exit 1
fi

if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Port ${PORT} is already in use. Stop that process before starting the harness." >&2
  exit 1
fi

ENV_FILE="$(mktemp)"
ROUTER="$(mktemp)"
PHP_PID=""

cleanup() {
  if [[ -n "$PHP_PID" ]]; then
    kill "$PHP_PID" 2>/dev/null || true
    wait "$PHP_PID" 2>/dev/null || true
  fi
  rm -f "$ENV_FILE" "$ROUTER"
}
trap cleanup EXIT INT TERM

cat > "$ENV_FILE" << 'PHP'
<?php
$overrides = [
    'USER_REPOSITORY' => 'memory',
    'BLOB_AUTH_MODE' => 'memory',
    'CUSTOMER_CONTACT_MODULE_ENABLED' => 'true',
    'CUSTOMER_CONTACT_EMAIL_ENABLED' => 'false',
    'CUSTOMER_CONTACT_E2E_MODE' => 'true',
    'CUSTOMER_CONTACT_ACCOUNTS_LOGIN' => 'cc-e2e-accounts@tricab.test',
    'CUSTOMER_CONTACT_MAIL_FROM' => '',
    'CORS_ALLOWED_ORIGINS' => 'http://127.0.0.1:5174,http://localhost:5174',
];
foreach ($overrides as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}
PHP

cat > "$ROUTER" << PHP
<?php
require '${ENV_FILE}';
return require '${ROOT}/public/router.php';
PHP

echo "Customer Contact E2E API on http://127.0.0.1:${PORT}"
echo "Web harness: npm run dev:customer-contact-e2e (port 5174). Normal npm run dev is unchanged."

php -S "127.0.0.1:${PORT}" -t "${ROOT}/public" "$ROUTER" &
PHP_PID=$!
wait "$PHP_PID"
