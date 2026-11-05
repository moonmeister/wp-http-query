<?php
/**
 * Stand-ins for core gaps: CORS (gap 1), body parameter order (gap 3), the Accept-Query header, and the cache-safety decision.
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
 * Gap 1 — Access-Control-Allow-Methods must include QUERY.
 *
 * Without it a cross-origin QUERY preflight fails. Core's list is filterable
 * from 7.2 (Trac#46992, r64730), the plugin's minimum. A site that removed
 * core's CORS handler never runs the filter, so its REST CORS stays off.
 * ----------------------------------------------------------------------
 */

add_filter(
	'rest_allowed_cors_methods',
	static function ( array $methods ): array {
		if ( ! in_array( METHOD, $methods, true ) ) {
			$methods[] = METHOD;
		}

		return $methods;
	}
);

/*
 * -------------------------------------------------------------------------
 * Gap 3 — get_parameter_order() excludes QUERY from body-data parsing.
 *
 * class-wp-rest-request.php:377-380 hardcodes POST/PUT/PATCH/DELETE, so a
 * form-encoded QUERY body is parsed into params['POST'] and then never looked
 * up. JSON bodies are unaffected (parse_json_params runs unconditionally).
 *
 * Fixable from userland via the rest_request_parameter_order filter, which
 * usefully proves the one-line core fix is correct before anyone patches it.
 * ----------------------------------------------------------------------
 */

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

/*
 * -------------------------------------------------------------------------
 * Accept-Query advertisement (RFC 10008 §3).
 *
 * Structured Fields List syntax — NOT the legacy Accept grammar, despite the
 * surface similarity. Emitted alongside core's generic Allow header logic.
 * ----------------------------------------------------------------------
 */

/**
 * Whether any of a route's normalized handlers accepts QUERY.
 *
 * The one test for "this route answers QUERY": the Accept-Query header and
 * the settings page's status table both use it.
 *
 * @param array $handlers A route's handlers, from WP_REST_Server::get_routes().
 */
function accepts_query( array $handlers ): bool {
	foreach ( $handlers as $handler ) {
		if ( ! empty( $handler['methods'][ METHOD ] ) ) {
			return true;
		}
	}

	return false;
}

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server ) {
		// Per route, once per request: get_routes() rebuilds the whole route
		// table, and an embedding request dispatches many sub-responses.
		static $answers = array();

		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		$route = $response->get_matched_route();
		if ( ! $route ) {
			return $response;
		}

		if ( ! isset( $answers[ $route ] ) ) {
			$answers[ $route ] = accepts_query( $server->get_routes()[ $route ] ?? array() );
		}

		if ( $answers[ $route ] ) {
			$response->header(
				'Accept-Query',
				implode(
					', ',
					array_map(
						static fn ( string $type ): string => '"' . $type . '"',
						advertised_query_types()
					)
				)
			);
		}

		return $response;
	},
	11,
	2
);

/*
 * -------------------------------------------------------------------------
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
 * ----------------------------------------------------------------------
 */
