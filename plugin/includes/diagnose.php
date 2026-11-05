<?php
/**
 * Self-test classification: what a response means, in plain language. Pure functions, no I/O.
 *
 * @package WPHttpQuery
 */

declare( strict_types = 1 );

namespace WPHttpQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coerces an observation of one request into one shape.
 *
 * Every diagnose function takes observations: what came back from a
 * loopback, or what the browser posted to the diagnose route. This is the
 * one place they are cast and their headers normalized.
 *
 * - status, headers, body: the response. status 0: there was none.
 * - error: why the request failed, if it did.
 * - sent: the request body, for the cache probe.
 * - redirect: true when the browser saw an opaque redirect, so no status.
 * - unsupported: true when the browser refused to build a QUERY request.
 * - client: 'browser' or 'server', which sent the request. Only the wording
 *   of a network error depends on it.
 *
 * @param mixed $raw An observation, or anything decoded from JSON.
 * @return array{status: int, headers: array<string, string>, body: string, sent: string, error: string, redirect: bool, unsupported: bool, client: string}
 */
function observation( $raw ): array {
	$raw     = is_array( $raw ) ? $raw : array();
	$headers = array();

	foreach ( (array) ( $raw['headers'] ?? array() ) as $name => $value ) {
		if ( is_string( $name ) && ( is_scalar( $value ) || is_array( $value ) ) ) {
			$headers[ $name ] = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : $value;
		}
	}

	return array(
		'status'      => is_numeric( $raw['status'] ?? null ) ? (int) $raw['status'] : 0,
		'headers'     => normalize_headers( $headers ),
		'body'        => is_string( $raw['body'] ?? null ) ? $raw['body'] : '',
		'sent'        => is_string( $raw['sent'] ?? null ) ? $raw['sent'] : '',
		'error'       => is_string( $raw['error'] ?? null ) ? $raw['error'] : '',
		'redirect'    => true === ( $raw['redirect'] ?? null ),
		'unsupported' => true === ( $raw['unsupported'] ?? null ),
		'client'      => 'browser' === ( $raw['client'] ?? null ) ? 'browser' : 'server',
	);
}

/**
 * Header names lower-cased, and repeated headers joined with ", ".
 *
 * @param array $headers Header name => value or list of values.
 * @return array<string, string>
 */
