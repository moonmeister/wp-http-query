#!/usr/bin/env bash
# Drive the wp-env sites the plugin is tested against.
#
#   nightly    .wp-env.nightly.json    nightly build  :8872  the test site: the plugin needs 7.2+
#   demo       .wp-env.demo.json       nightly build  :8873  for trying by hand: plugin only, defaults,
#                                                            no test fixtures. Never part of "all"
#   multisite  .wp-env.multisite.json  nightly build  :8876  subdirectory network, plugin network-active,
#                                                            subsite at /sub/. Never part of "all"
#
# Usage: scripts/env.sh <start|stop|destroy|status|open|wp> [env] [args...]
#   env defaults to "all" (the test sites, not demo or multisite) for start/stop/destroy/status/open.
#   wp requires an env: scripts/env.sh wp nightly plugin list
set -euo pipefail

cd "$(dirname "$0")/.."

WP_ENV=(npx --no-install wp-env)

url_for() {
	case "$1" in
		nightly) echo "http://localhost:8872" ;;
		demo) echo "http://localhost:8873" ;;
		multisite) echo "http://localhost:8876" ;;
	esac
}

config_for() {
	case "$1" in
		nightly) echo ".wp-env.nightly.json" ;;
		demo) echo ".wp-env.demo.json" ;;
		multisite) echo ".wp-env.multisite.json" ;;
		*) echo "unknown env: $1 (expected nightly, demo or multisite)" >&2; exit 2 ;;
	esac
}

envs_for() {
	if [[ "${1:-all}" == "all" ]]; then echo "nightly"; else echo "$1"; fi
}

cmd="${1:-}"; shift || true

case "$cmd" in
	start)
		for env in $(envs_for "${1:-all}"); do
			config="$(config_for "$env")"
			echo "==> starting $env ($config)"
			"${WP_ENV[@]}" start --config="$config"
			if [[ "$env" == "demo" ]]; then
				# What a real install looks like: the plugin active and pretty permalinks
				# (QUERY to /wp-json/ needs them), nothing else. No fixtures, no example
				# plugin, no options: no preset on, and the echo route stays admins only.
				"${WP_ENV[@]}" run cli --config="$config" wp plugin activate wp-http-query >/dev/null
				"${WP_ENV[@]}" run cli --config="$config" wp rewrite structure '/%postname%/' --hard >/dev/null
				"${WP_ENV[@]}" run cli --config="$config" wp core version
				continue
			fi
			if [[ "$env" == "multisite" ]]; then
				# Network-activated, as a network admin would, plus a subsite so REST is
				# tested under a site path (/sub/wp-json/). Each site has its own
				# permalink setting; the tests need pretty permalinks on both.
				wp=("${WP_ENV[@]}" run cli --config="$config" wp)
				"${wp[@]}" plugin activate wp-http-query query-route-example --network >/dev/null
				"${wp[@]}" site list --field=path | grep -x '/sub/' >/dev/null || "${wp[@]}" site create --slug=sub --title=Sub >/dev/null
				for path in / /sub/; do
					"${wp[@]}" rewrite structure '/%postname%/' --url="$(url_for multisite)$path" >/dev/null
				done
				"${wp[@]}" core version
				continue
			fi
			# Mapped rather than listed under "plugins" so the slug is wp-http-query,
			# as on a real install, not the directory name. Mappings aren't activated
			# automatically, and a pre-existing site may still have the old slug.
			"${WP_ENV[@]}" run cli --config="$config" wp plugin deactivate plugin --quiet >/dev/null 2>&1 || true
			"${WP_ENV[@]}" run cli --config="$config" wp plugin activate wp-http-query >/dev/null
			# The example plugin from docs/integrating.md, tested by tests/examples.test.ts.
			"${WP_ENV[@]}" run cli --config="$config" wp plugin activate query-route-example >/dev/null
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
	open)
		opener="$(command -v open || command -v xdg-open || true)"
		for env in $(envs_for "${1:-all}"); do
			config_for "$env" >/dev/null # validates the name
			url="$(url_for "$env")/wp-admin/"
			# The demo opens straight onto the plugin's settings page.
			if [[ "$env" == "demo" ]]; then url="${url}options-general.php?page=wp-http-query"; fi
			if [[ -n "$opener" ]]; then
				echo "==> opening $env: $url (admin / password)"
				"$opener" "$url"
			else
				echo "$env: $url (admin / password)"
			fi
		done
		;;
	wp)
		env="${1:?usage: scripts/env.sh wp <nightly|demo|multisite> <wp-cli args...>}"; shift
		"${WP_ENV[@]}" run cli --config="$(config_for "$env")" wp "$@"
		;;
	*)
		sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
		exit 2
		;;
esac
