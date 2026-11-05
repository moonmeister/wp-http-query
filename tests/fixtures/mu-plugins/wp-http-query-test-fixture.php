<?php
/**
 * Plugin Name: WP HTTP QUERY test fixture
 * Description: Test-only reach configuration for the wp-env test sites. Never ship this.
 *
 * Mounted into wp-content/mu-plugins by the .wp-env*.json mappings, so every
 * test site starts with the same opt-ins. Configuration is code only: nothing
 * here reads the request, so no HTTP request can change what is opted in.
 *
 * What it sets up for tests/adr-0005-reach.test.ts (ADR 0005, issue #1):
 *
 * - Enables the 'core-collections' preset through the filter, unless the
 *   lifecycle suite has switched that off (PRESET_FILTER_OFF below).
 * - Opts two core routes in by exact route key, one plain and one with a
 *   URL parameter, so both key shapes are covered.
 * - Registers a route that opts itself in with WPHttpQuery\QUERYABLE.
 * - Registers a GET route shaped like Jetpack's hazard, opted in nowhere,
 *   which must keep answering QUERY with 404.
 *
 * /wp/v2/types is deliberately left alone: the default-off tests use it.
 *
 * Five more switches, also set only by the lifecycle suite, change how REST
 * CORS is handled, as a site might: REMOVE_CORE_CORS removes core's handler
 * (REST CORS off), REMOVE_CORE_CORS_LATE removes it later in rest_api_init,
 * REHOOK_CORE_CORS moves it to priority 11, DOUBLE_CORE_CORS hooks it again
 * at 20, and COMPETING_CORS adds a restrictive CORS callback after it at 10.
 *
 * It also makes the plugin's diagnostic echo route public (it is admins only
 * by default), explicitly rather than relying on wp-env's WP_DEBUG, unless the
 * lifecycle suite has switched that off (DEMO_ROUTE_OFF below).
 *
 * @package WPHttpQuery
 */

declare( strict_types = 1 );

namespace WPHttpQuery\TestFixture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NAMESPACE_ = 'wp-http-query-test/v1';

/**
 * Set only by the serial lifecycle suite, through WP-CLI, while it tests the
 * stored preset setting: with this filter forcing the preset on, the setting
 * could not be seen to change anything. Never set by an HTTP request.
 */
const PRESET_FILTER_OFF = 'wp_http_query_fixture_preset_filter_off';

/**
 * Set only by the serial lifecycle suite, through WP-CLI, to check the echo
 * route is admins only when the plugin's filter is left at its default.
 * Never set by an HTTP request.
 */
const DEMO_ROUTE_OFF = 'wp_http_query_fixture_demo_route_off';

/**
 * Set only by the serial lifecycle suite, through WP-CLI: removes core's REST
 * CORS handler, the way a site turns REST CORS off. Never set by an HTTP request.
 */
const REMOVE_CORE_CORS = 'wp_http_query_fixture_remove_core_cors';

/**
 * Set only by the serial lifecycle suite, through WP-CLI: moves core's REST
 * CORS handler from priority 10 to 11. Never set by an HTTP request.
 */
const REHOOK_CORE_CORS = 'wp_http_query_fixture_rehook_core_cors';

/**
 * Set only by the serial lifecycle suite, through WP-CLI: removes core's REST
 * CORS handler on rest_api_init at priority 99, late enough that anything
 * deciding on rest_api_init at the default priorities would miss it. Never
 * set by an HTTP request.
 */
const REMOVE_CORE_CORS_LATE = 'wp_http_query_fixture_remove_core_cors_late';

/**
 * Set only by the serial lifecycle suite, through WP-CLI: hooks core's REST
 * CORS handler a second time, at priority 20, so it runs at 10 and at 20.
 * Never set by an HTTP request.
 */
const DOUBLE_CORE_CORS = 'wp_http_query_fixture_double_core_cors';

/**
 * Set only by the serial lifecycle suite, through WP-CLI: adds a callback at
 * core's priority, 10, after core's handler, that sends restricted CORS
 * headers the way a CORS plugin might: one fixed allowed origin, its own
 * method list, no credentials. Never set by an HTTP request.
 */
const COMPETING_CORS = 'wp_http_query_fixture_competing_cors';

/** The one origin COMPETING_CORS allows. */
const COMPETING_CORS_ORIGIN = 'https://allowed.example';

/** The method list COMPETING_CORS sends. */
const COMPETING_CORS_METHODS = 'GET, OPTIONS';

// Core hooks rest_send_cors_headers on rest_api_init at 10, so a site changes
// it after that.
add_action(
	'rest_api_init',
	static function (): void {
		if ( get_option( REMOVE_CORE_CORS ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}
		if ( get_option( REHOOK_CORE_CORS ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
			add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers', 11 );
		}
		if ( get_option( DOUBLE_CORE_CORS ) ) {
			add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers', 20 );
		}
		if ( get_option( COMPETING_CORS ) ) {
			// Same priority as core's handler, added after it, so it runs after it.
			add_filter(
				'rest_pre_serve_request',
				static function ( $value ) {
					if ( get_http_origin() ) {
						header( 'Access-Control-Allow-Origin: ' . COMPETING_CORS_ORIGIN );
						header( 'Access-Control-Allow-Methods: ' . COMPETING_CORS_METHODS );
						header_remove( 'Access-Control-Allow-Credentials' );
					}
					return $value;
				}
			);
		}
	},
	15
);

add_action(
	'rest_api_init',
	static function (): void {
		if ( get_option( REMOVE_CORE_CORS_LATE ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}
	},
	99
);

add_filter(
	'wp_http_query_public_demo_route',
	static function ( $is_public ) {
		return get_option( DEMO_ROUTE_OFF ) ? $is_public : true;
	}
);

add_filter(
	'wp_http_query_presets',
	static function ( array $presets ): array {
		if ( get_option( PRESET_FILTER_OFF ) ) {
			return $presets;
		}
		$presets[] = 'core-collections';
		return $presets;
	}
);

add_filter(
	'wp_http_query_routes',
	static function ( array $routes ): array {
		$routes[] = '/wp/v2/taxonomies';
		$routes[] = '/wp/v2/posts/(?P<id>[\d]+)';
		return $routes;
	}
);

add_action(
	'rest_api_init',
	static function (): void {
		// mu-plugins load before plugins, so only read the constant once
		// rest_api_init runs, and skip the route if the plugin is inactive.
		if ( defined( 'WPHttpQuery\QUERYABLE' ) ) {
			register_rest_route(
				NAMESPACE_,
				'/queryable',
				array(
					'methods'             => \WP_REST_Server::READABLE . ', ' . \WPHttpQuery\QUERYABLE,
					'permission_callback' => '__return_true',
					'callback'            => static function ( \WP_REST_Request $request ): array {
						return array(
							'method' => $request->get_method(),
							'params' => $request->get_params(),
						);
					},
				)
			);
		}

		// The method-branching hazard (ADR 0005, experiments/plugin-spike/).
		// Opted in nowhere, so QUERY must never reach the else branch. It
		// writes nothing: it only reports which branch ran.
		register_rest_route(
			NAMESPACE_,
			'/branches-on-method',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $request ): array {
					if ( 'GET' === $request->get_method() ) {
						return array( 'branch' => 'read' );
					}
					return array( 'branch' => 'write' );
				},
			)
		);
	}
);
