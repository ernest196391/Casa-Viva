#!/usr/bin/env bash
set -euo pipefail

base_url="${1:-${STAGING_URL:-}}"
if [[ -z "$base_url" ]]; then
  echo "Usage: $0 https://staging.example.com" >&2
  exit 64
fi

base_url="${base_url%/}"
if [[ "$base_url" != https://* && "${ALLOW_INSECURE_STAGING:-0}" != "1" ]]; then
  echo "Staging URL must use HTTPS. Set ALLOW_INSECURE_STAGING=1 only for an isolated local test." >&2
  exit 65
fi

curl_common=(--silent --show-error --location --connect-timeout 10 --max-time 30)

echo "[1/4] Front page availability"
front_code="$(curl "${curl_common[@]}" --output /tmp/cvd-front.html --write-out '%{http_code}' "$base_url/")"
if [[ "$front_code" -lt 200 || "$front_code" -ge 400 ]]; then
  echo "Front page returned HTTP $front_code" >&2
  exit 1
fi

echo "[2/4] WordPress REST index"
rest_code="$(curl "${curl_common[@]}" --output /tmp/cvd-rest.json --write-out '%{http_code}' "$base_url/wp-json/")"
if [[ "$rest_code" -ne 200 ]]; then
  echo "WordPress REST index returned HTTP $rest_code" >&2
  exit 1
fi
python3 - <<'PY'
import json
from pathlib import Path
payload = json.loads(Path('/tmp/cvd-rest.json').read_text())
namespaces = payload.get('namespaces', [])
if 'casa-viva/v1' not in namespaces:
    raise SystemExit('Casa Viva REST namespace casa-viva/v1 is not registered')
print('Casa Viva REST namespace registered')
PY

echo "[3/4] Protected Casa Viva route is present and private"
protected_code="$(curl "${curl_common[@]}" --output /tmp/cvd-protected.json --write-out '%{http_code}' "$base_url/wp-json/casa-viva/v1/order-center/0")"
if [[ "$protected_code" != "401" && "$protected_code" != "403" ]]; then
  echo "Expected protected route to return 401/403 to anonymous request; got HTTP $protected_code" >&2
  cat /tmp/cvd-protected.json >&2 || true
  exit 1
fi

echo "[4/4] No obvious WordPress fatal error on front page"
if grep -Eqi 'There has been a critical error|Fatal error:|Parse error:' /tmp/cvd-front.html; then
  echo "Front page contains a WordPress/PHP fatal error marker" >&2
  exit 1
fi

echo "Staging smoke checks passed for $base_url"
