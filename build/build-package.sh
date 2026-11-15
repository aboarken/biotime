#!/usr/bin/env bash
# Builds the installable Joomla package: dist/com_zkbiotime-<version>.zip
# Usage: build/build-package.sh   (run from anywhere)
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$root/zkbiotime.xml" | head -n1)"
[ -n "$version" ] || { echo "No <version> in zkbiotime.xml" >&2; exit 1; }

out="$root/dist/com_zkbiotime-$version.zip"
mkdir -p "$root/dist"
rm -f "$out"

# Only what the manifest installs, plus the licence, in a stable sorted order.
cd "$root"
find zkbiotime.xml script.php LICENSE administrator site media -type f \
  ! -name '.DS_Store' ! -name '*.orig' | LC_ALL=C sort | zip -X -q "$out" -@

echo "$out"
