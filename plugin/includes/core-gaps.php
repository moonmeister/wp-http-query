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

		/*
		 * Swap core's handler only while it's hooked, and in its place.
		 * A site that removed it has turned REST CORS off: adding ours anyway
		 * would turn it back on, echoing any Origin with credentials allowed.
		 * A site that re-hooked it at another priority, or at more than one,
		 * would otherwise get both, since remove_filter() only matches the
		 * priority it's given, and core's, running later, would overwrite
		 * our method list.
		 *
		 * Decided when the response is served, not here: a site can remove or
		 * move core's handler at any rest_api_init priority, after this one.
		 * swap_cors_handler() runs first on rest_pre_serve_request and
		 * replaces core's callback in place, so ours runs exactly where
		 * core's would have, after anything core's ran after and before
		 * anything that ran after it.
		 *
		 * The filter path above needs none of this: once core's handler is
		 * gone, rest_allowed_cors_methods never runs.
		 */
		add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\swap_cors_handler', PHP_INT_MIN );
	},
	20
);

/**
 * Swaps core's CORS handler for send_cors_headers() in place, at every
 * priority it's hooked at. Before 7.2 only, as the first
 * rest_pre_serve_request callback.
 *
 * In place, not remove_filter() plus add_filter(): add_filter() appends to
 * the end of a priority, so a callback added after core's at the same
 * priority, such as a CORS plugin narrowing the allowed origin, would run
 * before ours and be overwritten. Rebuilding the priority's array with ours
 * under core's position keeps the order.
 *
 * Safe while the hook is running: WP_Hook::apply_filters() reads
 * $this->callbacks[ $priority ] afresh as it reaches each priority (checked
 * in class-wp-hook.php on 7.0 and 7.1), so a priority after this callback's
 * PHP_INT_MIN runs the replaced array. The set of priorities doesn't change,
 * so no running iteration needs re-sorting.
 *
 * @param mixed $value Passthrough value.
 * @return mixed
 */
function swap_cors_handler( $value ) {
	global $wp_filter;

	$hook = $wp_filter['rest_pre_serve_request'] ?? null;
	if ( ! $hook instanceof \WP_Hook ) {
		return $value;
	}

	// WP_Hook keys a function-name callback by the name itself.
	$core = 'rest_send_cors_headers';
	$ours = __NAMESPACE__ . '\\send_cors_headers';

	foreach ( $hook->callbacks as $priority => $callbacks ) {
		// The priority running now has already been read; it can only be ours.
		if ( PHP_INT_MIN === $priority || ! isset( $callbacks[ $core ] ) ) {
			continue;
		}

		$replaced = array();
		foreach ( $callbacks as $id => $callback ) {
			if ( $id === $core ) {
				$replaced[ $ours ] = array(
					'function'      => $ours,
					'accepted_args' => 1,
				);
			} else {
				$replaced[ $id ] = $callback;
			}
		}

		$hook->callbacks[ $priority ] = $replaced;
	}

	return $value;
}

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
