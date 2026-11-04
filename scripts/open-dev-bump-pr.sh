#!/usr/bin/env bash
# After a release, open a PR against main that starts the next development version
# (scripts/bump-dev.sh: X.Y.Z becomes X.(Y+1).0-dev, plus an empty Unreleased CHANGELOG section).
#
# Usage: scripts/open-dev-bump-pr.sh <released-version>    e.g. scripts/open-dev-bump-pr.sh 0.1.0
# Run from a checkout of the release commit. Pushes branch release/start-<next> to origin and opens
# the PR with `gh` (GH_TOKEN). release-on-merge.yml runs this as its last step.
set -euo pipefail

cd "$(dirname "$0")/.."

released=${1:?usage: scripts/open-dev-bump-pr.sh <released-version>}
base=${BASE_BRANCH:-main}

next=$(scripts/bump-dev.sh "$released")
branch="release/start-$next"

files=(plugin/wp-http-query.php CHANGELOG.md)
if [ -f plugin/readme.txt ]; then
	files+=(plugin/readme.txt)
fi

git switch -c "$branch"
git add -- "${files[@]}"
git -c user.name='github-actions[bot]' \
	-c user.email='41898282+github-actions[bot]@users.noreply.github.com' \
	commit -q -m "chore: start $next" -m "v$released is tagged and drafted as a GitHub release."
git push origin "$branch"

body="v$released is tagged, and its draft release is waiting under Releases: review and publish it.

This PR starts the next development version: \`Version: $next\` in the plugin header (and
\`Stable tag\` in \`plugin/readme.txt\`), and an empty \`Unreleased\` section in \`CHANGELOG.md\`.

Opened by release-on-merge.yml with GITHUB_TOKEN, so no \`pull_request\` CI runs on it yet. To get
CI, push a commit to this branch, or close and reopen the PR. See RELEASING.md."

if ! gh pr create --base "$base" --head "$branch" --title "chore: start $next" --body "$body"; then
	echo "::error::Couldn't open the PR for $branch. Is 'Allow GitHub Actions to create and approve pull requests' enabled? The branch is pushed; open the PR by hand: https://github.com/${GITHUB_REPOSITORY:-moonmeister/wp-http-query}/compare/$base...$branch" >&2
	exit 1
fi
