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
 * - Registers a route that opts itself in with 'QUERY' in its methods.
 * - Registers a GET route shaped like Jetpack's hazard, opted in nowhere,
 *   which must keep answering QUERY with 404.
 *
 * /wp/v2/types is deliberately left alone: the default-off tests use it.
 *
 * For tests/forward-compat.test.ts (issue #67) it registers ALREADY_PROVIDED,
 * a route on which everything the plugin adds is already there, as a future
 * core or the route would provide it: see "Already provided" below.
 *
 * One more option, also set only by the lifecycle suite, changes how REST
 * CORS is handled, as a site might: see CORS below.
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
 * Set only by the serial lifecycle suite, through WP-CLI, to change how REST
 * CORS is handled the way a site might. Never set by an HTTP request. Its value
 * is an array: 'scenario' picks one, and 'competing' also takes 'origin' and
 * 'methods'.
 *
 * - remove-late: removes core's handler, turning REST CORS off.
 * - competing: adds a callback after core's handler that sends restricted CORS
 *   headers, as a CORS plugin might: 'origin', 'methods', no credentials.
 * - filter-removes-query: adds a rest_allowed_cors_methods callback, after
 *   the plugin's, that takes QUERY out of the list.
 */
const CORS = 'wp_http_query_fixture_cors';

// Late in rest_api_init, after core hooks its handler (10).
add_action(
	'rest_api_init',
	static function (): void {
		$cors = get_option( CORS );
		if ( ! is_array( $cors ) ) {
			return;
		}

		switch ( $cors['scenario'] ?? '' ) {
			case 'remove-late':
				remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
				break;
			case 'competing':
				// Added after core's handler, so at its priority it runs after it.
				add_filter(
					'rest_pre_serve_request',
					static function ( $value ) use ( $cors ) {
						if ( get_http_origin() ) {
							header( 'Access-Control-Allow-Origin: ' . $cors['origin'] );
							header( 'Access-Control-Allow-Methods: ' . $cors['methods'] );
							header_remove( 'Access-Control-Allow-Credentials' );
						}
						return $value;
					},
					10
				);
				break;
			case 'filter-removes-query':
				add_filter(
					'rest_allowed_cors_methods',
					static fn ( array $methods ): array => array_values( array_diff( $methods, array( 'QUERY' ) ) ),
					20
				);
				break;
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
		$routes[] = ALREADY_PROVIDED;
		return $routes;
	}
);

add_action(
	'rest_api_init',
	static function (): void {
		// mu-plugins load before plugins, so only check for the plugin once
		// rest_api_init runs, and skip the route if the plugin is inactive.
		if ( defined( 'WP_HTTP_QUERY_VERSION' ) ) {
			register_rest_route(
				NAMESPACE_,
				'/queryable',
				array(
					'methods'             => \WP_REST_Server::READABLE . ', QUERY',
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

/*
 * -------------------------------------------------------------------------
 * Already provided (issue #67): everything the plugin adds, already there on
 * one route, as a future core or the route itself would provide it. The plugin
 * must neither duplicate nor overwrite any of it.
 *
 * - Its handler registers 'GET, QUERY', and wp_http_query_routes opts it in.
 * - A rest_allowed_cors_methods callback, before the plugin's, returns core's
 *   default list with QUERY in it.
 * - A rest_request_parameter_order callback, before the plugin's, returns the
 *   order core gives its body methods: POST before GET. The tests send JSON.
 * - The callback sets its own Accept-Query.
 *
 * Observers that run after the plugin report what it left: the response body
 * has the handler's raw methods and the parameter order, and a header has the
 * CORS list. Core dedupes the CORS list after the filter, so the header the
 * browser sees can't show a duplicate; the observer's can.
 * ----------------------------------------------------------------------
 */

/** The route's full key. */
const ALREADY_PROVIDED = '/' . NAMESPACE_ . '/already-provided';

/**
 * Whether a request is for the already-provided route.
 *
 * @param mixed $request The request, if any.
 */
function is_already_provided( $request ): bool {
	return $request instanceof \WP_REST_Request && ALREADY_PROVIDED === $request->get_route();
}

/**
 * What the observers saw after the plugin ran, by key.
 *
 * @param string $key   'methods' or 'order'.
 * @param mixed  $value The value to record. Omit to read.
 * @return mixed The recorded value, or null.
 */
function observed( string $key, $value = null ) {
	static $seen = array();

	if ( null !== $value ) {
		$seen[ $key ] = $value;
	}

	return $seen[ $key ] ?? null;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			NAMESPACE_,
			substr( ALREADY_PROVIDED, strlen( '/' . NAMESPACE_ ) ),
			array(
				'methods'             => 'GET, QUERY',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
					// Reads the parameters, so the parameter order is worked out and observed.
					$request->get_params();

					return new \WP_REST_Response(
						array(
							'methods' => observed( 'methods' ),
							'order'   => observed( 'order' ),
						),
						200,
						array( 'Accept-Query' => '"application/jsonpath"' )
					);
				},
			)
		);
	}
);

// The registration exactly as the plugin left it, after every other callback.
add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		observed( 'methods', $endpoints[ ALREADY_PROVIDED ][0]['methods'] ?? null );
		return $endpoints;
	},
	PHP_INT_MAX
);

// The fake provider: core's default list with QUERY already in it.
add_filter(
	'rest_allowed_cors_methods',
	static function ( array $methods, $request ): array {
		return is_already_provided( $request )
			? array( 'OPTIONS', 'GET', 'QUERY', 'POST', 'PUT', 'PATCH', 'DELETE' )
			: $methods;
	},
	9,
	2
);

// Sent before core dedupes the list, so a duplicate the plugin added would show.
add_filter(
	'rest_allowed_cors_methods',
	static function ( array $methods, $request ): array {
		if ( is_already_provided( $request ) ) {
			header( 'X-WP-HTTP-Query-Test-CORS-Methods: ' . implode( ', ', $methods ) );
		}
		return $methods;
	},
	PHP_INT_MAX,
	2
);

// The fake provider: POST already in place for a QUERY with a JSON body.
add_filter(
	'rest_request_parameter_order',
	static function ( array $order, \WP_REST_Request $request ): array {
		return is_already_provided( $request ) && 'QUERY' === $request->get_method()
			? array( 'JSON', 'POST', 'GET', 'URL', 'defaults' )
			: $order;
	},
	9,
	2
);

add_filter(
	'rest_request_parameter_order',
	static function ( array $order, \WP_REST_Request $request ): array {
		if ( is_already_provided( $request ) ) {
			observed( 'order', $order );
		}
		return $order;
	},
	PHP_INT_MAX,
	2
);
