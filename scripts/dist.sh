#!/usr/bin/env bash
# Build the installable plugin zip: dist/wp-http-query-<version>.zip, holding a
# single top-level folder, wp-http-query/, with exactly the files a site needs.
#
# Usage: scripts/dist.sh   (or: make dist)
#
# What ships is an explicit list: SHIP, plus SHIP_IF_PRESENT when those files exist.
# Every file in plugin/ must be on one of those or on DEV_ONLY, or the build fails:
# adding a file to plugin/ means deciding whether sites get it.
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG=wp-http-query

# Files copied into the zip, relative to plugin/. The build fails if one is missing.
SHIP=(
	wp-http-query.php
	uninstall.php
	README.md
	LICENSE
	assets/self-test.js
)

# Files copied into the zip when they exist, relative to plugin/.
SHIP_IF_PRESENT=(
	readme.txt
)

# Files in plugin/ that deliberately stay out of the zip, relative to plugin/.
DEV_ONLY=(
)

version=$(scripts/plugin-version.sh)

# Every file in plugin/ must be classified. (.DS_Store is never interesting.)
unlisted=()
while IFS= read -r file; do
	file=${file#plugin/}
	listed=no
	for known in "${SHIP[@]}" "${SHIP_IF_PRESENT[@]}" ${DEV_ONLY[@]+"${DEV_ONLY[@]}"}; do
		if [ "$file" = "$known" ]; then
			listed=yes
			break
		fi
	done
	if [ "$listed" = no ]; then
		unlisted+=("$file")
	fi
done < <(find plugin -type f ! -name .DS_Store | LC_ALL=C sort)

if [ ${#unlisted[@]} -gt 0 ]; then
	echo "error: plugin/ has files that are neither shipped nor marked dev-only:" >&2
	printf '  %s\n' "${unlisted[@]}" >&2
	echo "Add each to SHIP or DEV_ONLY in scripts/dist.sh." >&2
	exit 1
fi

for file in "${SHIP[@]}"; do
	if [ ! -f "plugin/$file" ]; then
		echo "error: plugin/$file is listed in SHIP but doesn't exist" >&2
		exit 1
	fi
done

files=("${SHIP[@]}")
for file in "${SHIP_IF_PRESENT[@]}"; do
	if [ -f "plugin/$file" ]; then
		files+=("$file")
	fi
done

zip_name="$SLUG-$version.zip"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/$SLUG" dist
for file in "${files[@]}"; do
	mkdir -p "$stage/$SLUG/$(dirname "$file")"
	cp "plugin/$file" "$stage/$SLUG/$file"
done

out="$PWD/dist/$zip_name"
rm -f "$out"
# -X: leave out extra file attributes (uid/gid, macOS metadata).
(cd "$stage" && zip -q -r -X "$out" "$SLUG")

echo "dist/$zip_name"
