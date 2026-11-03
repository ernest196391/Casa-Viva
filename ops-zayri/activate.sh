#!/usr/bin/env bash
set -euo pipefail
umask 077
stage=$1
root=/home/u824654880/domains/casaviva.company/public_html
plugin="$root/wp-content/plugins/casa-viva-dropship-core"
files=(assets/product-background.js assets/content-studio.js includes/class-cvd-photo-ai.php includes/class-cvd-content-studio.php)
cd "$plugin"
exec 9>"$HOME/.cvd-fotos-activate.lock"
flock -w 15 9
snapshot() {
 find . -type f ! -path './assets/product-background.js' ! -path './assets/content-studio.js' ! -path './includes/class-cvd-photo-ai.php' ! -path './includes/class-cvd-content-studio.php' -print0 | sort -z | xargs -0 sha256sum
}
rollback() {
  for f in "${files[@]}"; do
    if [ -f "$stage/old/$f" ]; then cp -p "$stage/old/$f" "$f.cvd-rollback" && mv -f "$f.cvd-rollback" "$f"
    elif [ -f "$stage/absent-background" ] && [ "$f" = assets/product-background.js ]; then rm -f "$f"; fi
  done
}
if [ "${2:-apply}" = rollback ]; then
  test -f "$stage/applied" || exit 0
  sha256sum -c "$stage/target.sha" >/dev/null
  rollback
  exit
fi
snapshot > "$stage/other-before.sha"
if sha256sum -c "$stage/target.sha" >/dev/null 2>&1; then echo 'Los cuatro archivos ya estaban activos.'; exit 0; fi
sha256sum -c "$stage/baseline.sha"
test ! -e assets/product-background.js
touch "$stage/absent-background"
for f in "${files[@]}"; do
  test ! -L "$f"
  mkdir -p "$stage/old/$(dirname "$f")"
  if [ -f "$f" ]; then cp -p "$f" "$stage/old/$f"; fi
done
for f in "$stage"/new/includes/*.php; do php -l "$f"; done
trap 'rollback' ERR
for f in "${files[@]}"; do
 install -m 644 "$stage/new/$f" "$f.cvd-new"
 mv -f "$f.cvd-new" "$f"
done
sha256sum -c "$stage/target.sha"
snapshot > "$stage/other-after.sha"
cmp "$stage/other-before.sha" "$stage/other-after.sha"
cd "$root"
if ! wp eval 'if (!CVD_Photo_AI::providers()["cloudflare"] || !isset(rest_get_server()->get_routes()["/casa-viva/v1/contenido/review"])) {exit(1);}'; then cd "$plugin"; false; fi
touch "$stage/applied"
trap - ERR
echo 'Verificados todos los archivos del plugin fuera del panel, incluidos el tablero y Pa’llá.'
