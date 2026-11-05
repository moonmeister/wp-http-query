<?php
/**
 * Plugin Name:       WP HTTP QUERY
 * Plugin URI:        https://github.com/moonmeister/wp-http-query
 * Description:       HTTP QUERY (RFC 10008) support for the WordPress REST API, entirely in userland. No core patch required.
 * Version:           0.0.0-dev
 * Requires at least: 7.2
 * Requires PHP:      8.0
 * Author:            Alex Moon
 * Author URI:        https://moonmeister.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-http-query
 *
 * The project's deliverable: QUERY support shipped as a feature plugin on stock
 * core, ahead of any core patch. Each workaround is annotated with the core gap
 * it stands in for, so the plugin doubles as the spec for the eventual patch.
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

const METHOD     = 'QUERY';
const NAMESPACE_ = 'wp-http-query/v1';

/**
 * Media types advertised in Accept-Query.
 *
 * Advertising is separate from parsing. ADR 0001 (option B) settled what is
 * *parsed*: JSON and form-encoded, matching core's other body-bearing
 * methods, and both keep working whatever this returns. What is *advertised*
 * is JSON only: accepting form-encoded is a bug fix, but advertising it
 * endorses a poor structured-query format (ADR 0001 open question 2, #20).
 *
 * This is the current default, not a final decision. Revisit it if real use
 * shows a need. Sites can change it with the filter.
 */
function advertised_query_types(): array {
	return (array) apply_filters(
		'wp_http_query_advertised_types',
		array( 'application/json' )
	);
}

/**
 * Userland stand-in for core's future WP_REST_Server::QUERYABLE (ADR 0002,
 * option B). Use it in 'methods' when registering a route that answers QUERY.
 */
const QUERYABLE = METHOD;

/** This file's path, for plugin_basename() and plugins_url(). */
const PLUGIN_FILE = __FILE__;

/**
 * Registers one of the plugin's own routes by its full route key, such as
 * ECHO_ROUTE, so each route's path is written once.
 *
 * @param string $route Route key: '/' . NAMESPACE_ . '/...'.
 * @param array  $args  register_rest_route() arguments.
 */
function register_route( string $route, array $args ): void {
	register_rest_route( NAMESPACE_, substr( $route, strlen( '/' . NAMESPACE_ ) ), $args );
}

require_once __DIR__ . '/includes/core-gaps.php';
require_once __DIR__ . '/includes/reach.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/demo-route.php';
require_once __DIR__ . '/includes/diagnose.php';
require_once __DIR__ . '/includes/self-test.php';
