<?php
/**
 * Reach: which existing routes gain QUERY (ADR 0005). Presets, the route filter, and the rest_endpoints filter that applies them.
 *
 * @package WPHttpQuery
 */

declare( strict_types = 1 );

namespace WPHttpQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -------------------------------------------------------------------------
 * Gap 2 — READABLE is 'GET', so no existing read route matches QUERY.
 *
 * ADR 0002 closed on an additive QUERYABLE constant, so core gives existing
 * read routes nothing. Which routes this plugin adds QUERY to is ADR 0005,
 * decided 2026-11-04: reach is OFF by default and opt-in, never every route.
 *
 * Why not every route: plugins read 'GET' as a synonym for "safe". Jetpack
 * registers READABLE routes whose callbacks do
 * `if ( 'GET' === $request->get_method() ) { read } else { write }`, so a
 * QUERY to them activates modules. See ADR 0005 and experiments/plugin-spike/.
 *
 * QUERY reaches a route in exactly three ways:
 *
 * 1. The route registers it. Put QUERYABLE (or 'QUERY') in 'methods':
 *
 *        register_rest_route( 'my/v1', '/things', array(
 *            'methods'  => WP_REST_Server::READABLE . ', ' . \WPHttpQuery\QUERYABLE,
 *            ...
 *        ) );
 *
 *    This already works on stock core; the plugin adds nothing for it.
 *
 * 2. A site lists the route in the 'wp_http_query_routes' filter.
 *
 * 3. A site enables a preset: the option set on Settings → HTTP QUERY, then
 *    the 'wp_http_query_presets' filter, which code can use to override it.
 *
 * Only handlers that accept GET gain QUERY. Write handlers on the same route
 * never do (add_query_to_handler()).
 *
 * match_request_to_handler() is a plain array-key lookup on the normalized
 * method map, and 'rest_endpoints' runs BEFORE that normalization
 * (class-wp-rest-server.php:973, normalizer at :1007). So what this filter
 * hands back is raw registration input — a bare string, a comma-separated
 * string, or an array — not the method map.
 * ----------------------------------------------------------------------
 */

/** Option holding the enabled preset names. The only thing the plugin stores. */
const PRESETS_OPTION = 'wp_http_query_presets';

/**
 * The presets, by name: a label for the settings page and a route list.
 *
 * 'core-collections' is core's public read collections: ADR 0005 option 2,
 * done by the plugin rather than by core. Each route's GET handler is a
 * WP_REST_Controller::get_items() that was checked for branching on the
 * request method (get_method(), is_method(), REQUEST_METHOD) in 7.0.8, 7.1.4
 * and 7.2-beta4: the only hits are is_method( 'HEAD' ), which QUERY does not
 * match, so QUERY gets the full GET response. See ADR 0005 for the record.
 *
 * Exact route keys, not patterns. Single-item routes are not included.
 *
 * @return array<string, array{label: string, description: string, routes: string[]}>
 */
function presets(): array {
	return array(
		'core-collections' => array(
			'label'       => __( 'Core collections', 'wp-http-query' ),
			'description' => __( 'Accept QUERY on core’s read collections (posts, pages, media, comments, categories, tags, users, search)', 'wp-http-query' ),
			'routes'      => array(
				'/wp/v2/posts',
				'/wp/v2/pages',
				'/wp/v2/media',
				'/wp/v2/comments',
				'/wp/v2/categories',
				'/wp/v2/tags',
				'/wp/v2/users',
				'/wp/v2/search',
			),
		),
	);
}

/**
 * Names of the enabled presets.
 *
 * The stored option, then the 'wp_http_query_presets' filter, so code can
 * enable or disable a preset whatever the setting says:
 *
 *     add_filter( 'wp_http_query_presets', function ( array $presets ) {
 *         $presets[] = 'core-collections';
 *         return $presets;
 *     } );
 *
 * Unknown names are ignored, with a _doing_it_wrong() notice.
 *
 * @return string[]
 */
function enabled_presets(): array {
	$names = (array) apply_filters( 'wp_http_query_presets', sanitize_presets( get_option( PRESETS_OPTION, array() ) ) );
	$known = array();

	foreach ( $names as $name ) {
		if ( is_string( $name ) && isset( presets()[ $name ] ) ) {
			$known[] = $name;
		} else {
			_doing_it_wrong(
				'wp_http_query_presets',
				sprintf(
					/* translators: 1: The preset name that was passed. 2: Comma-separated list of known preset names. */
					esc_html__( 'Unknown preset "%1$s". Known presets: %2$s.', 'wp-http-query' ),
					esc_html( is_scalar( $name ) ? (string) $name : gettype( $name ) ),
					esc_html( implode( ', ', array_keys( presets() ) ) )
				),
				'0.1.0'
			);
		}
	}

	return array_values( array_unique( $known ) );
}

