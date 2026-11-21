#!/usr/bin/env bash
#
# Print the CHANGELOG.md section for a version, e.g. 0.12.1.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:-${GITHUB_REF_NAME#v}}"

if [[ -z "${VERSION}" ]]; then
	echo "Usage: bin/release-notes.sh <version>" >&2
	exit 1
fi

NOTES=$(awk -v version="${VERSION}" '
	$0 == "## " version { found = 1; next }
	found && /^## / { exit }
	found { print }
' CHANGELOG.md)

if [[ -z "${NOTES//[[:space:]]/}" ]]; then
	echo "No CHANGELOG.md section found for ${VERSION}." >&2
	exit 1
fi

printf '%s\n' "${NOTES}" | sed '/./,$!d'
