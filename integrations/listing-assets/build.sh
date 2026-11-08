#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
brand="$root/web/landing/site/src/assets/brand"
im="$(command -v magick || true)"
if [ -z "$im" ]; then
  echo "ImageMagick 7 (magick) is required: brew install imagemagick" >&2
  exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

"$im" "$brand/logo-dark.png" -crop 531x470+0+0 +repage -background none -gravity center -extent 540x540 "$work/mark.png"

icon() {
  mkdir -p "$(dirname "$2")"
  "$im" "$work/mark.png" -resize "${1}x${1}" -background white -alpha remove -alpha off -depth 8 -strip "$2"
}

banner() {
  mkdir -p "$(dirname "$3")"
  local logo_w=$(( $1 * 5 / 10 ))
  "$im" -size "${1}x${2}" "xc:#FDFDFE" \
    \( "$brand/logo-dark.png" -resize "${logo_w}x" \) -gravity center -composite \
    -depth 8 -strip "$3"
}

wp="$root/integrations/woocommerce/.wordpress-org"
icon 128 "$wp/icon-128x128.png"
icon 256 "$wp/icon-256x256.png"
banner 772 250 "$wp/banner-772x250.png"
banner 1544 500 "$wp/banner-1544x500.png"

icon 128 "$root/integrations/prestashop/suawave/logo.png"

icon 512 "$root/integrations/magento/listing/logo-512x512.png"
banner 1200 628 "$root/integrations/magento/listing/banner-1200x628.png"

icon 512 "$root/integrations/opencart/listing/icon-512x512.png"
banner 1200 630 "$root/integrations/opencart/listing/banner-1200x630.png"
