<?php
/**
 * Probe: can the feature plugin serve QUERY from an existing core read route,
 * on unmodified trunk?
 *
 * This is the question the plugin-first pivot turns on. Everything measured so
 * far registered a controller under 'methods' => 'QUERY' by hand, which proves
 * dispatch works but not that the plugin can reach routes it does not own.
 * Here nothing is registered: the plugin rewrites core's own /wp/v2/posts
 * registration through 'rest_endpoints', and the assertions run against that.
 *
 * The form-encoded case is the one that matters most. On unmodified trunk it
 * returns the FULL UNFILTERED COLLECTION with a 200 — the project's only
 * silent-wrong-answer failure mode (gap 3). If the plugin closes it from
 * userland, no core patch is needed to make QUERY safe.
 *
 * Scratch test. Not intended for core.
 *
 * Drop in tests/phpunit/tests/rest-api/ alongside a copy of the plugin, then:
 *   phpunit --filter Tests_REST_Plugin_Spike_Probe
 *
 * @group restapi
 */
class Tests_REST_Plugin_Spike_Probe extends WP_Test_REST_TestCase {

	/**
	 * Path to the plugin under test. Override if it is not sitting next to this
	 * file — the fork stays pristine, so it is copied in rather than symlinked
	 * from the hub repo.
	 */
	const PLUGIN = __DIR__ . '/wp-http-query.php';

	protected static $needle_id;
	protected static $haystack_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$needle_id = $factory->post->create(
			array(
				'post_title'  => 'findmeneedle',
				'post_status' => 'publish',
			)
		);

		self::$haystack_id = $factory->post->create(
			array(
				'post_title'  => 'an unrelated post',
				'post_status' => 'publish',
			)
		);
	}

	public function set_up() {
		parent::set_up();

		if ( ! file_exists( self::PLUGIN ) ) {
			$this->markTestSkipped( 'Copy plugin/wp-http-query.php next to this probe first.' );
		}

		// Top-level add_filter calls; including twice would double-hook them.
		require_once self::PLUGIN;

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	private function query( $content_type, $body ) {
		$request = new WP_REST_Request( 'QUERY', '/wp/v2/posts' );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );

		return rest_get_server()->dispatch( $request );
	}

	private function ids( $response ) {
		return wp_list_pluck( $response->get_data(), 'id' );
	}

	/**
	 * The route matches at all. Without the 'rest_endpoints' rewrite this is a
	 * 404: READABLE is 'GET', and match_request_to_handler() is a plain
	 * array-key lookup on the normalized method map.
	 */
	public function test_core_read_route_matches_query() {
		$response = $this->query( 'application/json', '{}' );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A JSON body filters the collection. Already true on trunk for a route
	 * registered under QUERY by hand — asserted here to show the plugin's
	 * rewrite does not disturb it.
	 */
	public function test_query_json_body_filters_the_collection() {
		$response = $this->query( 'application/json', '{"search":"findmeneedle"}' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( self::$needle_id ), $this->ids( $response ) );
	}

	/**
	 * The gap-3 case, and the point of the exercise.
	 *
	 * Measured three ways on e7739d5414, because the obvious control is the
	 * wrong one — with the plugin off entirely this 404s on gap 2, which says
	 * nothing about gap 3:
	 *
	 *   plugin off          404, route does not match       (gap 2)
	 *   gap 2 shim only     200 with BOTH posts, unfiltered (gap 3)
	 *   both shims          200, correctly filtered
	 *
	 * The middle row is the silent wrong answer. parse_body_params() does run
	 * for QUERY, but get_parameter_order() leaves the parsed bucket out of the
	 * lookup order, so 'search' is parsed and then never consulted. Reproduce
	 * it by renaming the 'rest_request_parameter_order' hook in the plugin.
	 */
	public function test_query_form_encoded_body_filters_the_collection() {
		$response = $this->query( 'application/x-www-form-urlencoded', 'search=findmeneedle' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( self::$needle_id ),
			$this->ids( $response ),
			'A form-encoded QUERY returned the unfiltered collection — gap 3 is not closed.'
		);
	}

	/**
	 * Regression guard: GET is untouched.
	 */
	public function test_get_still_returns_everything() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
	}

	/**
	 * QUERY reaches read handlers only. The write handler on the same route
	 * shares a URL but not a method map, and must not pick QUERY up.
	 */
	public function test_write_handler_does_not_gain_query() {
		$handlers = rest_get_server()->get_routes()['/wp/v2/posts'];

		$read_has_query  = false;
		$write_has_query = false;

		foreach ( $handlers as $handler ) {
			if ( ! empty( $handler['methods']['GET'] ) ) {
				$read_has_query = ! empty( $handler['methods']['QUERY'] );
			}
			if ( ! empty( $handler['methods']['POST'] ) ) {
				$write_has_query = ! empty( $handler['methods']['QUERY'] );
			}
		}

		$this->assertTrue( $read_has_query, 'The read handler did not gain QUERY.' );
		$this->assertFalse( $write_has_query, 'The write handler gained QUERY.' );
	}

	/**
	 * The plugin must not trip Trac#65905 on the routes it rewrites.
	 *
	 * get_routes() comma-splits the string form of 'methods' but not the array
	 * form, so handing back an array whose element carries a multi-method
	 * constant would register one unmatchable key. The plugin writes the string
	 * form, which also repairs the route on the way past.
	 */
	public function test_array_form_route_survives_the_rewrite() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'spike/v1',
					'/thing',
					array(
						'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::EDITABLE ),
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$methods = rest_get_server()->get_routes()['/spike/v1/thing'][0]['methods'];

		$this->assertArrayHasKey( 'QUERY', $methods );
		$this->assertArrayHasKey( 'PATCH', $methods, 'PATCH is unmatchable — the array form was not split.' );
		$this->assertArrayNotHasKey( 'POST, PUT, PATCH', $methods );
	}
}
