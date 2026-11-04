#!/usr/bin/env bash
# Print the body of one version's section of CHANGELOG.md, for release notes.
#
# Usage: scripts/changelog-section.sh <version>      e.g. 0.1.0
# The section starts at the "## [<version>]" heading and ends before the next "## " heading or
# the link references at the bottom. Exits non-zero if there's no such section, or it's empty.
set -euo pipefail

cd "$(dirname "$0")/.."

version=${1:?usage: scripts/changelog-section.sh <version>}

notes=$(awk -v want="## [$version]" '
	index($0, want) == 1 { found = 1; next }
	found && /^## / { exit }
	found && /^\[[^]]+\]: / { exit }
	found { lines[++n] = $0; if ($0 ~ /[^[:space:]]/) { last = n; if (!first) first = n } }
	END { for (i = first; first && i <= last; i++) print lines[i] }
' CHANGELOG.md)

if [ -z "$notes" ]; then
	echo "error: CHANGELOG.md has no '## [$version]' section, or it's empty" >&2
	exit 1
fi

printf '%s\n' "$notes"
