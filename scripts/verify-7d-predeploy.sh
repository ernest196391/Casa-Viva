#!/usr/bin/env bash
set -euo pipefail

expected_sha="${1:-}"
if [[ -z "$expected_sha" ]]; then
  echo "Usage: $0 <expected-main-sha>" >&2
  exit 2
fi

actual_sha="$(git rev-parse HEAD)"
if [[ "$actual_sha" != "$expected_sha" ]]; then
  echo "HEAD mismatch: expected $expected_sha, got $actual_sha" >&2
  exit 1
fi

git fetch origin main --depth=200 >/dev/null 2>&1 || true
if ! git merge-base --is-ancestor "$expected_sha" origin/main; then
  echo "Expected SHA is not an ancestor of origin/main: $expected_sha" >&2
  exit 1
fi

out_dir="$(mktemp -d)"
trap 'rm -rf "$out_dir"' EXIT

GITHUB_REF_NAME=main bash scripts/build-release-candidate.sh "$out_dir"
(
  cd "$out_dir"
  sha256sum -c SHA256SUMS
)

manifest_sha="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $m["source_sha"] ?? "";' "$out_dir/release-manifest.json")"
if [[ "$manifest_sha" != "$expected_sha" ]]; then
  echo "Manifest source_sha mismatch: expected $expected_sha, got $manifest_sha" >&2
  exit 1
fi

archive="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $m["archive"] ?? "";' "$out_dir/release-manifest.json")"
if [[ -z "$archive" || ! -f "$out_dir/$archive" ]]; then
  echo "Release archive missing" >&2
  exit 1
fi

if [[ ! -x scripts/smoke-staging.sh ]]; then
  echo "Smoke script is not executable: scripts/smoke-staging.sh" >&2
  exit 1
fi

bash -n scripts/smoke-staging.sh
bash -n scripts/build-release-candidate.sh

echo "7D PREDEPLOY GATE: OK"
echo "Certified SHA: $expected_sha"
echo "Release manifest: $out_dir/release-manifest.json"
