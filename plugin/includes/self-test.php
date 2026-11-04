<?php
/**
 * Self-test: the REST routes, the loopback checks, and the Site Health tests.
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
 * Self-test: does QUERY work on this hosting?
 *
 * Settings → HTTP QUERY has a "Test your server" button, and Site Health has
 * two tests. Between them they ask:
 *
 * 1. Does a QUERY, with its body, reach WordPress intact? The browser sends a
 *    real fetch() with method QUERY to the echo route; the server sends the
 *    same request to itself (a loopback, like Site Health's own). Both are
 *    signed in as the current admin, because the echo route is admins only.
 *
 * 2. Does a cache on the way store QUERY responses without keying on the
 *    body? Two different bodies go to the same URL, the public fingerprint
 *    route, signed out: caches usually skip signed-in requests, so a signed-in
 *    probe would always look clean. If the second answer is the first one
 *    again, something stored it. The browser path goes through any CDN; the
 *    loopback usually doesn't, but catches page-cache plugins, such as W3
 *    Total Cache Pro with REST caching on (experiments/cache-survey/).
 *
 * What a response means is decided here, in two pure functions,
 * diagnose_echo_response() and diagnose_cache_probe(), that take a status,
 * headers and body and return a verdict and a message. The loopback, Site
 * Health and the browser (through the diagnose route) all use them, and the
 * tests feed them fabricated responses. The causes they name come from the
 * compatibility matrix (matrix/README.md, matrix/results/MATRIX.md) and the
 * cache survey, and claim no more than those do.
 * ----------------------------------------------------------------------
 */

/** The body key the echo check puts its random marker under. */
const SELF_TEST_MARKER_KEY = 'wp_http_query_self_test';

/** The fingerprint route's key, as the REST server lists it. */
const FINGERPRINT_ROUTE = '/' . NAMESPACE_ . '/self-test/fingerprint';

/** Seconds a loopback request may take. Core's loopback test uses the same. */
const LOOPBACK_TIMEOUT = 10;

/** Site Health test keys, also the 'test' value in their results. */
const HEALTH_TEST_REACH = 'wp_http_query_reaches_wordpress';
const HEALTH_TEST_CACHE = 'wp_http_query_cache';

/**
 * Who may run the self-test and read its results.
 */
function can_run_self_test(): bool {
	return current_user_can( 'manage_options' );
}

add_action(
	'rest_api_init',
	static function (): void {
		/*
		 * Public on purpose, and safe to be: it echoes nothing back, not the
		 * body, not a parameter, not a header, so it reflects no input and
		 * reveals nothing. It returns a SHA-256 of the raw body it received
		 * and a value that is new on every request, which is all the cache
		 * probe needs to tell a fresh answer from a stored one. It reads and
		 * writes nothing. And it sends no Cache-Control of its own: ADR 0003,
		 * the plugin emits nothing method-specific, and the point is to see
		 * what the layers in front of WordPress do.
		 */
		register_rest_route(
			NAMESPACE_,
			'/self-test/fingerprint',
			array(
				'methods'             => METHOD,
				'permission_callback' => '__return_true',
				'callback'            => __NAMESPACE__ . '\\handle_fingerprint',
			)
		);

		register_rest_route(
			NAMESPACE_,
			'/self-test/server',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => __NAMESPACE__ . '\\can_run_self_test',
				'callback'            => static fn (): \WP_REST_Response => rest_ensure_response( health_result( HEALTH_TEST_REACH, run_server_check() ) ),
			)
		);

		register_rest_route(
			NAMESPACE_,
			'/self-test/cache',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => __NAMESPACE__ . '\\can_run_self_test',
				'callback'            => static fn (): \WP_REST_Response => rest_ensure_response( health_result( HEALTH_TEST_CACHE, run_cache_check() ) ),
			)
		);

		// What the browser saw, in; a verdict and message, out. Pure: it
		// sends nothing and stores nothing.
		register_rest_route(
			NAMESPACE_,
			'/self-test/diagnose',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => __NAMESPACE__ . '\\can_run_self_test',
				'callback'            => __NAMESPACE__ . '\\handle_diagnose',
				'args'                => array(
					'check' => array(
						'type'     => 'string',
						'enum'     => array( 'echo', 'cache' ),
						'required' => true,
					),
				),
			)
		);
	}
);

