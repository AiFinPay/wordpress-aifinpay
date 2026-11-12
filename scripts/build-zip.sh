#!/usr/bin/env bash
# dist/aifinpay-<version>.zip: only what a site needs.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n "s/^define( 'AIFINPAY_VERSION', '\(.*\)' );$/\1/p" "$root/aifinpay.php")"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/aifinpay" "$root/dist"
cp -R "$root/src" "$root/aifinpay.php" "$root/uninstall.php" "$root/readme.txt" "$root/LICENSE.txt" "$stage/aifinpay/"
rm -f "$root/dist/aifinpay-$version.zip"
(cd "$stage" && zip -qr "$root/dist/aifinpay-$version.zip" aifinpay)
echo "$root/dist/aifinpay-$version.zip"
