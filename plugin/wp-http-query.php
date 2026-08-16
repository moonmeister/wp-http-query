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
 * Deliberately just JSON for now — see ADR 0001, which is undecided. Advertising
 * this is not the same as core blessing it.
 */
function accepted_query_types(): array {
	return (array) apply_filters( 'wp_http_query_accepted_types', array( 'application/json' ) );
}

/* -------------------------------------------------------------------------
 * Gap 1 — Access-Control-Allow-Methods is a hardcoded string in core.
 *
 * rest-api.php:814 emits it with no filter at that point, so a cross-origin
 * QUERY preflight fails. Core's callback is unhooked and replaced.
 * ---------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	static function (): void {
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
 * Cache safety (ADR 0003 — undecided; this is the conservative default).
 *
 * A cache that does not understand QUERY may key by URI alone, colliding two
 * different request bodies. There is no Vary mechanism that prevents this —
 * Vary works on headers, and there is no Vary: body. So the origin must be
 * conservative until the operator asserts their edge is body-aware.
 * ---------------------------------------------------------------------- */

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		if ( ! $response instanceof \WP_REST_Response || METHOD !== $request->get_method() ) {
			return $response;
		}

		/**
		 * Whether this deployment's edge implements RFC 10008 §2.7 body-inclusive
		 * cache keys. Body-aware-ness is a property of the DEPLOYMENT, not of any
		 * route — which is why this is a site-level signal, not a route argument.
		 *
		 * Do not set this true unless you know your cache keys on request content.
		 */
		if ( ! apply_filters( 'wp_http_query_edge_is_body_aware', false ) ) {
			$response->header( 'Cache-Control', 'private, no-cache' );
		}

		return $response;
	},
	12,
	3
);

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
