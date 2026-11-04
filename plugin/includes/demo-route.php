<?php
/**
 * The diagnostic echo route: admins only unless a site makes it public.
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
 * Demo route: admins only unless a site makes it public.
 *
 * QUERY /wp-json/wp-http-query/v1/echo reports how the server parsed the
 * request. It is always registered, because the self-test below sends QUERY
 * to it, but by default only users with manage_options may call it: anyone
 * else gets core's standard 401 (signed out) or 403. It reflects its input,
 * so making it public is a site's choice, through the
 * 'wp_http_query_register_demo_route' filter (named for when it controlled
 * registration; the name stays so existing opt-ins keep working):
 *
 *     add_filter( 'wp_http_query_register_demo_route', '__return_true' );
 *
 * The default is false, not WP_DEBUG: WP_DEBUG is often left on for staging
 * sites that are publicly reachable, and a route that opens up with a
 * debugging constant is a surprise. Opting in is one line.
 *
 * The route is registrable on stock core with no patch: register_rest_route()
 * has no method allowlist, set_method() only uppercases, and
 * match_request_to_handler() is a plain array-key lookup. See scope.md §2.
 *
 * Also reachable via the core X-HTTP-Method-Override tunnel:
 *   POST /wp-json/wp-http-query/v1/echo  +  X-HTTP-Method-Override: QUERY
 * That is a tunnel, NOT the method — intermediaries see POST, so RFC 10008
 * cacheability and retry safety do not apply.
 * ----------------------------------------------------------------------
 */

/** The echo route's key, as the REST server lists it. */
const ECHO_ROUTE = '/' . NAMESPACE_ . '/echo';

/**
 * Whether anyone may call the echo route, not just admins.
 *
 * The one place this is decided: the route's permission_callback and the
 * settings page's status table both read it.
 */
function demo_route_is_public(): bool {
	/**
	 * Filters whether the diagnostic echo route is public.
	 *
	 * The route is always registered. When this is false, only users with
	 * manage_options may call it.
	 *
	 * @param bool $public Whether anyone may call QUERY /wp-http-query/v1/echo. Default false.
	 */
	return (bool) apply_filters( 'wp_http_query_register_demo_route', false );
}

/**
 * Who may call the echo route: anyone when it's public, otherwise admins.
 *
 * Returning false lets core send its standard rest_forbidden error, 401 for a
 * signed-out request and 403 for a signed-in one.
 */
function can_call_echo(): bool {
	return demo_route_is_public() || current_user_can( 'manage_options' );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			NAMESPACE_,
			'/echo',
			array(
				'methods'             => METHOD,
				'permission_callback' => __NAMESPACE__ . '\\can_call_echo',
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
			'method'           => $request->get_method(),
			'content_type'     => $request->get_content_type(),
			'body_bytes'       => strlen( $body ),
			'json_params'      => $request->get_json_params(),
			'body_params'      => $request->get_body_params(),
			'query_params'     => $request->get_query_params(),
			'merged_params'    => $request->get_params(),
			'advertised_types' => advertised_query_types(),
			'via_override'     => isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ),
		),
		200
	);
}
