#!/usr/bin/env bash
set -euo pipefail

API_BASE_URL="${API_BASE_URL:-https://api.petertecnet.com.br}"
PUBLIC_APP_SLUGS="${PUBLIC_APP_SLUGS:-nexus rasoio cutinapp plat inkap payflow laora}"
MAX_RESPONSE_MS="${PUBLIC_CATALOG_MONITOR_MAX_RESPONSE_MS:-2500}"
MAX_PAYLOAD_BYTES="${PUBLIC_CATALOG_MONITOR_MAX_PAYLOAD_BYTES:-1048576}"
CHECKED=0
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

require_budget() {
  local seconds="$1"
  local bytes="$2"
  local label="$3"
  local milliseconds
  milliseconds="$(awk -v seconds="$seconds" 'BEGIN { printf "%.2f", seconds * 1000 }')"

  awk -v actual="$milliseconds" -v max="$MAX_RESPONSE_MS" 'BEGIN { exit !(actual <= max) }' \
    || { echo "${label}: latency ${milliseconds}ms exceeds ${MAX_RESPONSE_MS}ms" >&2; return 1; }
  [[ "$bytes" -le "$MAX_PAYLOAD_BYTES" ]] \
    || { echo "${label}: payload ${bytes} bytes exceeds ${MAX_PAYLOAD_BYTES}" >&2; return 1; }
}

for app_slug in $PUBLIC_APP_SLUGS; do
  body="$TMP_DIR/${app_slug}.json"
  meta="$TMP_DIR/${app_slug}.meta"
  url="${API_BASE_URL%/}/api/v1/apps/${app_slug}/discovery?per_page=10"

  curl --silent --show-error --location --retry 3 --retry-all-errors \
    --output "$body" --write-out '%{http_code}\n%{time_total}\n%{size_download}\n' "$url" > "$meta"

  status="$(sed -n '1p' "$meta")"
  seconds="$(sed -n '2p' "$meta")"
  bytes="$(sed -n '3p' "$meta")"

  if [[ "$status" == "404" ]] && jq -e '.code == "APPLICATION_NOT_AVAILABLE"' "$body" >/dev/null 2>&1; then
    echo "SKIP ${app_slug}: application context is not active/provisioned."
    continue
  fi

  [[ "$status" =~ ^2 ]] || { echo "${app_slug}: discovery returned HTTP ${status}" >&2; cat "$body" >&2; exit 1; }
  jq -e '.success == true' "$body" >/dev/null
  jq -e '.establishments | type == "array"' "$body" >/dev/null
  jq -e '.items | type == "array"' "$body" >/dev/null
  jq -e '.pagination | type == "object"' "$body" >/dev/null
  require_budget "$seconds" "$bytes" "${app_slug}/discovery"

  establishment_slug="$(jq -r '.establishments[0].slug // empty' "$body")"
  if [[ -n "$establishment_slug" ]]; then
    catalog="$TMP_DIR/${app_slug}-catalog.json"
    catalog_meta="$TMP_DIR/${app_slug}-catalog.meta"
    catalog_url="${API_BASE_URL%/}/api/v1/apps/${app_slug}/catalog/${establishment_slug}"

    curl --silent --show-error --location --retry 3 --retry-all-errors \
      --output "$catalog" --write-out '%{http_code}\n%{time_total}\n%{size_download}\n' "$catalog_url" > "$catalog_meta"

    catalog_status="$(sed -n '1p' "$catalog_meta")"
    catalog_seconds="$(sed -n '2p' "$catalog_meta")"
    catalog_bytes="$(sed -n '3p' "$catalog_meta")"
    [[ "$catalog_status" =~ ^2 ]] || { echo "${app_slug}: catalog returned HTTP ${catalog_status}" >&2; cat "$catalog" >&2; exit 1; }
    jq -e '.success == true' "$catalog" >/dev/null
    jq -e '.data.items | type == "array"' "$catalog" >/dev/null
    require_budget "$catalog_seconds" "$catalog_bytes" "${app_slug}/catalog"
  fi

  CHECKED=$((CHECKED + 1))
  echo "PASS ${app_slug}: public discovery contract is healthy."
done

[[ "$CHECKED" -gt 0 ]] || { echo "No active public application was checked." >&2; exit 1; }
echo "Public platform smoke passed for ${CHECKED} active application context(s)."
