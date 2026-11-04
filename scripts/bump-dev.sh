#!/usr/bin/env bash
# Start the next development version after a release: X.Y.Z becomes X.(Y+1).0-dev.
#   - plugin/wp-http-query.php: "Version: X.(Y+1).0-dev"
#   - plugin/readme.txt, if there is one: "Stable tag: X.(Y+1).0-dev"
#   - CHANGELOG.md: an empty "## [Unreleased]" section above "## [X.Y.Z]" (unless there already
#     is one), "[Unreleased]" linked to the compare view from vX.Y.Z, and a "[X.Y.Z]" link to the tag
#
# Usage: scripts/bump-dev.sh <released-version>    e.g. scripts/bump-dev.sh 0.1.0
# Prints the new version. release-on-merge.yml runs this for the follow-up PR after a release.
set -euo pipefail

cd "$(dirname "$0")/.."

released=${1:?usage: scripts/bump-dev.sh <released-version>}

if ! [[ $released =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
	echo "error: '$released' isn't a plain X.Y.Z version" >&2
	exit 1
fi
next="${BASH_REMATCH[1]}.$((BASH_REMATCH[2] + 1)).0-dev"

repo=https://github.com/moonmeister/wp-http-query
export RELEASED=$released NEXT=$next REPO=$repo

# The first "Version:" line is the header's (scripts/plugin-version.sh reads the same one).
perl -pi -e 's/^([\s*#@]*Version:\s*)\S+/$1$ENV{NEXT}/ && ($done = 1) unless $done' \
	plugin/wp-http-query.php

if [ -f plugin/readme.txt ]; then
	perl -pi -e 's/^(\s*Stable tag:\s*)\S+/$1$ENV{NEXT}/ && ($done = 1) unless $done' plugin/readme.txt
fi

perl -0pi -e '
	my $released = quotemeta $ENV{RELEASED};
	# An empty Unreleased section above the first version heading, unless there is one.
	s/^(## \[)/## [Unreleased]\n\n$1/m unless /^## \[Unreleased\]/m;
	# [Unreleased] compares from the new tag.
	my $unreleased = "[Unreleased]: $ENV{REPO}/compare/v$ENV{RELEASED}...HEAD";
	s/^\[Unreleased\]: .*$/$unreleased/m or s/\n*\z/\n\n$unreleased\n/;
	# [X.Y.Z] links to the tag.
	s/^(\[Unreleased\]: .*\n)/$1\[$ENV{RELEASED}\]: $ENV{REPO}\/releases\/tag\/v$ENV{RELEASED}\n/m
		unless /^\[$released\]: /m;
' CHANGELOG.md

if [ "$(scripts/plugin-version.sh)" != "$next" ]; then
	echo "error: failed to set Version: $next in the plugin header" >&2
	exit 1
fi

printf '%s\n' "$next"
