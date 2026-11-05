#!/usr/bin/env bash
# Decide whether the nightly prerelease needs rebuilding. Prints `build` or `skip` on stdout,
# and the reason on stderr. Used by .github/workflows/nightly.yml.
#
# Usage: scripts/nightly-should-build.sh <owner/repo> <main HEAD commit SHA>
# Needs `gh` with a token that can read the repo's releases.
#
# It skips only when the published `nightly` release has the nightly zip attached and the zip's
# label is the HEAD commit. The workflow sets the label in the same call that uploads the zip, so
# the label names the commit the attached zip was built from. Anything else, including a failed or
# unexpected lookup, means build: a needless rebuild is cheap, and a skipped one leaves a stale
# nightly.
set -euo pipefail

repo=${1:?usage: scripts/nightly-should-build.sh <owner/repo> <head sha>}
head=${2:?usage: scripts/nightly-should-build.sh <owner/repo> <head sha>}
asset=wp-http-query-nightly.zip

head=$(printf '%s' "$head" | tr '[:upper:]' '[:lower:]')
if ! [[ $head =~ ^[0-9a-f]{40}$ ]]; then
	echo "error: '$2' isn't a full 40-character commit SHA" >&2
	exit 1
fi

build() {
	echo "build: $*" >&2
	echo build
	exit 0
}

# Published releases only: a draft left by a failed first run reads as no release.
if ! release=$(gh api "repos/$repo/releases/tags/nightly" 2>/dev/null); then
	build "no published nightly release (none yet, a draft, or it couldn't be read)"
fi

if ! label=$(jq -er --arg name "$asset" \
	'first(.assets[]? | select(.name == $name and .state == "uploaded")) | .label // ""' \
	<<<"$release" 2>/dev/null); then
	build "the nightly release has no $asset"
fi

label=$(printf '%s' "$label" | tr '[:upper:]' '[:lower:]')
if [ "$label" != "$head" ]; then
	build "$asset is labelled '$label', main is at $head"
fi

echo "skip: $asset is already built from $head" >&2
echo skip
