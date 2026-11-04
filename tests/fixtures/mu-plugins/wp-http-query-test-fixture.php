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
 * It also turns on the plugin's diagnostic echo route, which is off by
 * default, explicitly rather than relying on wp-env's WP_DEBUG, unless the
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
 * route is gone when the plugin's filter is left at its default. Never set
 * by an HTTP request.
 */
const DEMO_ROUTE_OFF = 'wp_http_query_fixture_demo_route_off';

add_filter(
	'wp_http_query_register_demo_route',
	static function ( $register ) {
		return get_option( DEMO_ROUTE_OFF ) ? $register : true;
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
