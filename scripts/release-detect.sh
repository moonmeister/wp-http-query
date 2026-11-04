#!/usr/bin/env bash
# Decide whether a push to main is a release: did it change the plugin header's Version to a
# non -dev version that has no tag yet?
#
# Usage: scripts/release-detect.sh <before> <after>
#   before  the commit main pointed at before the push (github.event.before). All zeros or empty
#           when there was none (main was just created).
#   after   the commit main points at now (github.sha).
#
# Prints release=true|false, version=<after's Version> and tagged=true|false, and appends them to
# $GITHUB_OUTPUT when that's set. Exits 0 either way; non-zero only if it can't read the version.
#
# A push is a release when all of these hold:
#   1. after's Version isn't a -dev version;
#   2. there's no v<Version> tag, locally or on origin;
#   3. before's Version differs from after's. If before is unknown (a first push, or a force-push
#      whose old commit isn't in this clone) this check is skipped and 1 and 2 decide.
# Exception to 2: if v<Version> already points at after, this is a re-run of a release that got
# as far as tagging, so it's release=true with tagged=true. The workflow then skips the tag step,
# and draft-release.sh and open-dev-bump-pr.sh skip whatever they already did.
# A tag on any other commit makes the push a no-op, so a push that re-lands an already released
# version never re-releases it. The before check stops a release from being cut by an unrelated
# push when an earlier release attempt left a non -dev Version untagged; release that one by hand
# (RELEASING.md).
set -euo pipefail

cd "$(dirname "$0")/.."

before=${1-}
after=${2:?usage: scripts/release-detect.sh <before> <after>}

version=$(scripts/plugin-version.sh "$after")

decide() {
	echo "$3" >&2
	printf 'release=%s\nversion=%s\ntagged=%s\n' "$1" "$version" "$2" |
		tee -a "${GITHUB_OUTPUT:-/dev/null}"
	exit 0
}

case "$version" in
	*-dev*) decide false false "no release: Version $version is a development version" ;;
esac

# The commit an existing v<Version> tag points at, here or on origin; empty if there's no tag.
tagged_commit=$(git rev-parse -q --verify "refs/tags/v$version^{commit}" || true)
where=
if [ -z "$tagged_commit" ] && git remote get-url origin >/dev/null 2>&1; then
	# An annotated tag also lists its peeled commit as refs/tags/<tag>^{}; prefer that line.
	tagged_commit=$(git ls-remote --tags origin "refs/tags/v$version" "refs/tags/v$version^{}" |
		awk '{ if ($2 ~ /\^\{\}$/) peeled = $1; else plain = $1 } END { print peeled ? peeled : plain }')
	where=" on origin"
fi
if [ -n "$tagged_commit" ]; then
	if [ "$tagged_commit" = "$(git rev-parse "$after^{commit}")" ]; then
		decide true true "release: v$version already tags this commit$where; resuming (re-run)"
	fi
	decide false false "no release: tag v$version already exists$where (on $tagged_commit)"
fi

if [ -z "$before" ] || [ -z "${before//0/}" ]; then
	echo "note: no previous commit (first push to the branch); deciding on the tag alone" >&2
elif ! git cat-file -e "$before^{commit}" 2>/dev/null; then
	echo "note: previous commit $before isn't in this clone (force-push?); deciding on the tag alone" >&2
else
	before_version=$(scripts/plugin-version.sh "$before" 2>/dev/null || true)
	if [ "$before_version" = "$version" ]; then
		decide false false "no release: Version is unchanged ($version)"
	fi
	echo "note: Version changed from ${before_version:-(none)} to $version" >&2
fi

decide true false "release: v$version"
