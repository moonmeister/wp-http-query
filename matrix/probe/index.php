<?php
/**
 * SAPI passthrough probe for the HTTP QUERY method (RFC 10008).
 *
 * Deliberately has NO WordPress dependency. The question this answers —
 * "does an unrecognized verb, and its body, survive the path from client to PHP?" —
 * is a property of the web server and SAPI, not of WordPress. Keeping it standalone
 * makes it fast to run across many stacks and citable outside the WordPress world.
 *
 * Responds with a JSON report. See ../README.md.
 */

declare( strict_types = 1 );

/*
 * Read the raw body FIRST, before anything else can consume the stream.
 * Some SAPIs allow only a single read of php://input, and PHP's own
 * post-data machinery may have consumed it already for known verbs.
 */
$raw       = @file_get_contents( 'php://input' );
$raw_ok    = ( false !== $raw );
$body      = $raw_ok ? $raw : '';
$body_len  = strlen( $body );

$headers = array();
foreach ( $_SERVER as $key => $value ) {
	if ( str_starts_with( $key, 'HTTP_' ) ) {
		$name             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
		$headers[ $name ] = $value;
	}
}

$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) && '' !== $_SERVER['CONTENT_LENGTH']
	? (int) $_SERVER['CONTENT_LENGTH']
	: null;

// The client may assert what it sent, so the probe can self-verify integrity.
$expected_sha = $headers['x-probe-expect-sha256'] ?? null;
$actual_sha   = hash( 'sha256', $body );

$notes = array();

if ( ! $raw_ok ) {
	$notes[] = 'php://input was NOT readable (file_get_contents returned false).';
}

if ( null === $content_length && $body_len > 0 ) {
	$notes[] = 'Body present but CONTENT_LENGTH absent — likely chunked, or the SAPI dropped it.';
}

if ( null !== $content_length && $content_length !== $body_len ) {
	$notes[] = sprintf(
		'TRUNCATION OR PADDING: CONTENT_LENGTH=%d but read %d bytes.',
		$content_length,
		$body_len
	);
}

if ( ! empty( $_POST ) ) {
	$notes[] = 'PHP populated $_POST for this request; its post-data machinery engaged.';
}

if ( ! empty( $_POST ) && 0 === $body_len ) {
	$notes[] = 'CRITICAL: $_POST populated but php://input empty — the stream was consumed before the probe read it.';
}

if ( null !== $expected_sha && $expected_sha !== $actual_sha ) {
	$notes[] = 'BODY MISMATCH: client-asserted sha256 does not match what arrived.';
}

$method       = $_SERVER['REQUEST_METHOD'] ?? null;
$body_intact  = ( null === $expected_sha ) ? null : ( $expected_sha === $actual_sha );
$length_match = ( null === $content_length ) ? null : ( $content_length === $body_len );

$report = array(
	'probe_version' => 1,

	// What arrived.
	'request_method' => $method,
	'method_verbatim' => ( null !== $method && $method === strtoupper( (string) $method ) ),
	'content_type'   => $_SERVER['CONTENT_TYPE'] ?? null,

	// The load-bearing question: did the body survive?
	'body' => array(
		'input_readable'        => $raw_ok,
		'bytes_read'            => $body_len,
		'content_length_header' => $content_length,
		'length_matches'        => $length_match,
		'sha256'                => $actual_sha,
		'expected_sha256'       => $expected_sha,
		'intact'                => $body_intact,
		'preview'               => substr( $body, 0, 256 ),
	),

	// Did PHP's own parsing interfere?
	'php_parsing' => array(
		'post_populated'           => ! empty( $_POST ),
		'post_keys'                => array_keys( $_POST ),
		'get_populated'            => ! empty( $_GET ),
		'get_keys'                 => array_keys( $_GET ),
		'enable_post_data_reading' => (bool) ini_get( 'enable_post_data_reading' ),
		'post_max_size'            => ini_get( 'post_max_size' ),
	),

	// Environment, so results are attributable.
	'environment' => array(
		'sapi'            => PHP_SAPI,
		'php_version'     => PHP_VERSION,
		'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
		'protocol'        => $_SERVER['SERVER_PROTOCOL'] ?? null,
	),

	'headers_received' => $headers,
	'notes'            => $notes,
);

header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
