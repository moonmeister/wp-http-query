#!/usr/bin/env bash
# Build the installable plugin zip: dist/wp-http-query-<version>.zip, holding a
# single top-level folder, wp-http-query/, with exactly the files a site needs.
#
# Usage: scripts/dist.sh [version]      (or: make dist [VERSION=x.y.z])
#
# The version never lives on main: plugin/ always says 0.0.0-dev. Given a version (the argument,
# or the VERSION environment variable), the build stamps it into the COPY of the plugin it zips,
# in the header's `Version:`, the WP_HTTP_QUERY_VERSION constant and readme.txt's `Stable tag:`.
# plugin/ itself is never modified.
# Without one, the header's own value (0.0.0-dev) is used; that's what PR builds do.
# The version must be semver: x.y.z, optionally with a -prerelease and/or +build suffix,
# e.g. 0.1.0, 0.2.0-beta.1, 0.0.0-nightly.20261105+abc1234.
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
	includes/core-gaps.php
	includes/reach.php
	includes/settings.php
	includes/demo-route.php
	includes/diagnose.php
	includes/self-test.php
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

version=${1:-${VERSION:-}}
if [ -z "$version" ]; then
	version=$(scripts/plugin-version.sh)
fi

# Semantic Versioning 2.0.0: MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD].
# A prerelease identifier is numeric with no leading zero, or alphanumeric with at least one
# letter or hyphen (so 01 is out, 0a is in). Build identifiers have no leading-zero rule.
num='(0|[1-9][0-9]*)'
pre="($num|[0-9]*[A-Za-z-][0-9A-Za-z-]*)"
meta='[0-9A-Za-z-]+'
semver="^$num\\.$num\\.$num(-$pre(\\.$pre)*)?(\\+$meta(\\.$meta)*)?\$"
if ! [[ $version =~ $semver ]]; then
	echo "error: version '$version' isn't semver: x.y.z, optionally with -prerelease and/or +build" >&2
	echo "       (e.g. 0.1.0, 0.2.0-beta.1, 0.0.0-nightly.20261105+abc1234)" >&2
	exit 1
fi

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

# Stamp the version into the staged copy, never into plugin/. Only the first matching line
# changes (WordPress reads the first one too), and its alignment is kept.
# Usage: stamp <file relative to the staged plugin> <field name>
stamp() {
	local file="$stage/$SLUG/$1" field=$2
	if ! awk -v v="$version" -v field="$field" '
		!done && $0 ~ ("^[[:space:]*#@]*" field ":[[:space:]]*[^[:space:]]+[[:space:]]*$") {
			match($0, field ":[[:space:]]*")
			print substr($0, 1, RSTART + RLENGTH - 1) v
			done = 1
			next
		}
		{ print }
		END { exit !done }
	' "$file" >"$file.tmp"; then
		echo "error: plugin/$1 has no '$field:' line to stamp the version into" >&2
		exit 1
	fi
	mv "$file.tmp" "$file"
}
stamp wp-http-query.php 'Version'

# The WP_HTTP_QUERY_VERSION constant, the same way: its first define() line only.
main="$stage/$SLUG/wp-http-query.php"
if ! awk -v v="$version" -v q="'" '
	!done && $0 ~ ("^define\\( " q "WP_HTTP_QUERY_VERSION" q ", " q "[^" q "]*" q " \\);$") {
		print "define( " q "WP_HTTP_QUERY_VERSION" q ", " q v q " );"
		done = 1
		next
	}
	{ print }
	END { exit !done }
' "$main" >"$main.tmp"; then
	echo "error: plugin/wp-http-query.php has no WP_HTTP_QUERY_VERSION define() line to stamp the version into" >&2
	exit 1
fi
mv "$main.tmp" "$main"
if [ -f "$stage/$SLUG/readme.txt" ]; then
	stamp readme.txt 'Stable tag'
fi

out="$PWD/dist/$zip_name"
rm -f "$out"
# -X: leave out extra file attributes (uid/gid, macOS metadata).
(cd "$stage" && zip -q -r -X "$out" "$SLUG")

echo "dist/$zip_name"
