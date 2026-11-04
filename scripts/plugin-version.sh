#!/usr/bin/env bash
# Print the plugin's version, read from the `Version:` field of its header.
# On main that's always 0.0.0-dev: release versions are stamped into the zip at build time
# (scripts/dist.sh), never committed. dist.sh and pr-preview-build.yml use this for the default.
#
# Usage: scripts/plugin-version.sh
# Exits non-zero, with a message on stderr, if the field is missing or empty.
set -euo pipefail

cd "$(dirname "$0")/.."

main=plugin/wp-http-query.php

# WordPress reads the header from the first 8 KiB of the file, so only look there.
# The first match wins, as in WordPress's get_file_data().
version=$(head -c 8192 "$main" |
	sed -n 's/^[[:space:]*#@]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' |
	head -n 1)

if [ -z "$version" ]; then
	echo "error: no 'Version:' in the plugin header of $main" >&2
	exit 1
fi

printf '%s\n' "$version"
