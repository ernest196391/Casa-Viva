#!/usr/bin/env bash
set -euo pipefail

repo="ernest196391/Casa-Viva"
plugin_dir="wordpress/casa-viva-dropship-core"
plugin_slug="casa-viva-dropship-core"
out_dir="${1:-release}"

sha="$(git rev-parse HEAD)"
branch="$(git branch --show-current || true)"
if [[ "${GITHUB_REF_NAME:-$branch}" != "main" ]]; then
  echo "Release candidates must be built from main; current ref: ${GITHUB_REF_NAME:-$branch}" >&2
  exit 1
fi

plugin_version="$(sed -n "s/^define( 'CVD_VERSION', '\([^']*\)' );$/\1/p" "$plugin_dir/casa-viva-dropship-core.php")"
if [[ -z "$plugin_version" ]]; then
  echo "Unable to determine CVD_VERSION" >&2
  exit 1
fi

mkdir -p "$out_dir"
archive="$out_dir/${plugin_slug}-${plugin_version}-${sha:0:12}.zip"
manifest="$out_dir/release-manifest.json"
checksums="$out_dir/SHA256SUMS"

git archive --format=zip --prefix="${plugin_slug}/" -o "$archive" "$sha:$plugin_dir"
archive_sha="$(sha256sum "$archive" | awk '{print $1}')"
archive_name="$(basename "$archive")"

cat > "$manifest" <<JSON
{
  "schema": 1,
  "repository": "$repo",
  "source_sha": "$sha",
  "source_ref": "main",
  "component": "$plugin_slug",
  "plugin_version": "$plugin_version",
  "archive": "$archive_name",
  "archive_sha256": "$archive_sha"
}
JSON

printf '%s  %s\n' "$archive_sha" "$archive_name" > "$checksums"
sha256sum "$manifest" >> "$checksums"

echo "Built $archive"
echo "Source SHA: $sha"
echo "Plugin version: $plugin_version"
echo "Archive SHA-256: $archive_sha"