/**
 * Answers the cache probe: a hash of the body received, and a fresh value.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function handle_fingerprint( \WP_REST_Request $request ): \WP_REST_Response {
	return new \WP_REST_Response(
		array(
			'body_sha256' => hash( 'sha256', $request->get_body() ),
			'fresh'       => wp_generate_uuid4() . '@' . sprintf( '%.6F', microtime( true ) ),
		),
		200
	);
}

/**
 * Classifies what the browser saw.
 *
 * @param \WP_REST_Request $request Request with 'check' and the observation(s).
 * @return array
 */
function handle_diagnose( \WP_REST_Request $request ): array {
	if ( 'echo' === $request->get_param( 'check' ) ) {
		$seen = observation( $request->get_param( 'response' ) );
		return diagnose_echo_response( $seen['status'], $seen['headers'], $seen['body'], (string) $request->get_param( 'marker' ) );
	}

	return diagnose_cache_probe( observation( $request->get_param( 'first' ) ), observation( $request->get_param( 'second' ) ) );
}

/**
 * Sends a QUERY from the site to itself.
 *
 * Signed in as the current user when asked, the way core's loopback test
 * does it (WP_Site_Health::can_perform_loopback()): the browser's cookies
 * forwarded, plus a REST nonce, since cookie auth needs one. HTTP Basic auth
 * is passed on too, but only when signed in: an Authorization header makes
 * caches skip the request, which would hide what the cache probe looks for.
 *
 * 'redirection' => 0, so a redirect is reported rather than followed:
 * WP_Http turns any method into GET on a 302.
 *
 * @param string $url            URL.
 * @param string $body           JSON request body.
 * @param bool   $as_current_user Whether to sign in as the current user.
 * @return array{status: int, headers: array, body: string, error: string}
 */
function loopback_query( string $url, string $body, bool $as_current_user ): array {
	$headers = array( 'Content-Type' => 'application/json' );
	$cookies = array();

	if ( $as_current_user ) {
		$headers['X-WP-Nonce'] = wp_create_nonce( 'wp_rest' );
		// Forwarded verbatim, as core's loopback test does.
		$cookies = wp_unslash( $_COOKIE ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, passed on verbatim as core's loopback test does.
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
		}
	}

	$response = wp_remote_request(
		$url,
		array(
			'method'      => METHOD,
			'headers'     => $headers,
			'body'        => $body,
			'cookies'     => $cookies,
			'timeout'     => LOOPBACK_TIMEOUT,
			'redirection' => 0,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as core's loopback test does.
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status'  => 0,
			'headers' => array(),
			'body'    => '',
			'error'   => $response->get_error_message(),
		);
	}

	$headers = wp_remote_retrieve_headers( $response );

	return array(
		'status'  => (int) wp_remote_retrieve_response_code( $response ),
		'headers' => is_array( $headers ) ? $headers : $headers->getAll(),
		'body'    => wp_remote_retrieve_body( $response ),
		'error'   => '',
	);
}

/**
 * The server check: a signed-in QUERY loopback to the echo route.
 *
 * Without a signed-in user (Site Health's scheduled run, WP-CLI) it goes
 * signed out, and a 401 from the route still shows QUERY reached WordPress.
 *
 * @return array Verdict from diagnose_echo_response(), plus 'url'.
 */
function run_server_check(): array {
	$url           = rest_url( NAMESPACE_ . '/echo' );
	$marker        = wp_generate_password( 20, false );
	$authenticated = is_user_logged_in() && ! empty( $_COOKIE );
	$response      = loopback_query( $url, (string) wp_json_encode( array( SELF_TEST_MARKER_KEY => $marker ) ), $authenticated );

	return diagnose_echo_response( $response['status'], $response['headers'], $response['body'], $marker, $authenticated, $response['error'] ) + array( 'url' => $url );
}

/**
 * The cache check: two different signed-out QUERY loopbacks to one URL.
 *
 * @return array Verdict from diagnose_cache_probe(), plus 'url'.
 */
function run_cache_check(): array {
	$url       = rest_url( NAMESPACE_ . '/self-test/fingerprint' );
	$responses = array();

	foreach ( array( 1, 2 ) as $n ) {
		$sent        = (string) wp_json_encode( array( 'probe' => wp_generate_password( 20, false ) . $n ) );
		$responses[] = loopback_query( $url, $sent, false ) + array( 'sent' => $sent );
	}

	return diagnose_cache_probe( $responses[0], $responses[1] ) + array( 'url' => $url );
}

