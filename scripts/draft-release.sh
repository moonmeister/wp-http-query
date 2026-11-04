#!/usr/bin/env bash
# Build the plugin zip and create a DRAFT GitHub release for an existing tag, with the zip attached
# and the version's CHANGELOG.md section as the notes. A maintainer reviews and publishes the draft.
#
# Usage: scripts/draft-release.sh <tag>      e.g. scripts/draft-release.sh v0.1.0
# Both release paths end here, so the release they make is the same: release-on-merge.yml (after
# it pushes the tag) and release.yml (on a hand-pushed tag). Needs `gh` with GH_TOKEN set, and the
# tag already on GitHub (--verify-tag refuses to create one).
set -euo pipefail

cd "$(dirname "$0")/.."

tag=${1:?usage: scripts/draft-release.sh <tag>}

scripts/release-check.sh "$tag"
version=$(scripts/plugin-version.sh)

make dist

notes=$(mktemp)
trap 'rm -f "$notes"' EXIT
scripts/changelog-section.sh "$version" > "$notes"

prerelease=()
case "$version" in *-*) prerelease=(--prerelease) ;; esac

gh release create "$tag" "dist/wp-http-query-$version.zip" \
	--draft \
	--verify-tag \
	--title "$tag" \
	--notes-file "$notes" \
	${prerelease[@]+"${prerelease[@]}"}