/**
 * Opted-in route keys, each mapped to why it is opted in.
 *
 * The one place reach is decided: the rest_endpoints filter below reads its
 * keys, and the settings page's status table reads the values. A route a
 * preset lists is array( 'type' => 'preset', 'preset' => <name> ), from the
 * first enabled preset that lists it; anything the 'wp_http_query_routes'
 * filter added is array( 'type' => 'filter' ). A preset route the filter
 * removed is not opted in at all.
 *
 * Worked out once per request: core rebuilds its route table, running
 * rest_endpoints, every time get_routes() is called. Saving the preset option
 * clears it (see the hooks below).
 *
 * @param bool $forget Clear the remembered result instead, and return an empty list.
 * @return array<string, array{type: string, preset?: string}>
 */
function opted_in_route_sources( bool $forget = false ): array {
	static $sources = null;

	if ( $forget ) {
		$sources = null;
		return array();
	}

	if ( null !== $sources ) {
		return $sources;
	}

	$from_presets = array();
	foreach ( enabled_presets() as $name ) {
		foreach ( presets()[ $name ]['routes'] as $route ) {
			if ( ! isset( $from_presets[ $route ] ) ) {
				$from_presets[ $route ] = array(
					'type'   => 'preset',
					'preset' => $name,
				);
			}
		}
	}

	/**
	 * Filters the route keys that gain QUERY on their GET handlers.
	 *
	 * Starts from the enabled presets' routes. Add to the list rather than
	 * replacing it, or the presets are dropped:
	 *
	 *     add_filter( 'wp_http_query_routes', function ( array $routes ) {
	 *         $routes[] = '/my-plugin/v1/things';
	 *         $routes[] = '/wp/v2/posts/(?P<id>[\d]+)'; // The registered regex, verbatim.
	 *         return $routes;
	 *     } );
	 *
	 * Entries match registered route keys exactly: no patterns, no prefixes.
	 * The keys are listed at GET /wp-json/.
	 *
	 * @param string[] $routes Route keys. Default: the enabled presets' routes.
	 */
	$routes  = array_filter(
		(array) apply_filters( 'wp_http_query_routes', array_keys( $from_presets ) ),
		'is_string'
	);
	$sources = array();

	foreach ( $routes as $route ) {
		if ( ! isset( $sources[ $route ] ) ) {
			$sources[ $route ] = $from_presets[ $route ] ?? array( 'type' => 'filter' );
		}
	}

	return $sources;
}

/**
 * Clears opted_in_route_sources()'s remembered result, when the stored
 * presets change mid-request.
 */
function forget_opted_in_route_sources(): void {
	opted_in_route_sources( true );
}

add_action( 'add_option_' . PRESETS_OPTION, __NAMESPACE__ . '\\forget_opted_in_route_sources' );
add_action( 'update_option_' . PRESETS_OPTION, __NAMESPACE__ . '\\forget_opted_in_route_sources' );
add_action( 'delete_option_' . PRESETS_OPTION, __NAMESPACE__ . '\\forget_opted_in_route_sources' );

add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		foreach ( array_keys( opted_in_route_sources() ) as $route ) {
			if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
				continue;
			}

			$handlers = $endpoints[ $route ];

			// Core wraps the single-handler shape after this filter, not before.
			if ( isset( $handlers['callback'] ) ) {
				$endpoints[ $route ] = add_query_to_handler( $handlers );
				continue;
			}

			foreach ( $handlers as $key => $handler ) {
				// Non-numeric keys are route options ('schema', ...), which core
				// moves out during normalization. Leave them alone.
				if ( ! is_numeric( $key ) || ! is_array( $handler ) ) {
					continue;
				}

				$endpoints[ $route ][ $key ] = add_query_to_handler( $handler );
			}
		}

		return $endpoints;
	}
);

/**
 * Adds QUERY alongside GET on a single route handler.
 *
 * Returns the handler untouched unless it accepts GET and does not already
 * accept QUERY.
 *
 * @param array $handler Raw, pre-normalization route handler.
 * @return array
 */
function add_query_to_handler( array $handler ): array {
	if ( ! isset( $handler['methods'] ) ) {
		return $handler;
	}

	$entries = is_array( $handler['methods'] ) ? $handler['methods'] : array( $handler['methods'] );
	$names   = array();

	foreach ( $entries as $entry ) {
		if ( ! is_string( $entry ) ) {
			return $handler;
		}

		foreach ( explode( ',', $entry ) as $name ) {
			$names[] = strtoupper( trim( $name ) );
		}
	}

	if ( ! in_array( 'GET', $names, true ) || in_array( METHOD, $names, true ) ) {
		return $handler;
	}

	$names[] = METHOD;

	/*
	 * Hand back a string, the form get_routes() has always comma-split. The
	 * array form was only split from 7.2 (Trac#65905, r63756); the string
	 * form costs nothing.
	 */
	$handler['methods'] = implode( ', ', $names );

	return $handler;
}

/**
 * Keeps known preset names only.
 *
 * Also the option's sanitize_callback, so it gets whatever was posted to
 * options.php: anything that isn't a list of known names reduces to the
 * known names in it, or to an empty list. Non-string entries are dropped
 * before comparing, so a nested array can't raise an array-to-string notice.
 *
 * @param mixed $value Raw option value.
 * @return string[]
 */
function sanitize_presets( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	return array_values( array_intersect( array_keys( presets() ), array_filter( $value, 'is_string' ) ) );
}