/**
 * A check's result as a Site Health test result, with the check attached.
 *
 * @param string $test  HEALTH_TEST_REACH or HEALTH_TEST_CACHE.
 * @param array  $check A verdict from run_server_check() or run_cache_check().
 * @return array
 */
function health_result( string $test, array $check ): array {
	$labels = array(
		HEALTH_TEST_REACH => array(
			'good'        => __( 'HTTP QUERY requests reach WordPress', 'wp-http-query' ),
			'recommended' => __( 'Couldn’t confirm that HTTP QUERY requests reach WordPress', 'wp-http-query' ),
			'critical'    => __( 'HTTP QUERY requests don’t reach WordPress intact', 'wp-http-query' ),
		),
		HEALTH_TEST_CACHE => array(
			'good'        => __( 'No cache was seen storing HTTP QUERY responses', 'wp-http-query' ),
			'recommended' => __( 'Couldn’t check whether a cache stores HTTP QUERY responses', 'wp-http-query' ),
			'critical'    => __( 'A cache stores HTTP QUERY responses without the request body', 'wp-http-query' ),
		),
	);

	$description = sprintf( '<p>%s</p>', esc_html( $check['message'] ) );

	$statuses = $check['http_statuses'] ?? ( $check['http_status'] ? array( $check['http_status'] ) : array() );
	if ( $statuses ) {
		$description .= sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: HTTP status code(s). 2: URL. */
					__( 'HTTP status: %1$s. URL tested: %2$s', 'wp-http-query' ),
					implode( ', ', $statuses ),
					$check['url'] ?? ''
				)
			)
		);
	}

	if ( ! empty( $check['cache_headers'] ) ) {
		$description .= sprintf( '<p>%s</p><ul>', esc_html__( 'Cache-related response headers seen (hints, not proof):', 'wp-http-query' ) );
		foreach ( $check['cache_headers'] as $seen ) {
			$description .= sprintf(
				'<li><code>#%d %s: %s</code>%s</li>',
				(int) ( $seen['response'] ?? 0 ),
				esc_html( $seen['header'] ),
				esc_html( $seen['value'] ),
				'' !== $seen['hint'] ? ' (' . esc_html( $seen['hint'] ) . ')' : ''
			);
		}
		$description .= '</ul>';
	}

	if ( HEALTH_TEST_CACHE === $test ) {
		$description .= sprintf( '<p>%s</p>', esc_html( self_test_limits() ) );
	}

	return array(
		'label'       => $labels[ $test ][ $check['status'] ],
		'status'      => $check['status'],
		'badge'       => array(
			/* translators: Site Health badge. QUERY is the HTTP method's name; keep it as is. */
			'label' => __( 'HTTP QUERY', 'wp-http-query' ),
			'color' => 'good' === $check['status'] ? 'blue' : ( 'critical' === $check['status'] ? 'red' : 'orange' ),
		),
		'description' => $description,
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=' . SETTINGS_PAGE ) ),
			esc_html__( 'Test from your browser too, on Settings → HTTP QUERY', 'wp-http-query' )
		),
		'test'        => $test,
		'check'       => $check,
	);
}

add_filter(
	'site_status_tests',
	static function ( array $tests ): array {
		// Async, like core's loopback test: each run sends requests to the site.
		$tests['async'][ HEALTH_TEST_REACH ] = array(
			'label'             => __( 'HTTP QUERY requests reach WordPress', 'wp-http-query' ),
			'test'              => rest_url( NAMESPACE_ . '/self-test/server' ),
			'has_rest'          => true,
			'async_direct_test' => static fn (): array => health_result( HEALTH_TEST_REACH, run_server_check() ),
		);
		$tests['async'][ HEALTH_TEST_CACHE ] = array(
			'label'             => __( 'No cache stores HTTP QUERY responses', 'wp-http-query' ),
			'test'              => rest_url( NAMESPACE_ . '/self-test/cache' ),
			'has_rest'          => true,
			'async_direct_test' => static fn (): array => health_result( HEALTH_TEST_CACHE, run_cache_check() ),
		);

		return $tests;
	}
);
