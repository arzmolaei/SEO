#!/usr/bin/env bash
set -euo pipefail

VERSION="v33.003"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET_DIR="$ROOT/public/fonts/open/vazirmatn"
FONT_URL="https://raw.githubusercontent.com/rastikerdar/vazirmatn/${VERSION}/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2"
LICENSE_URL="https://raw.githubusercontent.com/rastikerdar/vazirmatn/${VERSION}/OFL.txt"

mkdir -p "$TARGET_DIR"

curl --fail --location --silent --show-error \
    "$FONT_URL" \
    --output "$TARGET_DIR/Vazirmatn[wght].woff2"

curl --fail --location --silent --show-error \
    "$LICENSE_URL" \
    --output "$TARGET_DIR/OFL.txt"

printf 'Vazirmatn %s bundled into %s\n' "$VERSION" "$TARGET_DIR"
