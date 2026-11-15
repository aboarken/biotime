#!/usr/bin/env bash
# Refreshes the bundled TCPDF in administrator/lib/tcpdf (PDF exports). Run by a maintainer only;
# the package never downloads anything at runtime.
#   build/update-tcpdf.sh [version-constraint]   (default ^6.10)
# Keeps only what the exports need: tcpdf.php, include/, config/, DejaVu Sans (regular and bold,
# covers Arabic and other scripts) and Helvetica metrics, plus the licence.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
(cd "$tmp" && printf '{"require":{"tecnickcom/tcpdf":"%s"}}' "${1:-^6.10}" > composer.json && composer install -q --no-interaction --no-dev)
src="$tmp/vendor/tecnickcom/tcpdf"; dst="$root/administrator/lib/tcpdf"
rm -rf "$dst"; mkdir -p "$dst/fonts"
cp "$src"/{tcpdf.php,tcpdf_autoconfig.php,LICENSE.TXT,VERSION,README.md} "$dst/"
cp -r "$src/include" "$src/config" "$dst/"
cp "$src"/fonts/dejavusans.* "$src"/fonts/dejavusansb.* "$src"/fonts/helvetica.php "$src"/fonts/helveticab.php "$dst/fonts/"
echo "TCPDF $(cat "$dst/VERSION") bundled in administrator/lib/tcpdf"
