#!/usr/bin/env bash
# php -l on every .php file, and on the PHP part of every .page file
# (.page = header lines, a --- separator, then PHP/HTML).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

for f in $(find "$ROOT/source" -name '*.php'); do
  php -l "$f" >/dev/null
done

for f in $(find "$ROOT/source" -name '*.page'); do
  tmp="$(mktemp)"
  awk 'found{print} /^---$/{found=1}' "$f" > "$tmp"
  php -l "$tmp" >/dev/null || { echo "PHP syntax error in $f" >&2; rm -f "$tmp"; exit 1; }
  rm -f "$tmp"
done

echo "PHP syntax OK"
