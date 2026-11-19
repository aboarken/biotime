#!/usr/bin/env bash
# Copies the PDF library (mPDF and the classes it needs) into administrator/vendor: only the code,
# its data, one font family (DejaVu Sans Condensed: Latin and Arabic) and the licences. psr/log and
# psr/http-message are not copied: Joomla has them. Versions: build/pdf/composer.json (+ .lock).
# Usage: build/vendor-pdf.sh   (run from anywhere; needs composer)
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cp "$root/build/pdf/composer.json" "$work/"
[ -f "$root/build/pdf/composer.lock" ] && cp "$root/build/pdf/composer.lock" "$work/"
composer install --working-dir="$work" --no-dev --no-interaction --no-progress --prefer-dist -q
cp "$work/composer.lock" "$root/build/pdf/composer.lock"

dest="$root/administrator/vendor"
rm -rf "$dest"
mkdir -p "$dest"
v="$work/vendor"

copy() { # package, then the paths inside it to keep
    local pkg="$1"; shift
    mkdir -p "$dest/$pkg"
    for path in "$@"; do
        mkdir -p "$dest/$pkg/$(dirname "$path")"
        cp -R "$v/$pkg/$path" "$dest/$pkg/$path"
    done
}

copy mpdf/mpdf src data LICENSE.txt
mkdir -p "$dest/mpdf/mpdf/ttfonts"
cp "$v"/mpdf/mpdf/ttfonts/DejaVuSansCondensed.ttf "$v"/mpdf/mpdf/ttfonts/DejaVuSansCondensed-Bold.ttf \
   "$v"/mpdf/mpdf/ttfonts/DejaVuSansCondensed-Oblique.ttf "$v"/mpdf/mpdf/ttfonts/DejaVuSansCondensed-BoldOblique.ttf \
   "$v"/mpdf/mpdf/ttfonts/DejaVuinfo.txt "$dest/mpdf/mpdf/ttfonts/"
copy mpdf/psr-log-aware-trait src composer.json
copy mpdf/psr-http-message-shim src composer.json
copy myclabs/deep-copy src LICENSE
copy setasign/fpdi src LICENSE.txt
cp "$root/build/pdf/autoload.php" "$dest/autoload.php"

( cd "$work" && composer show --no-dev --format=text ) > "$dest/VERSIONS.txt"
du -sh "$dest"