function normalize_headers( array $headers ): array {
	$normalized = array();

	foreach ( $headers as $name => $value ) {
		$normalized[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
	}

	return $normalized;
}

/**
 * A WordPress REST error in a response body, if that's what it is.
 *
 * @param string $body Response body.
 * @return array{code: string, message: string}|null
 */
function rest_error_in( string $body ): ?array {
	$data = json_decode( $body, true );

	if ( is_array( $data ) && is_string( $data['code'] ?? null ) && is_string( $data['message'] ?? null ) ) {
		return array(
			'code'    => $data['code'],
			'message' => $data['message'],
		);
	}

	return null;
}

/**
 * Site Health status for each verdict.
 *
 * Critical when QUERY doesn't reach WordPress intact, a cache serves one
 * body's answer to another, or CORS is on but leaves QUERY out; recommended
 * when the test couldn't tell, or the site turned REST CORS off.
 *
 * @return array<string, string>
 */
function verdict_statuses(): array {
	return array(
		'pass'                 => 'good',
		'reached'              => 'good',
		'clean'                => 'good',
		'no_accept_query'      => 'recommended',
		'wordpress_error'      => 'recommended',
		'network_error'        => 'recommended',
		'unexpected'           => 'recommended',
		'inconclusive'         => 'recommended',
		'altered'              => 'recommended',
		'not_allowed'          => 'critical',
		'forbidden'            => 'critical',
		'not_implemented'      => 'critical',
		'redirect'             => 'critical',
		'method_changed'       => 'critical',
		'body_lost'            => 'critical',
		'stored'               => 'critical',
		'cors_query'           => 'good',
		'cors_off'             => 'recommended',
		'cors_other_origin'    => 'recommended',
		'cors_no_query'        => 'critical',
		'cors_no_content_type' => 'critical',
		'browser_unsupported'  => 'recommended',
		// Made by the script, not here: the test itself couldn't run.
		'test_failed'          => 'recommended',
	);
}

/**
 * Builds a verdict.
 *
 * @param string $verdict       One of verdict_statuses()' keys.
 * @param string $message       One-line explanation, translated.
 * @param int[]  $http_statuses Status codes of the responses, empty if there were none.
 * @return array{verdict: string, status: string, http_statuses: int[], message: string}
 */
function verdict( string $verdict, string $message, array $http_statuses ): array {
	return array(
		'verdict'       => $verdict,
		'status'        => verdict_statuses()[ $verdict ],
		'http_statuses' => array_values( array_filter( $http_statuses ) ),
		'message'       => $message,
	);
}

/**
 * The verdict for a browser that refused to build a QUERY request.
 *
 * @return array{verdict: string, status: string, http_statuses: int[], message: string}
 */
function browser_unsupported(): array {
	return verdict( 'browser_unsupported', __( 'Your browser can’t send QUERY yet, so this check couldn’t run. That’s the browser, not your server: the server checks still apply.', 'wp-http-query' ), array() );
}

/**
 * What a QUERY to the echo route got back, in plain language.
 *
 * Pure: no I/O, no globals. The causes named come from the compatibility
 * matrix and the cache survey.
 *
 * @param array  $response      An observation (see observation()).
 * @param string $marker        The random value sent under SELF_TEST_MARKER_KEY.
 * @param bool   $authenticated Whether the request was signed in. A signed-out
 *                              request to the admins-only route expects a 401.
 * @return array{verdict: string, status: string, http_statuses: int[], message: string}
 */
function diagnose_echo_response( array $response, string $marker, bool $authenticated = true ): array {
	$seen    = observation( $response );
	$status  = $seen['status'];
	$headers = $seen['headers'];
	$body    = $seen['body'];
	$error   = $seen['error'];

	if ( $seen['unsupported'] ) {
		return browser_unsupported();
	}

	if ( 'browser' === $seen['client'] && ! $seen['redirect'] && ( '' !== $error || 0 === $status ) ) {
		return verdict( 'network_error', __( 'The request didn’t complete: the browser reported a network error before any response arrived. A browser extension, proxy or firewall may have blocked it.', 'wp-http-query' ), array() );
	}

	if ( ! $seen['redirect'] && ( '' !== $error || 0 === $status ) ) {
		return verdict(
			'network_error',
			sprintf(
				/* translators: %s: The error message from the HTTP request. */
				__( 'The request didn’t complete (%s). That says nothing about QUERY yet: the site couldn’t reach its own address. If Site Health’s loopback test fails too, fix that first.', 'wp-http-query' ),
				'' !== $error ? $error : __( 'no response', 'wp-http-query' )
			),
			array()
		);
	}

	if ( $seen['redirect'] || ( $status >= 300 && $status < 400 ) ) {
		return verdict(
			'redirect',
			sprintf(
				/* translators: %s: The URL the response redirected to. */
				__( 'The request was redirected to %s. A client that follows a redirect may switch QUERY to GET and drop the body: WordPress’s own HTTP API does on a 302, browsers on a 303. Check that the Site Address in Settings → General matches the address the site is served from, including https and www.', 'wp-http-query' ),
				'' !== ( $headers['location'] ?? '' ) ? $headers['location'] : __( 'another address', 'wp-http-query' )
			),
			array( $status )
		);
	}

	if ( 200 === $status ) {
		$echo = json_decode( $body, true );

		if ( ! is_array( $echo ) || ! isset( $echo['method'] ) ) {
			return verdict( 'unexpected', __( 'Something answered 200, but not with the test route’s reply. A cache or proxy in front of WordPress may have answered instead.', 'wp-http-query' ), array( $status ) );
		}

		if ( METHOD !== $echo['method'] ) {
			return verdict(
				'method_changed',
				sprintf(
					/* translators: %s: The HTTP method WordPress received, such as GET. */
					__( 'WordPress received the request as %s, not QUERY. Something between the client and WordPress changed the method.', 'wp-http-query' ),
					is_string( $echo['method'] ) ? $echo['method'] : '?'
				),
				array( $status )
			);
		}

		$received = $echo['merged_params'][ SELF_TEST_MARKER_KEY ] ?? null;
		if ( $received !== $marker ) {
			$message = empty( $echo['body_bytes'] )
				? __( 'QUERY reached WordPress, but its body didn’t: WordPress saw an empty body. Something between the client and WordPress dropped it.', 'wp-http-query' )
				: __( 'QUERY reached WordPress, but its body arrived changed: the test value sent in it didn’t come back.', 'wp-http-query' );
			return verdict( 'body_lost', $message, array( $status ) );
		}

		if ( '' === ( $headers['accept-query'] ?? '' ) ) {
			return verdict( 'no_accept_query', __( 'QUERY reached WordPress intact, but the Accept-Query response header was missing. Something in front of WordPress may be removing it, so clients can’t see which body format to send.', 'wp-http-query' ), array( $status ) );
		}

		return verdict( 'pass', __( 'QUERY reached WordPress with its method and body intact, and the Accept-Query header came back.', 'wp-http-query' ), array( $status ) );
	}

	$rest_error = rest_error_in( $body );
	if ( null !== $rest_error ) {
		if ( 404 === $status && 'rest_no_route' === $rest_error['code'] ) {
			return verdict( 'method_changed', __( 'WordPress got the request but not as QUERY: the test route answers only QUERY, and WordPress found no route for the method it saw. Something between the client and WordPress may have changed the method.', 'wp-http-query' ), array( $status ) );
		}

		// The permission check runs only once a QUERY handler has matched, so
		// this 401 shows the method arrived as QUERY.
		if ( 401 === $status && ! $authenticated && 'rest_forbidden' === $rest_error['code'] ) {
			return verdict( 'reached', __( 'QUERY reached WordPress: the admins-only test route turned away the signed-out request with 401, which happens only after WordPress matched the QUERY method. The body wasn’t checked; the button on Settings → HTTP QUERY checks it.', 'wp-http-query' ), array( $status ) );
		}

		if ( 401 === $status || 403 === $status ) {
			return verdict(
				'wordpress_error',
				sprintf(
					/* translators: %s: A WordPress REST API error code, such as rest_forbidden. */
					__( 'QUERY reached WordPress, but WordPress didn’t accept the test’s sign-in (%s), so the body couldn’t be checked. Reload the page and try again.', 'wp-http-query' ),
					$rest_error['code']
				),
				array( $status )
			);
		}

		return verdict(
			'wordpress_error',
			sprintf(
				/* translators: 1: A WordPress REST API error code. 2: Its message. */
				__( 'WordPress answered with an error (%1$s: %2$s), so the test couldn’t tell whether QUERY works.', 'wp-http-query' ),
				$rest_error['code'],
				wp_strip_all_tags( $rest_error['message'] )
			),
			array( $status )
		);
	}

	if ( 405 === $status ) {
		return verdict( 'not_allowed', __( 'The web server refused QUERY before WordPress saw it. nginx does this when the request goes to the site root (/ or /?rest_route=…) instead of /index.php or /wp-json/: its index module answers 405 first. Check the URL tested; with plain permalinks, send QUERY to /index.php?rest_route=… instead.', 'wp-http-query' ), array( $status ) );
	}

	if ( 403 === $status ) {
		return verdict( 'forbidden', __( 'A server rule or firewall refused QUERY before WordPress saw it. Rules that allow only GET and POST do this, such as nginx’s limit_except GET POST or Apache’s <LimitExcept GET POST>. Apache can add QUERY to that list; nginx can’t name QUERY there, so ask your host.', 'wp-http-query' ), array( $status ) );
	}

	if ( 501 === $status ) {
		$message = __( 'Something in front of WordPress doesn’t implement QUERY. Varnish 8.0 to 9.0.x answers QUERY with 501 itself (9.1.0 and later pass it through; for older versions, add if (req.method == "QUERY") { return (pass); } to vcl_req_method), and so does PHP’s built-in server (php -S).', 'wp-http-query' );
		if ( in_array( 'Varnish', array_column( cache_header_hints( $headers ), 'hint' ), true ) ) {
			$message .= ' ' . __( 'This response has Varnish headers.', 'wp-http-query' );
		}
		return verdict( 'not_implemented', $message, array( $status ) );
	}

	return verdict( 'unexpected', __( 'Unexpected response, so the test couldn’t tell whether QUERY works.', 'wp-http-query' ), array( $status ) );
}

/**
 * What a response header suggests about the cache or proxy that sent it.
 *
 * The one place headers map to vendors. A hint, not proof: anything can send
 * any header, and many are shared between vendors. Returns null for headers
 * that say nothing about caching, and '' for ones worth showing that name no
 * vendor (Cache-Control). LiteSpeed is named from its headers only; this
 * project hasn't tested it (matrix/README.md).
 *
 * @param string $name  Header name, lower case.
 * @param string $value Header value.
 * @return string|null
 */
function cache_header_hint( string $name, string $value ): ?string {
	$value = strtolower( $value );

	switch ( $name ) {
		case 'age':
			return __( 'set by a cache: seconds it has held this response', 'wp-http-query' );
		case 'cache-control':
			return '';
		case 'x-cache':
			return __( 'a CDN or proxy cache; Fastly, CloudFront and Squid are among those that send it', 'wp-http-query' );
		case 'cf-cache-status':
			return 'Cloudflare';
		case 'x-varnish':
			return 'Varnish';
		case 'x-served-by':
			return __( 'a CDN; Fastly sends it', 'wp-http-query' );
		case 'x-litespeed-cache':
			return 'LiteSpeed';
		case 'x-kinsta-cache':
			return 'Kinsta';
		case 'x-proxy-cache':
		case 'x-cache-status':
			return __( 'an nginx proxy or FastCGI cache, as many hosts configure it', 'wp-http-query' );
		case 'x-cacheable':
			return __( 'a host’s page cache', 'wp-http-query' );
		case 'via':
			if ( str_contains( $value, 'varnish' ) ) {
				return 'Varnish';
			}
			if ( str_contains( $value, 'cloudfront' ) ) {
				return 'Amazon CloudFront';
			}
			return __( 'a proxy', 'wp-http-query' );
		case 'server':
			foreach ( array(
				'litespeed'  => 'LiteSpeed',
				'cloudflare' => 'Cloudflare',
				'varnish'    => 'Varnish',
			) as $needle => $vendor ) {
				if ( str_contains( $value, $needle ) ) {
					return $vendor;
				}
			}
			return null;
	}

	if ( preg_match( '/^x-(?:fastcgi|nginx|srcache|proxy)[-_]?cache/', $name ) ) {
		return __( 'an nginx proxy or FastCGI cache, as many hosts configure it', 'wp-http-query' );
	}

	return null;
}

/**
 * The cache-related headers in a response, each with its hint.
 *
 * @param array $headers Response headers.
 * @return array<int, array{header: string, value: string, hint: string}>
 */
function cache_header_hints( array $headers ): array {
	$seen = array();

	foreach ( normalize_headers( $headers ) as $name => $value ) {
		$hint = cache_header_hint( $name, $value );
		if ( null !== $hint ) {
			$seen[] = array(
				'header' => $name,
				'value'  => $value,
				'hint'   => $hint,
			);
		}
	}

	return $seen;
}

/**
 * Whether a cache answered the second of two different QUERY bodies, sent to
 * the same URL, with the first one's response.
 *
 * Pure: no I/O, no globals. Each observation is what the fingerprint route
 * returned, plus what was sent to it.
 *
 * @param array $first  An observation (see observation()), with 'sent'.
 * @param array $second The same, for the second request.
 * @return array{verdict: string, status: string, http_statuses: int[], message: string, cache_headers: array}
 */
function diagnose_cache_probe( array $first, array $second ): array {
	$responses = array();
	$headers   = array();

	foreach ( array( $first, $second ) as $n => $raw ) {
		$seen        = observation( $raw );
		$data        = json_decode( $seen['body'], true );
		$responses[] = array(
			'seen'  => $seen,
			'sent'  => hash( 'sha256', $seen['sent'] ),
			'hash'  => is_array( $data ) && is_string( $data['body_sha256'] ?? null ) ? $data['body_sha256'] : null,
			'fresh' => is_array( $data ) && is_string( $data['fresh'] ?? null ) ? $data['fresh'] : null,
		);
		foreach ( cache_header_hints( $seen['headers'] ) as $hint ) {
			$headers[] = array( 'response' => $n + 1 ) + $hint;
		}
	}

	list( $a, $b ) = $responses;
	$statuses      = array( $a['seen']['status'], $b['seen']['status'] );
	$result        = static function ( string $verdict, string $message ) use ( $statuses, $headers ): array {
		return verdict( $verdict, $message, $statuses ) + array( 'cache_headers' => $headers );
	};

	foreach ( $responses as $response ) {
		$seen = $response['seen'];
		if ( $seen['unsupported'] ) {
			return browser_unsupported() + array( 'cache_headers' => $headers );
		}
		if ( $seen['redirect'] ) {
			return $result( 'inconclusive', __( 'Couldn’t check for caching: a probe request was redirected instead of getting the probe route’s answer. The QUERY check above says why QUERY isn’t getting through.', 'wp-http-query' ) );
		}
		if ( '' !== $seen['error'] || 0 === $seen['status'] ) {
			return $result(
				'inconclusive',
				sprintf(
					/* translators: %s: The error message from the HTTP request. */
					__( 'Couldn’t check for caching: a probe request didn’t complete (%s).', 'wp-http-query' ),
					'' !== $seen['error'] ? $seen['error'] : __( 'no response', 'wp-http-query' )
				)
			);
		}
		if ( 200 !== $seen['status'] || null === $response['hash'] || null === $response['fresh'] ) {
			return $result(
				'inconclusive',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Couldn’t check for caching: a probe request got HTTP %d instead of the probe route’s answer. The QUERY check above says why QUERY isn’t getting through.', 'wp-http-query' ),
					$seen['status']
				)
			);
		}
	}

	/*
	 * Stored: the second answer repeats the first one's fresh value, or
	 * carries the hash of the body the first probe sent. Compared with what
	 * was sent, not between the two responses: if both bodies were stripped
	 * on the way, both hashes are the empty body's, and that's a lost body,
	 * not a cache.
	 */
	$repeated_sent = $a['sent'] !== $b['sent'] && $b['hash'] === $a['sent'];
	if ( $repeated_sent || $a['fresh'] === $b['fresh'] ) {
		$vendors = array_unique( array_filter( array_column( $headers, 'hint' ), static fn ( string $hint ): bool => '' !== $hint && ! str_contains( $hint, ' ' ) ) );
		$message = __( 'A cache between you and WordPress is storing QUERY responses without keying on the body: two different requests to the same URL got the same answer, so one visitor’s search can get another’s results.', 'wp-http-query' );
		if ( $vendors ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Comma-separated vendor names, such as Varnish or Cloudflare. */
				__( 'The response headers point to %s (a hint, not proof).', 'wp-http-query' ),
				implode( ', ', $vendors )
			);
		}
		if ( in_array( 'LiteSpeed', $vendors, true ) ) {
			$message .= ' ' . __( 'This project hasn’t tested LiteSpeed, so this is an observation from this site only.', 'wp-http-query' );
		}
		return $result( 'stored', $message );
	}

	if ( $a['hash'] !== $a['sent'] || $b['hash'] !== $b['sent'] ) {
		return $result( 'altered', __( 'Couldn’t check for caching: a probe’s body was lost or changed on the way to WordPress, so its answers can’t be compared. The QUERY check above tests whether bodies get through.', 'wp-http-query' ) );
	}

	return $result( 'clean', __( 'No caching detected: two different QUERY bodies sent to the same URL got two different, fresh answers.', 'wp-http-query' ) );
}

