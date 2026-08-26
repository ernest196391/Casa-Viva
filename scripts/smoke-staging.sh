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

echo "[1/6] Front page availability"
front_code="$(curl "${curl_common[@]}" --output /tmp/cvd-front.html --write-out '%{http_code}' "$base_url/")"
if [[ "$front_code" -lt 200 || "$front_code" -ge 400 ]]; then
  echo "Front page returned HTTP $front_code" >&2
  exit 1
fi

echo "[2/6] WordPress REST index"
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

echo "[3/6] Protected Casa Viva route is present and private"
protected_code="$(curl "${curl_common[@]}" --output /tmp/cvd-protected.json --write-out '%{http_code}' "$base_url/wp-json/casa-viva/v1/order-center/0")"
if [[ "$protected_code" != "401" && "$protected_code" != "403" ]]; then
  echo "Expected protected route to return 401/403 to anonymous request; got HTTP $protected_code" >&2
  cat /tmp/cvd-protected.json >&2 || true
  exit 1
fi

echo "[4/6] Public canonical tariff page"
tariff_code="$(curl "${curl_common[@]}" --output /tmp/cvd-tariffs.html --write-out '%{http_code}' "$base_url/tarifas-mensajeria/")"
if [[ "$tariff_code" -ne 200 ]] || ! grep -Fq 'Tarifa de mensajería' /tmp/cvd-tariffs.html; then
  echo "Canonical tariff page is unavailable or incomplete (HTTP $tariff_code)" >&2
  exit 1
fi

echo "[5/6] Private canonical route entry"
route_code="$(curl --silent --show-error --connect-timeout 10 --max-time 30 --output /tmp/cvd-route.html --write-out '%{http_code}' "$base_url/ruta-cv/")"
if [[ "$route_code" != "301" && "$route_code" != "302" && "$route_code" != "303" && "$route_code" != "307" ]]; then
  echo "Expected anonymous route entry to redirect to authentication; got HTTP $route_code" >&2
  exit 1
fi

echo "[6/6] No obvious WordPress fatal error"
if grep -Eqi 'There has been a critical error|Fatal error:|Parse error:' /tmp/cvd-front.html /tmp/cvd-tariffs.html; then
  echo "A public page contains a WordPress/PHP fatal error marker" >&2
  exit 1
fi

echo "Staging smoke checks passed for $base_url"
