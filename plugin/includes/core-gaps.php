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
 * Gap 1 — Access-Control-Allow-Methods is a hardcoded string in core.
 *
 * rest_send_cors_headers() emits the list with a raw header() call and no hook
 * at that point, so a cross-origin QUERY preflight fails.
 *
 * Trac#46992 added a 'rest_allowed_cors_methods' filter in 7.2 (r64730).
 * Where that exists, one add_filter does the whole job. Where it does
 * not, core's callback has to be unhooked and replaced wholesale — which this
 * plugin will keep needing for as long as it supports cores that predate the
 * filter, so the fallback is permanent rather than temporary.
 * ----------------------------------------------------------------------
 */

/**
 * Whether core's CORS method list is filterable.
 *
 * Feature detection, not version sniffing: the $result, $request and $server
 * parameters arrived in the same change as the filter (r64730), and an alpha build of the release
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
	} elseif ( ! headers_sent() && isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] && ! is_user_logged_in() ) {
		header( 'Vary: Origin', false );
	}

	return $value;
}

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

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server ) {
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
				$list = implode(
					', ',
					array_map(
						static fn ( string $type ): string => '"' . $type . '"',
						advertised_query_types()
					)
				);
				$response->header( 'Accept-Query', $list );
				break;
			}
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
