#!/usr/bin/env bash
# Decide whether the nightly prerelease needs rebuilding. Prints `build` or `skip` on stdout,
# and the reason on stderr. Used by .github/workflows/nightly.yml.
#
# Usage: scripts/nightly-should-build.sh <owner/repo> <main HEAD commit SHA>
# Needs `gh` with a token that can read the repo's releases and refs.
#
# It skips only when everything already matches main's HEAD:
#   - the `nightly` release exists, is published, and has the nightly zip attached, and
#   - its tag, or failing that the release's target, is the HEAD commit.
# Anything else, including a failed or unexpected lookup, means build: a needless rebuild is
# cheap, and a skipped one leaves a stale nightly.
#
# The tag is trusted as "this commit's zip is up" because the workflow moves it only after the
# upload succeeded (see the comment above its upload step). A run that fails before then leaves
# the tag behind HEAD, so the next run builds.
#
# A draft Nightly, left by a first run that failed before publishing, means build. The
# releases/tags endpoint returns published releases only, so a draft reads as no release; the
# .draft check below covers it should that ever change.
set -euo pipefail

repo=${1:?usage: scripts/nightly-should-build.sh <owner/repo> <head sha>}
head=${2:?usage: scripts/nightly-should-build.sh <owner/repo> <head sha>}
asset=wp-http-query-nightly.zip

lower() { tr '[:upper:]' '[:lower:]'; }
is_sha() { [[ $1 =~ ^[0-9a-f]{40}$ ]]; }

head=$(printf '%s' "$head" | lower)
if ! is_sha "$head"; then
	echo "error: '$2' isn't a full 40-character commit SHA" >&2
	exit 1
fi

build() {
	echo "build: $*" >&2
	echo build
	exit 0
}

# The release, its target and its assets.
if ! release=$(gh api "repos/$repo/releases/tags/nightly" 2>/dev/null); then
	build "no published nightly release (none yet, a draft, or it couldn't be read)"
fi
if [ "$(jq -r '.draft // false' <<<"$release" 2>/dev/null)" != false ]; then
	build "the nightly release is still a draft"
fi
target=$(jq -r '.target_commitish // ""' <<<"$release" 2>/dev/null | lower) || target=
if ! jq -e --arg name "$asset" 'any(.assets[]?; .name == $name and .state == "uploaded")' \
	<<<"$release" >/dev/null 2>&1; then
	build "the nightly release has no $asset"
fi

# The tag, peeled to a commit if it's an annotated tag.
tag_sha=
if ref=$(gh api "repos/$repo/git/ref/tags/nightly" 2>/dev/null); then
	type=$(jq -r '.object.type // ""' <<<"$ref" 2>/dev/null) || type=
	sha=$(jq -r '.object.sha // ""' <<<"$ref" 2>/dev/null | lower) || sha=
	if [ "$type" = tag ] && is_sha "$sha"; then
		sha=$(gh api "repos/$repo/git/tags/$sha" --jq '.object.sha' 2>/dev/null | lower) || sha=
	fi
	if [ "$type" = commit ] || [ "$type" = tag ]; then
		tag_sha=$sha
	fi
fi

if is_sha "$tag_sha"; then
	if [ "$tag_sha" = "$head" ]; then
		echo "skip: the nightly tag is already at $head" >&2
		echo skip
		exit 0
	fi
	build "the nightly tag is at $tag_sha, main is at $head"
fi

# No readable tag: fall back to the release's target, which only counts if it's a SHA
# (it can also be a branch name, which says nothing about the commit).
if is_sha "$target" && [ "$target" = "$head" ]; then
	echo "skip: the nightly release already targets $head (its tag couldn't be read)" >&2
	echo skip
	exit 0
fi

build "the nightly tag couldn't be read and the release's target ('$target') isn't $head"
