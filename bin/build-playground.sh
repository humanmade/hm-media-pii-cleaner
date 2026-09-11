#!/usr/bin/env bash
#
# Build the plugin zip (with production vendor/ and the Playground sample
# media) and the matching blueprint into build/.
#
# Usage: bin/build-playground.sh [zip-url]
# Without a URL the blueprint keeps the {{ARTIFACT_URL:...}} placeholder that
# the PR preview publish workflow fills in.

set -euo pipefail

cd "$(dirname "$0")/.."

slug="hm-media-pii-cleaner"
out="build"
stage="$out/$slug"
placeholder="{{ARTIFACT_URL:$slug}}"
zip_url="${1:-$placeholder}"

rm -rf "$out"
mkdir -p "$stage/tests/fixtures/playground"

cp -R "$slug.php" inc composer.json README.md LIMITATIONS.md "$stage/"
cp tests/fixtures/playground/*.jpg tests/fixtures/playground/*.png tests/fixtures/playground/*.pdf "$stage/tests/fixtures/playground/"

composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --no-progress
rm -f "$stage/composer.lock"

( cd "$out" && zip -qr "$slug.zip" "$slug" )
php bin/playground-blueprint.php "$zip_url" > "$out/blueprint.json"

echo "Built $out/$slug.zip and $out/blueprint.json"