/**
 * A comma-separated list of HTTP tokens, as fetch parses a CORS preflight's
 * Access-Control-Allow-Methods and -Headers: null if any entry isn't a token,
 * which makes a browser refuse the whole preflight. Empty entries are skipped.
 *
 * @param string $value Header value.
 * @return string[]|null
 */
function cors_token_list( string $value ): ?array {
	$tokens = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );

	foreach ( $tokens as $token ) {
		if ( ! preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $token ) ) {
			return null;
		}
	}

	return $tokens;
}

/**
 * What the site answered a cross-origin QUERY preflight, in plain language.
 *
 * Pure: no I/O, no globals. Reads Access-Control-Allow-Methods the way a
 * browser does: exact, case-sensitive tokens (QUERY isn't one of the methods
 * fetch normalizes), with "*" a wildcard for requests sent without cookies.
 * Success also needs Access-Control-Allow-Origin to admit the probe's origin
 * and Access-Control-Allow-Headers to admit Content-Type (the preflight asks
 * for it, as a JSON QUERY needs it), and both lists to parse (see
 * cors_token_list()), or a browser refuses the preflight. Access-Control-Allow-Headers and
 * -Expose-Headers don't count as CORS being on:
 * WP_REST_Server::serve_request() sends them on every REST response, CORS on
 * or off.
 *
 * @param array  $response   An observation (see observation()) of the OPTIONS response.
 * @param string $origin     The Origin the preflight was sent with.
 * @param bool   $filterable Whether core's method list is filterable (7.2+, core_cors_is_filterable()).
 * @return array{verdict: string, status: string, http_statuses: int[], message: string}
 */
