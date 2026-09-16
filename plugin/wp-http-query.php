<?php
/**
 * Plugin Name:       WP HTTP QUERY
 * Description:       Demonstrates HTTP QUERY (RFC 10008) support on the WordPress REST API, entirely in userland — no core patch required.
 * Version:           0.1.0-dev
 * Requires PHP:      7.2
 * License:           GPL-2.0-or-later
 *
 * This plugin exists to prove the end-to-end path before proposing anything to
 * core, and to validate that the three identified core gaps can be worked around
 * from userland. Where a workaround is possible, it is annotated with the core
 * gap it stands in for.
 *
 * See ../docs/scope.md §2.
 *
 * @package WPHttpQuery
 */

declare( strict_types = 1 );

namespace WPHttpQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const METHOD    = 'QUERY';
const NAMESPACE_ = 'wp-http-query/v1';

/**
 * Media types this plugin accepts as query formats.
 *
 * ADR 0001 decided option B: JSON and form-encoded, matching what core already
 * parses for the other body-bearing methods. No new parsing is introduced —
 * closing the gap-3 lookup order is what makes the form-encoded case work.
 */
function accepted_query_types(): array {
	return (array) apply_filters(
		'wp_http_query_accepted_types',
		array( 'application/json', 'application/x-www-form-urlencoded' )
	);
}

/* -------------------------------------------------------------------------
 * Gap 1 — Access-Control-Allow-Methods is a hardcoded string in core.
 *
 * rest_send_cors_headers() emits the list with a raw header() call and no hook
 * at that point, so a cross-origin QUERY preflight fails.
 *
 * Trac#46992 (wordpress-develop#13151) adds a 'rest_allowed_cors_methods'
 * filter. Where that exists, one add_filter does the whole job. Where it does
 * not, core's callback has to be unhooked and replaced wholesale — which this
 * plugin will keep needing for as long as it supports cores that predate the
 * filter, so the fallback is permanent rather than temporary.
 * ---------------------------------------------------------------------- */

/**
 * Whether core's CORS method list is filterable.
 *
 * Feature detection, not version sniffing: the $result and $request parameters
 * arrived in the same change as the filter, and an alpha build of the release
 * carrying it would fail a version comparison against '7.2'.
 */
function core_cors_is_filterable(): bool {
	static $filterable = null;

	if ( null === $filterable ) {
		try {
			$filterable = ( new \ReflectionFunction( 'rest_send_cors_headers' ) )->getNumberOfParameters() >= 3;
		} catch ( \ReflectionException $e ) {
			$filterable = false;
		}
	}

	return $filterable;
}

add_action(
	'rest_api_init',
	static function (): void {
		if ( core_cors_is_filterable() ) {
			add_filter(
				'rest_allowed_cors_methods',
				static function ( array $methods ): array {
					if ( ! in_array( METHOD, $methods, true ) ) {
						$methods[] = METHOD;
					}

					return $methods;
				}
			);

			return;
		}

		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\send_cors_headers' );
	},
	20
);

/**
 * Replacement for core's rest_send_cors_headers() that includes QUERY.
 *
 * Mirrors core's behavior exactly apart from the method list.
 *
 * @param mixed $value Passthrough value.
 * @return mixed
 */
function send_cors_headers( $value ) {
	$origin = get_http_origin();

	if ( $origin ) {
		if ( 'null' !== $origin ) {
			$origin = sanitize_url( $origin );
		}
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE, QUERY' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin', false );
	} elseif ( ! headers_sent() && 'GET' === $_SERVER['REQUEST_METHOD'] && ! is_user_logged_in() ) {
		header( 'Vary: Origin', false );
	}

	return $value;
}

/* -------------------------------------------------------------------------
 * Gap 3 — get_parameter_order() excludes QUERY from body-data parsing.
 *
 * class-wp-rest-request.php:377-380 hardcodes POST/PUT/PATCH/DELETE, so a
 * form-encoded QUERY body is parsed into params['POST'] and then never looked
 * up. JSON bodies are unaffected (parse_json_params runs unconditionally).
 *
 * Fixable from userland via the rest_request_parameter_order filter, which
 * usefully proves the one-line core fix is correct before anyone patches it.
 * ---------------------------------------------------------------------- */

