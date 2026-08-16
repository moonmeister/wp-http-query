#!/usr/bin/env bash
#
# Runs the HTTP QUERY passthrough matrix and writes machine-readable results.
#
#   ./run.sh            bring stacks up, run, leave them running
#   ./run.sh --down     tear the stacks down afterwards
#   ./run.sh --no-up    assume stacks are already running
#
# Output: results/results.json (canonical) and results/MATRIX.md (generated).
# Re-runnable by design — this project tracks a moving ecosystem, so diffing
# runs over time is the point.

set -euo pipefail

cd "$(dirname "$0")"

RESULTS_DIR="results"
RESULTS_JSON="$RESULTS_DIR/results.json"
RESULTS_MD="$RESULTS_DIR/MATRIX.md"

DO_UP=1
DO_DOWN=0
for arg in "$@"; do
	case "$arg" in
		--down)   DO_DOWN=1 ;;
		--no-up)  DO_UP=0 ;;
		-h|--help) sed -n '2,12p' "$0"; exit 0 ;;
		*) echo "unknown flag: $arg" >&2; exit 2 ;;
	esac
done

for dep in docker jq curl; do
	command -v "$dep" >/dev/null 2>&1 || { echo "missing dependency: $dep" >&2; exit 1; }
done

sha256() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum | cut -d' ' -f1
	else
		shasum -a 256 | cut -d' ' -f1
	fi
}

# stack_name:port
STACKS=(
	"nginx-fpm:8081"
	"nginx-hardened:8181"
	"apache-modphp:8082"
	"apache-modphp-hardened:8182"
	"apache-fcgi:8083"
	"caddy-fpm:8084"
	"php-builtin:8085"
)

# Request the front controller directly rather than the bare root.
#
# This matters: on nginx, `QUERY /` is answered by the index module, which 405s
# non-GET/HEAD/POST *before* try_files can fall through to index.php. That is a
# real nginx behavior, but it is not the WordPress path — /wp-json/* never
# resolves to a directory index. Hitting /index.php reproduces what every stack
# does internally for a REST request, and removes the directory-index variable
# from the comparison. The bare-root behavior is kept as its own case below.
FC_PATH="/index.php"
ROOT_PATH="/"

JSON_BODY='{"filter":{"post_type":"post","status":"publish"},"per_page":10}'
FORM_BODY='filter[post_type]=post&per_page=10'
# 64 KiB, to catch truncation that a small body would hide.
LARGE_BODY="$(head -c 65536 /dev/zero | tr '\0' 'x')"

mkdir -p "$RESULTS_DIR"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [[ $DO_UP -eq 1 ]]; then
	echo "==> bringing stacks up"
	docker compose up -d --wait 2>/dev/null || docker compose up -d
	echo "==> waiting for readiness"
	for entry in "${STACKS[@]}"; do
		port="${entry##*:}"
		for _ in $(seq 1 40); do
			if curl -fsS -o /dev/null "http://localhost:$port/" 2>/dev/null; then break; fi
			sleep 0.5
		done
	done
fi

# run_case <stack> <port> <case> <method> <content-type|-> <body|-> <path> [expect]
#
# expect: "ok" (default) — a 200 with an intact body is a pass.
#         "deny"         — a non-2xx is a pass. Used for sentinels that prove a
#                          hardening config is actually in force. Without these,
#                          a misconfigured hardened stack reports all-pass and
#                          looks like evidence that hardening permits QUERY.
run_case() {
	local stack="$1" port="$2" case_name="$3" method="$4" ctype="$5" body="$6" path="$7"
	local expect="${8:-ok}"
	local url="http://localhost:$port$path"
	local resp="$TMP/resp.json"
	local -a args=(-s -o "$resp" -w '%{http_code}' --max-time 20 -X "$method")

	local expect_sha=""
	if [[ "$body" != "-" ]]; then
		expect_sha="$(printf '%s' "$body" | sha256)"
		args+=(-H "X-Probe-Expect-Sha256: $expect_sha" --data-binary "$body")
		[[ "$ctype" != "-" ]] && args+=(-H "Content-Type: $ctype")
	fi

	local code
	code="$(curl "${args[@]}" "$url" 2>/dev/null || echo "000")"

	local probe='null' verdict reason
	if [[ "$expect" == "deny" ]]; then
		if [[ "$code" == "000" ]]; then
			verdict="error"; reason="no response (stack down or connection refused)"
		elif [[ "$code" == 2* ]]; then
			verdict="fail"
			reason="HTTP $code — hardening NOT in force, this stack's results are not trustworthy"
		else
			verdict="pass"; reason="denied with HTTP $code, as required"
		fi
	elif [[ "$code" == "200" ]] && jq -e . "$resp" >/dev/null 2>&1; then
		probe="$(cat "$resp")"
		local got_method intact readable
		got_method="$(jq -r '.request_method // ""' "$resp")"
		intact="$(jq -r '.body.intact // "null"' "$resp")"
		readable="$(jq -r '.body.input_readable' "$resp")"

		if [[ "$got_method" != "$method" ]]; then
			verdict="fail"; reason="method arrived as '$got_method', expected '$method'"
		elif [[ "$readable" != "true" ]]; then
			verdict="fail"; reason="php://input not readable"
		elif [[ "$body" != "-" && "$intact" != "true" ]]; then
			verdict="fail"; reason="body did not arrive intact"
		else
			verdict="pass"; reason=""
		fi
	elif [[ "$code" == "000" ]]; then
		verdict="error"; reason="no response (stack down or connection refused)"
	else
		verdict="fail"; reason="HTTP $code"
	fi

	printf '%-24s %-14s %-7s %s\n' "$stack" "$case_name" "$verdict" "${reason:-HTTP $code}"

	jq -n \
		--arg stack "$stack" --arg case "$case_name" --arg method "$method" \
		--arg path "$path" --arg expect "$expect" \
		--arg code "$code" --arg verdict "$verdict" --arg reason "$reason" \
		--argjson probe "$probe" \
		'{stack:$stack, case:$case, method:$method, path:$path, expect:$expect,
		  http_status:($code|tonumber? // 0),
		  verdict:$verdict, reason:$reason, probe:$probe}' >> "$TMP/rows.jsonl"
}

