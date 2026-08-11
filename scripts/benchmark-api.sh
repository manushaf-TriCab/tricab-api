#!/usr/bin/env bash
# Local API latency samples. Does not print tokens.
# Usage:
#   API_BASE_URL=http://127.0.0.1:8080 ./scripts/benchmark-api.sh
# Admin endpoints need a bearer token:
#   API_BEARER_TOKEN=... ./scripts/benchmark-api.sh

set -euo pipefail

API_BASE_URL="${API_BASE_URL:-http://127.0.0.1:8080}"
TOKEN="${API_BEARER_TOKEN:-}"

curl_ms() {
  local url="$1"
  shift
  curl -sS -o /dev/null -w '%{http_code} %{time_total}' "$@" "$url"
}

echo "API_BASE_URL=${API_BASE_URL}"
echo

echo "GET /health (no SQL)"
curl_ms "${API_BASE_URL}/health"
echo
echo

echo "catalogue panel"
echo -n "  first:  "
curl_ms "${API_BASE_URL}/api/v1/catalogue/panel?includeProducts=false"
echo
echo -n "  second: "
curl_ms "${API_BASE_URL}/api/v1/catalogue/panel?includeProducts=false"
echo
echo -n "  etag:   "
ETAG="$(curl -sS -D - -o /dev/null "${API_BASE_URL}/api/v1/catalogue/panel?includeProducts=false" | awk 'tolower($1)=="etag:"{print $2}' | tr -d '\r')"
if [[ -n "${ETAG}" ]]; then
  curl_ms "${API_BASE_URL}/api/v1/catalogue/panel?includeProducts=false" -H "If-None-Match: ${ETAG}"
else
  echo "NO_ETAG"
fi
echo
echo

if [[ -z "${TOKEN}" ]]; then
  echo "Skipping admin endpoints (set API_BEARER_TOKEN)."
  exit 0
fi

AUTH=(-H "Authorization: Bearer ${TOKEN}")

echo "product lookups"
echo -n "  first:  "
curl_ms "${API_BASE_URL}/api/v1/admin/product-lookups" "${AUTH[@]}"
echo
echo -n "  second: "
curl_ms "${API_BASE_URL}/api/v1/admin/product-lookups" "${AUTH[@]}"
echo
echo

echo "catalogue admin tree (requires tabPublicId)"
TAB_QUERY="${CATALOGUE_TAB_PUBLIC_ID:-}"
if [[ -z "${TAB_QUERY}" ]]; then
  echo "  Skipping (set CATALOGUE_TAB_PUBLIC_ID)."
  exit 0
fi
echo -n "  first:  "
curl_ms "${API_BASE_URL}/api/v1/admin/catalogue-nodes/tree?tabPublicId=${TAB_QUERY}" "${AUTH[@]}"
echo
echo -n "  second: "
curl_ms "${API_BASE_URL}/api/v1/admin/catalogue-nodes/tree?tabPublicId=${TAB_QUERY}" "${AUTH[@]}"
echo