add_filter(
	'rest_request_parameter_order',
	static function ( array $order, \WP_REST_Request $request ): array {
		if ( METHOD !== $request->get_method() || in_array( 'POST', $order, true ) ) {
			return $order;
		}

		// Insert before GET, matching where core places it for other body methods.
		$index = array_search( 'GET', $order, true );
		if ( false === $index ) {
			$order[] = 'POST';
			return $order;
		}

		array_splice( $order, (int) $index, 0, array( 'POST' ) );
		return $order;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * Gap 2 — READABLE is 'GET', so no existing read route matches QUERY.
 *
 * ADR 0002 closed on an additive QUERYABLE constant, so core gives existing
 * read routes nothing. Whether they should get it anyway, and how, is ADR
 * 0005, which is open. This filter implements its option 3 — reach by
 * widening READABLE — because the plugin's job is to be the experiment: it
 * runs the option under test, not the option preferred, or it stops being
 * evidence about what core would do.
 *
 * That includes the cost. Option 3 breaks plugins that read 'GET' as a
 * synonym for "safe" — Jetpack activates modules on a QUERY to a READABLE
 * route — and this filter reproduces that on any site running both. Scoped
 * to sites that installed it deliberately, which is the whole difference
 * between measuring the hazard and shipping it. See ADR 0005 and
 * experiments/plugin-spike/.
 *
 * match_request_to_handler() is a plain array-key lookup on the normalized
 * method map, and 'rest_endpoints' runs BEFORE that normalization
 * (class-wp-rest-server.php:973, normalizer at :1007). So what this filter
 * hands back is raw registration input — a bare string, a comma-separated
 * string, or an array — not the method map.
 * ---------------------------------------------------------------------- */

add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		foreach ( $endpoints as $route => $handlers ) {
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
	 * Hand back a STRING, never an array. get_routes() comma-splits the string
	 * form but not the array form, so an array entry carrying a multi-method
	 * constant registers one unmatchable key — Trac#65905, patched in
	 * wordpress-develop#13136 but not yet released. Writing the string form
	 * means this plugin cannot trip that bug, on any core version.
	 *
	 * It also incidentally repairs the routes it touches: flattening
	 * array( 'GET', 'POST, PUT, PATCH' ) to a string makes POST/PUT/PATCH
	 * matchable again. Routes without GET are not touched and stay broken.
	 */
	$handler['methods'] = implode( ', ', $names );

	return $handler;
}

/* -------------------------------------------------------------------------
 * Accept-Query advertisement (RFC 10008 §3).
 *
 * Structured Fields List syntax — NOT the legacy Accept grammar, despite the
 * surface similarity. Emitted alongside core's generic Allow header logic.
 * ---------------------------------------------------------------------- */

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		$route = $response->get_matched_route();
		if ( ! $route ) {
			return $response;
		}

		$routes = $server->get_routes();
		foreach ( $routes[ $route ] ?? array() as $handler ) {
			if ( ! empty( $handler['methods'][ METHOD ] ) ) {
				$list = implode( ', ', array_map(
					static fn ( string $type ): string => '"' . $type . '"',
					accepted_query_types()
				) );
				$response->header( 'Accept-Query', $list );
				break;
			}
		}

		return $response;
	},
	11,
	3
);

/* -------------------------------------------------------------------------
 * Cache safety — deliberately nothing. ADR 0003, option C.
 *
 * An earlier version of this file sent Cache-Control: private, no-cache on
 * every QUERY response, on the theory that a body-blind cache would collide
 * two different request bodies on one URI-keyed entry. That threat model was
 * falsified: no shipping cache stores a QUERY response at all. Varnish
 * synth(501)s it before the origin sees it, nginx's cache-method bitmask holds
 * only GET/HEAD/POST and cannot be configured otherwise, mod_cache, Squid,
 * Fastly and Cloudflare all allowlist, and RFC 9111 §3 forbids storing a
 * response whose request method the cache has not understood.
 *
 * Emitting a header against a risk that does not exist would teach operators
 * that QUERY is uncacheable by nature. See experiments/cache-survey/.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Demo route.
 *
 * Registrable on stock core with no patch: register_rest_route() has no method
 * allowlist, set_method() only uppercases, and match_request_to_handler() is a
 * plain array-key lookup. See scope.md §2.
 *
 * Also reachable via the core X-HTTP-Method-Override tunnel:
 *   POST /wp-json/wp-http-query/v1/echo  +  X-HTTP-Method-Override: QUERY
 * That is a tunnel, NOT the method — intermediaries see POST, so RFC 10008
 * cacheability and retry safety do not apply.
 * ---------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			NAMESPACE_,
			'/echo',
			array(
				'methods'             => METHOD,
				'permission_callback' => '__return_true',
				'callback'            => __NAMESPACE__ . '\\handle_echo',
			)
		);
	}
);

/**
 * Echoes back what the server actually received.
 *
 * Diagnostic, not a real endpoint — it reports how the request was parsed so
 * the plumbing can be verified end to end.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function handle_echo( \WP_REST_Request $request ): \WP_REST_Response {
	$body = $request->get_body();

	return new \WP_REST_Response(
		array(
			'method'          => $request->get_method(),
			'content_type'    => $request->get_content_type(),
			'body_bytes'      => strlen( $body ),
			'json_params'     => $request->get_json_params(),
			'body_params'     => $request->get_body_params(),
			'query_params'    => $request->get_query_params(),
			'merged_params'   => $request->get_params(),
			'accepted_types'  => accepted_query_types(),
			'via_override'    => isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ),
		),
		200
	);
}
