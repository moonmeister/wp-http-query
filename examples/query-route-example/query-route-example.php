<?php
/**
 * Plugin Name:       QUERY Route Example
 * Description:       Example: a REST route that answers GET and HTTP QUERY with one read handler. Companion to docs/integrating.md in the wp-http-query repo.
 * Version:           0.1.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * Registers /query-route-example/v1/books, a read-only search over a fixed
 * list of books, the way docs/integrating.md recommends:
 *
 * - GET and QUERY share one read handler. A separate write handler takes POST.
 * - The search parameters are schema args, so a QUERY body is validated like
 *   a GET query string.
 * - The permission check asks "is this a safe method?", never
 *   "is this GET?". See is_safe_method().
 * - QUERY is only registered when something supports it: the wp-http-query
 *   plugin, or a future core with WP_REST_Server::QUERYABLE. Without either,
 *   the route answers GET only.
 *
 * The tests in tests/examples.test.ts exercise this file over HTTP. If you
 * change it, change the samples in docs/integrating.md to match.
 *
 * @package QueryRouteExample
 */

declare( strict_types = 1 );

namespace QueryRouteExample;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ROUTE_NAMESPACE = 'query-route-example/v1';

/**
 * The QUERY method name, or null if nothing on this site supports QUERY.
 *
 * Call it on rest_api_init or later, not when this file loads. Plugins load
 * in alphabetical order, so wp-http-query may not have loaded yet.
 */
function query_method(): ?string {
	// A future core release. Not in any release as of 2026-11-04.
	if ( defined( 'WP_REST_Server::QUERYABLE' ) ) {
		return constant( 'WP_REST_Server::QUERYABLE' );
	}

	// The wp-http-query feature plugin.
	if ( defined( 'WPHttpQuery\QUERYABLE' ) ) {
		return constant( 'WPHttpQuery\QUERYABLE' );
	}

	return null;
}

/**
 * Whether a method is safe (RFC 9110 §9.2.1): read-only by definition.
 *
 * Use this instead of 'GET' === $request->get_method(). That check treats
 * QUERY as a write, because QUERY is not GET.
 *
 * Unknown methods count as unsafe, so the check fails closed.
 */
function is_safe_method( string $method ): bool {
	return in_array( strtoupper( $method ), array( 'GET', 'HEAD', 'OPTIONS', 'QUERY' ), true );
}

add_action(
	'rest_api_init',
	static function (): void {
		$read_methods = \WP_REST_Server::READABLE;

		$query = query_method();
		if ( null !== $query ) {
			// String form, so cores before 7.2 can't trip Trac#65905.
			$read_methods .= ', ' . $query;
		}

		register_rest_route(
			ROUTE_NAMESPACE,
			'/books',
			array(
				array(
					'methods'             => $read_methods,
					'callback'            => __NAMESPACE__ . '\\get_books',
					'permission_callback' => __NAMESPACE__ . '\\check_permission',
					'args'                => array(
						'search'   => array(
							'description' => 'Case-insensitive match against the title.',
							'type'        => 'string',
						),
						'author'   => array(
							'description' => 'Only books by these authors.',
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'per_page' => array(
							'description' => 'Maximum number of books to return.',
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 10,
							'default'     => 10,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => __NAMESPACE__ . '\\create_book',
					'permission_callback' => __NAMESPACE__ . '\\check_permission',
					'args'                => array(
						'title' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}
);

/**
 * One permission callback for both handlers, so it has to tell reads from
 * writes. It asks whether the method is safe, so QUERY gets read access.
 *
 * @param \WP_REST_Request $request Request.
 * @return bool
 */
function check_permission( \WP_REST_Request $request ): bool {
	if ( is_safe_method( $request->get_method() ) ) {
		return true;
	}

	return current_user_can( 'edit_posts' );
}

/**
 * Read handler, for GET and QUERY alike.
 *
 * get_param() reads the query string for GET and the body for QUERY, JSON or
 * form-encoded. The handler doesn't need to know which method it got.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function get_books( \WP_REST_Request $request ): \WP_REST_Response {
	$search  = (string) $request->get_param( 'search' );
	$authors = (array) $request->get_param( 'author' );

	$books = array_values(
		array_filter(
			books(),
			static function ( array $book ) use ( $search, $authors ): bool {
				if ( '' !== $search && false === stripos( $book['title'], $search ) ) {
					return false;
				}

				return ! $authors || in_array( $book['author'], $authors, true );
			}
		)
	);

	return new \WP_REST_Response( array_slice( $books, 0, (int) $request->get_param( 'per_page' ) ) );
}

/**
 * Write handler, for POST only. Validates and echoes; stores nothing.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function create_book( \WP_REST_Request $request ): \WP_REST_Response {
	return new \WP_REST_Response( array( 'title' => $request->get_param( 'title' ) ), 201 );
}

/**
 * The data. Fixed, so the example has no setup.
 *
 * @return array<int, array{title: string, author: string, year: int}>
 */
function books(): array {
	return array(
		array( 'title' => 'Dune', 'author' => 'Frank Herbert', 'year' => 1965 ),
		array( 'title' => 'The Left Hand of Darkness', 'author' => 'Ursula K. Le Guin', 'year' => 1969 ),
		array( 'title' => 'The Dispossessed', 'author' => 'Ursula K. Le Guin', 'year' => 1974 ),
		array( 'title' => 'Kindred', 'author' => 'Octavia E. Butler', 'year' => 1979 ),
		array( 'title' => 'Neuromancer', 'author' => 'William Gibson', 'year' => 1984 ),
		array( 'title' => 'Parable of the Sower', 'author' => 'Octavia E. Butler', 'year' => 1993 ),
	);
}
