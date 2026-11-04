#!/usr/bin/env bash
# Drive both wp-env environments the plugin is tested against.
#
#   stable   .wp-env.json          WordPress 7.1.4  :8871  pre-7.2 CORS fallback path
#   nightly  .wp-env.nightly.json  nightly build    :8872  rest_allowed_cors_methods path (r64730)
#
# Usage: scripts/env.sh <start|stop|destroy|status|wp> [env] [args...]
#   env defaults to "all" (both) for start/stop/destroy/status.
#   wp requires an env: scripts/env.sh wp nightly plugin list
set -euo pipefail

cd "$(dirname "$0")/.."

WP_ENV=(npx --no-install wp-env)

config_for() {
	case "$1" in
		stable) echo ".wp-env.json" ;;
		nightly) echo ".wp-env.nightly.json" ;;
		*) echo "unknown env: $1 (expected stable or nightly)" >&2; exit 2 ;;
	esac
}

envs_for() {
	if [[ "${1:-all}" == "all" ]]; then echo "stable nightly"; else echo "$1"; fi
}

cmd="${1:-}"; shift || true

case "$cmd" in
	start)
		for env in $(envs_for "${1:-all}"); do
			config="$(config_for "$env")"
			echo "==> starting $env ($config)"
			"${WP_ENV[@]}" start --config="$config"
			# The tests use /wp-json/, which needs pretty permalinks.
			"${WP_ENV[@]}" run cli --config="$config" wp rewrite structure '/%postname%/' --hard >/dev/null
			"${WP_ENV[@]}" run cli --config="$config" wp core version
		done
		;;
	stop|destroy|status)
		for env in $(envs_for "${1:-all}"); do
			config="$(config_for "$env")"
			echo "==> $cmd $env ($config)"
			"${WP_ENV[@]}" "$cmd" --config="$config"
		done
		;;
	wp)
		env="${1:?usage: scripts/env.sh wp <stable|nightly> <wp-cli args...>}"; shift
		"${WP_ENV[@]}" run cli --config="$(config_for "$env")" wp "$@"
		;;
	*)
		sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
		exit 2
		;;
esac
