#!/usr/bin/env bash
# Check that a release tag is releasable, before anything is built or published:
#   - the tag is v<header Version>, e.g. v0.1.0 for "Version: 0.1.0"
#   - the version isn't a -dev build
#   - CHANGELOG.md has a non-empty section for the version
#
# Usage: scripts/release-check.sh <tag>      e.g. scripts/release-check.sh v0.1.0
# The release workflow runs this on every pushed v* tag. Run it locally before tagging.
set -euo pipefail

cd "$(dirname "$0")/.."

tag=${1:?usage: scripts/release-check.sh <tag>}
version=$(scripts/plugin-version.sh)

if [ "$tag" != "v$version" ]; then
	echo "error: tag $tag doesn't match the plugin header's Version: $version (expected tag v$version)" >&2
	exit 1
fi

case "$version" in
	*-dev*)
		echo "error: Version $version is a development version; drop -dev before tagging" >&2
		exit 1
		;;
esac

scripts/changelog-section.sh "$version" >/dev/null

echo "ok: $tag matches Version $version, and CHANGELOG.md has a section for it"
