#!/usr/bin/env bash
# Decide whether a push to main is a release: did it change the plugin header's Version to a
# non -dev version that has no tag yet?
#
# Usage: scripts/release-detect.sh <before> <after>
#   before  the commit main pointed at before the push (github.event.before). All zeros or empty
#           when there was none (main was just created).
#   after   the commit main points at now (github.sha).
#
# Prints release=true|false and version=<after's Version>, and appends them to $GITHUB_OUTPUT
# when that's set. Exits 0 either way; non-zero only if it can't read the version at all.
#
# A push is a release when all of these hold:
#   1. after's Version isn't a -dev version;
#   2. there's no v<Version> tag, locally or on origin;
#   3. before's Version differs from after's. If before is unknown (a first push, or a force-push
#      whose old commit isn't in this clone) this check is skipped and 1 and 2 decide.
# The tag check is what makes a re-run, or a push that re-lands an already released version, a
# no-op. The before check stops a release from being cut by an unrelated push when an earlier
# release attempt left a non -dev Version untagged; release that one by hand (RELEASING.md).
set -euo pipefail

cd "$(dirname "$0")/.."

before=${1-}
after=${2:?usage: scripts/release-detect.sh <before> <after>}

version=$(scripts/plugin-version.sh "$after")

decide() {
	echo "$2" >&2
	printf 'release=%s\nversion=%s\n' "$1" "$version" | tee -a "${GITHUB_OUTPUT:-/dev/null}"
	exit 0
}

case "$version" in
	*-dev*) decide false "no release: Version $version is a development version" ;;
esac

if git rev-parse -q --verify "refs/tags/v$version" >/dev/null; then
	decide false "no release: tag v$version already exists"
fi
if git remote get-url origin >/dev/null 2>&1; then
	on_origin=$(git ls-remote --tags origin "refs/tags/v$version")
	if [ -n "$on_origin" ]; then
		decide false "no release: tag v$version already exists on origin"
	fi
fi

if [ -z "$before" ] || [ -z "${before//0/}" ]; then
	echo "note: no previous commit (first push to the branch); deciding on the tag alone" >&2
elif ! git cat-file -e "$before^{commit}" 2>/dev/null; then
	echo "note: previous commit $before isn't in this clone (force-push?); deciding on the tag alone" >&2
else
	before_version=$(scripts/plugin-version.sh "$before" 2>/dev/null || true)
	if [ "$before_version" = "$version" ]; then
		decide false "no release: Version is unchanged ($version)"
	fi
	echo "note: Version changed from ${before_version:-(none)} to $version" >&2
fi

decide true "release: v$version"
