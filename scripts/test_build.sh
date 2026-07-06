#!/usr/bin/env bash
# Builds the package with a fixed test version and asserts its contents.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLGVER="0000.00.00"
BTOPVER="$(cat "$ROOT/BTOP_VERSION")"

fail() { echo "FAIL: $1" >&2; exit 1; }

bash "$ROOT/scripts/build.sh" "$BTOPVER" "$PLGVER"

PKG="unraid.btop-${PLGVER}-x86_64-1"
TXZ="$ROOT/dist/$PKG.txz"
[ -f "$TXZ" ] || fail "missing $TXZ"
[ -f "$ROOT/dist/$PKG.md5" ] || fail "missing $PKG.md5"
[ -f "$ROOT/dist/btop-${BTOPVER}.sha256" ] || fail "missing sha256 record"

LIST="$(tar -tf "$TXZ")"
echo "$LIST" | grep -q '^\./usr/bin/btop$' || fail "btop binary missing from package"
echo "$LIST" | grep -q '^\./usr/share/btop/themes/.*\.theme$' || fail "themes missing from package"
echo "$LIST" | grep -q '^\./usr/share/btop/LICENSE$' || fail "upstream LICENSE missing"

# Every file of the source tree must be in the package
for f in $(cd "$ROOT/source/btop" && find usr -type f); do
  echo "$LIST" | grep -q "^\./$f\$" || fail "source file missing from package: $f"
done

# MD5 file must match (md5sum on Linux, md5 on macOS)
cd "$ROOT/dist"
if command -v md5sum >/dev/null; then
  md5sum -c "$PKG.md5" >/dev/null || fail "md5 mismatch"
else
  expected="$(awk '{print $1}' "$PKG.md5")"
  actual="$(md5 -q "$PKG.txz")"
  [ "$expected" = "$actual" ] || fail "md5 mismatch"
fi

# pkgroot must be left in place (used by the CI smoke test)
[ -x "$ROOT/dist/pkgroot/usr/bin/btop" ] || fail "dist/pkgroot not left in place"

echo "OK: package contents verified"