echo
printf '%-24s %-14s %-7s %s\n' "STACK" "CASE" "VERDICT" "DETAIL"
printf '%.0s-' {1..78}; echo

: > "$TMP/rows.jsonl"
for entry in "${STACKS[@]}"; do
	stack="${entry%%:*}"; port="${entry##*:}"
	run_case "$stack" "$port" "get-control"   GET   -                                   -             "$FC_PATH"
	run_case "$stack" "$port" "post-control"  POST  "application/json"                  "$JSON_BODY"  "$FC_PATH"
	run_case "$stack" "$port" "query-json"    QUERY "application/json"                  "$JSON_BODY"  "$FC_PATH"
	run_case "$stack" "$port" "query-form"    QUERY "application/x-www-form-urlencoded" "$FORM_BODY"  "$FC_PATH"
	run_case "$stack" "$port" "query-empty"   QUERY -                                   -             "$FC_PATH"
	run_case "$stack" "$port" "query-large"   QUERY "application/octet-stream"          "$LARGE_BODY" "$FC_PATH"
	# Bare root — exercises the directory-index handler instead of the front
	# controller. Not the WordPress REST path; recorded to document the
	# difference rather than to gate on it.
	run_case "$stack" "$port" "query-root"    QUERY "application/json"                  "$JSON_BODY"  "$ROOT_PATH"

	# Sentinel: on a hardened stack, a method outside the allowlist must be
	# denied. DELETE is a *known* verb, so a 200 here means the hardening block
	# is not applying at all — not that it tolerates unknown methods.
	case "$stack" in
		*-hardened)
			run_case "$stack" "$port" "harden-sentinel" DELETE - - "$FC_PATH" deny
			;;
	esac
done

jq -s \
	--arg date "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
	--arg curl "$(curl --version | head -1)" \
	'{run:{date:$date, curl:$curl}, results:.}' \
	"$TMP/rows.jsonl" > "$RESULTS_JSON"

# Generated view. results.json stays canonical.
{
	echo "# QUERY passthrough matrix"
	echo
	echo "Generated by \`matrix/run.sh\` — **do not edit**. Canonical data: \`results.json\`."
	echo
	echo "Run: $(jq -r '.run.date' "$RESULTS_JSON")"
	echo
	echo "Legend: ✅ pass · ❌ fail · ⚠️ error (stack unreachable)"
	echo
	echo "All cases hit \`/index.php\` (the front controller — what \`/wp-json/*\` resolves to)"
	echo "except \`query-root\`, which hits \`/\` to exercise the directory-index handler."
	echo "A \`query-root\` failure alongside passing \`query-*\` cases is **not** a QUERY"
	echo "passthrough problem; see matrix/README.md."
	echo
	jq -r '
		([.results[].case]  | unique) as $cases  |
		([.results[].stack] | unique) as $stacks |
		(.results) as $r |
		(["Stack"] + $cases | "| " + join(" | ") + " |"),
		("|" + ("---|" * (($cases|length)+1))),
		($stacks[] | . as $s |
			"| " + $s + " | " + ([ $cases[] as $c |
				([ $r[] | select(.stack==$s and .case==$c) | .verdict ] | first) // "-" |
				if   . == "pass"  then "✅"
				elif . == "fail"  then "❌"
				elif . == "error" then "⚠️"
				else "-" end
			] | join(" | ")) + " |")
	' "$RESULTS_JSON"
	echo
	echo "## Failures and errors"
	echo
	jq -r '.results[] | select(.verdict != "pass")
		| "- **\(.stack)** / `\(.case)` — \(.verdict): \(.reason // "HTTP \(.http_status)")"' "$RESULTS_JSON"
	echo
	echo "## Probe notes"
	echo
	jq -r '.results[] | select(.probe != null and (.probe.notes | length) > 0)
		| "- **\(.stack)** / `\(.case)`:\n" + (.probe.notes | map("  - " + .) | join("\n"))' "$RESULTS_JSON"
} > "$RESULTS_MD"

echo
echo "==> wrote $RESULTS_JSON and $RESULTS_MD"
jq -r '.results | group_by(.verdict) | map("\(.[0].verdict): \(length)") | join("  ")' "$RESULTS_JSON"

if [[ $DO_DOWN -eq 1 ]]; then
	echo "==> tearing down"
	docker compose down -v
fi
