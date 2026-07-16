#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$ROOT/echo-platform"
BUILD="$ROOT/build"
VERSION="$(grep -m1 -E '^ \* Version:' "$PLUGIN/echo-motorworks-core.php" | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]*$//')"
[ -n "$VERSION" ] || VERSION="dev"
OUT="$BUILD/echo-platform-os-v${VERSION}.zip"
rm -f "$OUT"
mkdir -p "$BUILD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp -R "$PLUGIN" "$TMP/echo-motorworks-core"
(cd "$TMP" && zip -qr "$OUT" echo-motorworks-core)
echo "Built: $OUT"
