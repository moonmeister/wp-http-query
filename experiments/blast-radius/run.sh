#!/usr/bin/env bash
# ADR 0002 blast-radius experiment.
# Patches WP_REST_Server method constants one variant at a time and records the
# core test suite's reaction. Run from the wpdev-exp worktree.
set -u

ROOT="$(cd "$(dirname "$0")" && pwd)"
MAIN="$(cd "$ROOT/../wordpress-develop" && pwd)"
SERVER="$ROOT/src/wp-includes/rest-api/class-wp-rest-server.php"
OUT="$ROOT/blast-radius"
mkdir -p "$OUT"

run_suite() {
  docker run --rm --network wordpress-develop_wpdevnet \
    -v "$ROOT:/var/www" \
    -v "$MAIN/vendor:/var/www/vendor:ro" \
    -w /var/www wordpressdevelop/php:latest \
    ./vendor/bin/phpunit --group restapi 2>&1
}

reset_server() { git -C "$ROOT" checkout -- "$SERVER"; }

variant() {
  local name="$1" desc="$2"
  echo "=== $name — $desc ==="
  run_suite > "$OUT/$name.txt"
  tail -1 "$OUT/$name.txt"
  # List failing/erroring test names for the diff against baseline.
  grep -E "^[0-9]+\) " "$OUT/$name.txt" | sed 's/^[0-9]*) //' | sort > "$OUT/$name.tests"
  reset_server
}

reset_server

# Baseline — unmodified.
variant baseline "unmodified trunk"

# A — QUERY joins ALLMETHODS.
perl -0pi -e "s/const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';/const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE, QUERY';/" "$SERVER"
grep -q "DELETE, QUERY" "$SERVER" || { echo "A: patch failed"; exit 1; }
variant option-A "ALLMETHODS gains QUERY"

# B — additive QUERYABLE constant.
perl -0pi -e "s/(const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';)/\$1\n\n\tconst QUERYABLE = 'QUERY';/" "$SERVER"
grep -q "QUERYABLE" "$SERVER" || { echo "B: patch failed"; exit 1; }
variant option-B "additive QUERYABLE constant"

# D — QUERY joins READABLE.
perl -0pi -e "s/const READABLE = 'GET';/const READABLE = 'GET, QUERY';/" "$SERVER"
grep -q "'GET, QUERY'" "$SERVER" || { echo "D: patch failed"; exit 1; }
variant option-D "READABLE gains QUERY"

echo
echo "=== summary ==="
for f in baseline option-A option-B option-D; do
  printf '%-12s %s\n' "$f" "$(tail -1 "$OUT/$f.txt")"
done
echo
echo "=== new failures vs baseline ==="
for f in option-A option-B option-D; do
  printf '%-12s %s new\n' "$f" "$(comm -13 "$OUT/baseline.tests" "$OUT/$f.tests" | wc -l | tr -d ' ')"
done
