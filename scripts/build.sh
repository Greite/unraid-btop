#!/usr/bin/env bash
# Builds unraid.btop-<version>-x86_64-1.txz from the official btop release
# plus the source/ webgui tree. Leaves dist/pkgroot/ in place for smoke tests.
set -euo pipefail
BTOP_VERSION="${1:?usage: build.sh <btop-version> [plugin-version]}"
PLUGIN_VERSION="${2:-$(date -u +%Y.%m.%d)}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
PKG="unraid.btop-${PLUGIN_VERSION}-x86_64-1"
ASSET="btop-x86_64-unknown-linux-musl.tar.gz"
URL="https://github.com/aristocratos/btop/releases/download/v${BTOP_VERSION}/${ASSET}"

mkdir -p "$DIST/cache"
TARBALL="$DIST/cache/btop-${BTOP_VERSION}.tar.gz"
if [ ! -f "$TARBALL" ]; then
  echo "Downloading $URL"
  curl -fSL -o "$TARBALL" "$URL"
fi

# Record the SHA256 of the upstream artifact (upstream publishes no checksums)
if command -v sha256sum >/dev/null; then
  sha256sum "$TARBALL" > "$DIST/btop-${BTOP_VERSION}.sha256"
else
  shasum -a 256 "$TARBALL" > "$DIST/btop-${BTOP_VERSION}.sha256"
fi

rm -rf "$DIST/cache/btop" "$DIST/pkgroot"
tar -xzf "$TARBALL" -C "$DIST/cache"
[ -f "$DIST/cache/btop/bin/btop" ] || { echo "unexpected upstream archive layout" >&2; exit 1; }

mkdir -p "$DIST/pkgroot/usr/bin" "$DIST/pkgroot/usr/share/btop/themes"
cp -a "$ROOT/source/btop/usr/." "$DIST/pkgroot/usr/"
cp "$DIST/cache/btop/bin/btop" "$DIST/pkgroot/usr/bin/btop"
chmod 755 "$DIST/pkgroot/usr/bin/btop"
cp "$DIST/cache/btop/themes/"*.theme "$DIST/pkgroot/usr/share/btop/themes/"
cp "$DIST/cache/btop/LICENSE" "$DIST/pkgroot/usr/share/btop/LICENSE"

# Slackware package: tar.xz, root-owned, entries prefixed with ./
cd "$DIST/pkgroot"
if tar --version 2>/dev/null | grep -q GNU; then
  tar --owner=0 --group=0 -cJf "$DIST/$PKG.txz" .
else
  tar --format ustar --uid 0 --gid 0 -cJf "$DIST/$PKG.txz" .
fi

cd "$DIST"
if command -v md5sum >/dev/null; then
  md5sum "$PKG.txz" > "$PKG.md5"
else
  echo "$(md5 -q "$PKG.txz")  $PKG.txz" > "$PKG.md5"
fi

echo "Built: dist/$PKG.txz (btop v${BTOP_VERSION})"
