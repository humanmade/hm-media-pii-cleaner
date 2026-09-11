#!/usr/bin/env bash
#
# Build the installable plugin zip, with production vendor/, into
# build/hm-media-pii-cleaner.zip.
#
# Usage: bin/build-zip.sh [--version=<version>] [--with-samples]
#   --version       Replace the __VERSION__ placeholder in the zipped plugin header.
#   --with-samples  Include the Playground sample media from tests/fixtures/playground.

set -euo pipefail

cd "$(dirname "$0")/.."

slug="hm-media-pii-cleaner"
out="build"
stage="$out/$slug"
version=""
with_samples=0

for arg in "$@"; do
	case "$arg" in
		--version=*) version="${arg#--version=}" ;;
		--with-samples) with_samples=1 ;;
		*) echo "Unknown argument: $arg" >&2; exit 1 ;;
	esac
done

rm -rf "$out"
mkdir -p "$stage"

cp -R "$slug.php" inc composer.json README.md LIMITATIONS.md "$stage/"

if [ "$with_samples" = 1 ]; then
	mkdir -p "$stage/tests/fixtures/playground"
	cp tests/fixtures/playground/*.jpg tests/fixtures/playground/*.png tests/fixtures/playground/*.pdf "$stage/tests/fixtures/playground/"
fi

if [ -n "$version" ]; then
	sed -i.bak "s/__VERSION__/$version/" "$stage/$slug.php" && rm "$stage/$slug.php.bak"
fi

composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --no-progress
rm -f "$stage/composer.lock"

( cd "$out" && zip -qr "$slug.zip" "$slug" )

echo "Built $out/$slug.zip"
