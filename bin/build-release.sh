#!/usr/bin/env bash
#
# Build mms-membership-{version}.zip for a GitHub release.
# Requires: composer, rsync, zip. Intended for Linux/macOS and CI.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(sed -n "s/^ \* Version:[[:space:]]*//p" mms-membership.php | head -n 1 | tr -d '\r')

if [[ -z "${VERSION}" ]]; then
	echo "Could not read the plugin version." >&2
	exit 1
fi

echo "Building mms-membership ${VERSION}"

composer install --no-dev --optimize-autoloader --no-interaction

rm -rf dist
mkdir -p dist/mms-membership

rsync -a \
	--exclude '.git' \
	--exclude '.github' \
	--exclude 'bin' \
	--exclude 'dist' \
	--exclude 'vendor/bin' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude '.gitignore' \
	--exclude '.distignore' \
	--exclude 'CHANGELOG.md' \
	--exclude '*.zip' \
	./ dist/mms-membership/

(cd dist && zip -qr "mms-membership-${VERSION}.zip" mms-membership)

echo "Created dist/mms-membership-${VERSION}.zip"
