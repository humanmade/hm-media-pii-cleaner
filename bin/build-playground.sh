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
placeholder="{{ARTIFACT_URL:$slug}}"
zip_url="${1:-$placeholder}"
version="dev-$(git rev-parse --short HEAD 2>/dev/null || echo local)"

bash bin/build-zip.sh --with-samples --version="$version"
php bin/playground-blueprint.php "$zip_url" > build/blueprint.json

echo "Built build/blueprint.json"
