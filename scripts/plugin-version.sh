#!/usr/bin/env bash
# Print the plugin's version, read from the `Version:` field of its header.
# The header is the only place the version is written down; everything else reads it from here.
#
# Usage: scripts/plugin-version.sh            the working tree
#        scripts/plugin-version.sh <commit>   the header as of a commit, e.g. HEAD~1
# Exits non-zero, with a message on stderr, if the field is missing or empty, or the commit has no
# plugin header.
set -euo pipefail

cd "$(dirname "$0")/.."

main=plugin/wp-http-query.php

if [ $# -gt 0 ]; then
	source=$(git show "$1:$main")
else
	source=$(cat "$main")
fi

# WordPress reads the header from the first 8 KiB of the file, so only look there.
# The first match wins, as in WordPress's get_file_data().
LC_ALL=C
version=$(printf '%s\n' "${source:0:8192}" |
	sed -n 's/^[[:space:]*#@]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' |
	head -n 1)

if [ -z "$version" ]; then
	echo "error: no 'Version:' in the plugin header of $main${1:+ at $1}" >&2
	exit 1
fi

printf '%s\n' "$version"
