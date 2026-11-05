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
 * not, core's callback has to be wrapped so its list can be sent again with
 * QUERY — which this plugin will keep needing for as long as it supports
 * cores that predate the filter, so the fallback is permanent rather than
 * temporary.
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

		// Before 7.2: see swap_cors_handler() for why it's decided at serve time.
		add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\swap_cors_handler', PHP_INT_MIN );
	},
	20
);

/**
 * Core's default CORS method list, plus QUERY. What send_cors_headers() sends
 * in place of core's list before 7.2.
 */
const CORS_METHODS = array( 'OPTIONS', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', METHOD );

/**
 * Wraps core's CORS handler with send_cors_headers() wherever it's hooked.
 * Before 7.2 only, as the first rest_pre_serve_request callback.
 *
 * Only while core's handler is hooked: a site that removed it has turned
 * REST CORS off, and adding ours anyway would turn it back on, echoing any
 * Origin with credentials allowed.
 *
 * When the response is served, not on rest_api_init: a site can remove or
 * move core's handler at any rest_api_init priority, after the plugin's.
 *
 * At every priority it's hooked at: remove_filter() matches one priority, so
 * a site that re-hooked it elsewhere, or twice, would otherwise have a copy
 * of core's that runs after ours and overwrites the method list.
 *
 * In place: only the entry's 'function' changes. Its key stays
 * 'rest_send_cors_headers', so a later remove_filter() of core's handler
 * still matches and removes ours, and its position stays, so a callback that
 * ran after core's at the same priority, such as a CORS plugin narrowing the
 * allowed origin, still runs after ours and still wins.
 *
 * Safe while the hook is running: WP_Hook::apply_filters() reads
 * $this->callbacks[ $priority ] afresh as it reaches each priority (checked
 * in class-wp-hook.php on 7.0 and 7.1), and no priority is added or removed.
 *
 * @param mixed $value Passthrough value.
 * @return mixed
 */
function swap_cors_handler( $value ) {
	global $wp_filter;

	$hook = $wp_filter['rest_pre_serve_request'];
	foreach ( array_keys( $hook->callbacks ) as $priority ) {
		if ( isset( $hook->callbacks[ $priority ]['rest_send_cors_headers'] ) ) {
			$hook->callbacks[ $priority ]['rest_send_cors_headers']['function'] = __NAMESPACE__ . '\\send_cors_headers';
		}
	}

	return $value;
}

/**
 * Core's rest_send_cors_headers(), then its method list again with QUERY.
 *
 * The second header() call replaces core's, as header() does by default
 * for a header of the same name. Core sends the list only for an Origin.
 *
 * @param mixed $value Passthrough value.
 * @param mixed ...$args Any further arguments the hook passes, forwarded.
 * @return mixed
 */
function send_cors_headers( $value, ...$args ) {
	$value = rest_send_cors_headers( $value, ...$args );

	if ( get_http_origin() && ! headers_sent() ) {
		header( 'Access-Control-Allow-Methods: ' . implode( ', ', CORS_METHODS ) );
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
