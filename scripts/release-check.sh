#!/usr/bin/env bash
# Check that a release tag is releasable, before anything is tagged, built or published:
#   - the tag is v<header Version>, e.g. v0.1.0 for "Version: 0.1.0"
#   - the version isn't a -dev build
#   - plugin/readme.txt, if there is one, has "Stable tag: <Version>"
#   - CHANGELOG.md has a non-empty section for the version
#
# Usage: scripts/release-check.sh <tag>      e.g. scripts/release-check.sh v0.1.0
# Both release paths run this: release-on-merge.yml before it creates the tag, and release.yml on a
# hand-pushed v* tag. Run it locally on a version-bump branch before opening the PR.
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
		echo "error: Version $version is a development version; drop -dev before releasing" >&2
		exit 1
		;;
esac

readme=plugin/readme.txt
if [ -f "$readme" ]; then
	stable=$(sed -n 's/^[[:space:]]*Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' "$readme" | head -n 1)
	if [ "$stable" != "$version" ]; then
		echo "error: $readme has 'Stable tag: ${stable:-(none)}', but the plugin header has Version: $version" >&2
		exit 1
	fi
fi

scripts/changelog-section.sh "$version" >/dev/null

echo "ok: $tag matches Version $version${stable:+ and Stable tag}, and CHANGELOG.md has a section for it"