function diagnose_cors_preflight( array $response, string $origin, bool $filterable ): array {
	$seen    = observation( $response );
	$status  = $seen['status'];
	$headers = $seen['headers'];

	if ( '' !== $seen['error'] || 0 === $status ) {
		return verdict(
			'inconclusive',
			sprintf(
				/* translators: %s: The error message from the HTTP request. */
				__( 'Couldn’t check CORS: the preflight didn’t complete (%s).', 'wp-http-query' ),
				'' !== $seen['error'] ? $seen['error'] : __( 'no response', 'wp-http-query' )
			),
			array()
		);
	}

	if ( $status < 200 || $status >= 300 ) {
		return verdict(
			'inconclusive',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Couldn’t check CORS: the preflight got HTTP %d instead of WordPress’s answer. Browsers treat a preflight that doesn’t succeed as a refusal, so if something in front of WordPress refuses OPTIONS requests, other sites can’t send QUERY.', 'wp-http-query' ),
				$status
			),
			array( $status )
		);
	}

	if ( ! isset( $headers['access-control-allow-origin'] ) && ! isset( $headers['access-control-allow-methods'] ) ) {
		return verdict( 'cors_off', __( 'No CORS headers came back for the test’s origin, so a page there can’t call this site’s REST API from a browser, with any method. Usually the site or a plugin turned REST CORS off, and the plugin respects that rather than turning it back on. A CORS plugin that allows only some origins answers the others this way too.', 'wp-http-query' ), array( $status ) );
	}

	$methods  = $headers['access-control-allow-methods'] ?? '';
	$allowed  = cors_token_list( $methods ) ?? array();
	$named    = in_array( METHOD, $allowed, true );
	$wildcard = ! $named && in_array( '*', $allowed, true );

	if ( $named || $wildcard ) {
		$allow_origin = $headers['access-control-allow-origin'] ?? '';

		if ( $origin !== $allow_origin && '*' !== $allow_origin ) {
			return verdict(
				'cors_other_origin',
				sprintf(
					/* translators: 1: The Access-Control-Allow-Methods header value. 2: The Access-Control-Allow-Origin header value. 3: The origin the test sent. */
					__( 'The preflight’s Access-Control-Allow-Methods (%1$s) allows QUERY, but its Access-Control-Allow-Origin (%2$s) doesn’t admit the test’s origin, %3$s, so the test couldn’t confirm a browser gets through. A CORS plugin that allows only some origins does this; pages on the origins it allows can likely send QUERY.', 'wp-http-query' ),
					$methods,
					'' !== $allow_origin ? $allow_origin : __( 'none', 'wp-http-query' ),
					$origin
				),
				array( $status )
			);
		}

		$allow_headers = cors_token_list( strtolower( $headers['access-control-allow-headers'] ?? '' ) ) ?? array();
		if ( ! in_array( 'content-type', $allow_headers, true ) && ! in_array( '*', $allow_headers, true ) ) {
			return verdict(
				'cors_no_content_type',
				sprintf(
					/* translators: %s: The Access-Control-Allow-Headers header value. */
					__( 'CORS allows QUERY, but the preflight’s Access-Control-Allow-Headers (%s) leaves out Content-Type, or isn’t a valid list, so browsers refuse a QUERY with a JSON body from other sites. Core’s list includes it, so something replaced it: most likely another plugin that handles CORS, or a server rule.', 'wp-http-query' ),
					'' !== ( $headers['access-control-allow-headers'] ?? '' ) ? $headers['access-control-allow-headers'] : __( 'none', 'wp-http-query' )
				),
				array( $status )
			);
		}

		$message = sprintf(
			/* translators: %s: The Access-Control-Allow-Methods header value. */
			__( 'Pages on other sites can send QUERY: the preflight’s Access-Control-Allow-Methods is %s.', 'wp-http-query' ),
			$methods
		);
		if ( $wildcard ) {
			$message .= ' ' . __( 'The * covers requests sent without cookies; a browser sending cookies needs QUERY in the list by name.', 'wp-http-query' );
		}

		return verdict( 'cors_query', $message, array( $status ) );
	}

	$found = '' !== $methods
		? sprintf(
			/* translators: %s: The Access-Control-Allow-Methods header value. */
			__( 'CORS is on, but the preflight’s Access-Control-Allow-Methods (%s) leaves out QUERY, or isn’t a valid list, so browsers refuse to send QUERY from other sites.', 'wp-http-query' ),
			$methods
		)
		: __( 'CORS is on, but the preflight came back without Access-Control-Allow-Methods, so browsers refuse to send QUERY from other sites.', 'wp-http-query' );

	$cause = $filterable
		? __( 'Something removes it from the list the plugin adds it to: most likely a rest_allowed_cors_methods callback, or another plugin or a server rule that sends its own Access-Control-Allow-Methods after WordPress’s. Add QUERY back there.', 'wp-http-query' )
		: __( 'Something sends its own Access-Control-Allow-Methods after the plugin’s: most likely another plugin that handles CORS, or a server rule. Add QUERY to its list.', 'wp-http-query' );

	return verdict( 'cors_no_query', $found . ' ' . $cause, array( $status ) );
}

/**
 * What a clean cache result doesn't prove. Shown on the page and in Site Health.
 */
function self_test_limits(): string {
	return __( 'A clean cache result is a sample, not a guarantee: some caches store a response only after several requests, or per region. The loopback usually bypasses a CDN, so the browser check is the one that goes through it. LiteSpeed and OpenLiteSpeed haven’t been tested by this project, so LiteSpeed results are observations, not claims.', 'wp-http-query' );
}
