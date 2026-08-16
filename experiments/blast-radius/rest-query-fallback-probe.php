<?php
/**
 * Probe: if a QUERY request were routed to an unmodified GET collection
 * handler, would the JSON body actually drive the query?
 *
 * ADR 0002 option E (QUERY -> GET fallback) is argued against on the grounds
 * that the GET handler "will not read the body" and the query is silently
 * discarded. This tests that claim against the real posts controller instead
 * of asserting it.
 *
 * No core patch needed: registering WP_REST_Posts_Controller::get_items() under
 * 'methods' => 'QUERY' with its own get_collection_params() reproduces exactly
 * what a fallback (or option D) would dispatch to.
 *
 * Scratch test. Not intended for core.
 *
 * @group restapi
 */
class Tests_REST_Query_Fallback_Probe extends WP_Test_REST_TestCase {

	protected static $needle_id;
	protected static $other_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$needle_id = $factory->post->create(
			array(
				'post_title'  => 'Findable needle post',
				'post_status' => 'publish',
			)
		);
		self::$other_id = $factory->post->create(
			array(
				'post_title'  => 'Unrelated haystack post',
				'post_status' => 'publish',
			)
		);
	}

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$controller = new WP_REST_Posts_Controller( 'post' );
		register_rest_route(
			'probe/v1',
			'/posts',
			array(
				'methods'             => 'QUERY',
				'callback'            => array( $controller, 'get_items' ),
				'permission_callback' => array( $controller, 'get_items_permissions_check' ),
				'args'                => $controller->get_collection_params(),
			)
		);
	}

	private function dispatch_query( $content_type, $body ) {
		$request = new WP_REST_Request( 'QUERY', '/probe/v1/posts' );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Control: the same handler over GET with a query string filters correctly.
	 */
	public function test_get_with_query_string_filters() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'search', 'needle' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	/**
	 * The claim under test. If the JSON body drives the query, this passes and
	 * ADR 0002's "silently discards the query" argument against option E is
	 * wrong for JSON bodies.
	 */
	public function test_query_json_body_drives_the_search() {
		$response = $this->dispatch_query( 'application/json', '{"search":"needle"}' );

		$this->assertSame( 200, $response->get_status(), 'QUERY should dispatch' );
		$data = $response->get_data();
		$this->assertCount( 1, $data, 'JSON body should have filtered the collection' );
		$this->assertSame( self::$needle_id, $data[0]['id'] );
	}

	/**
	 * And the form-encoded case, which gap 3 predicts will silently return
	 * everything.
	 */
	public function test_query_form_body_drives_the_search() {
		$response = $this->dispatch_query( 'application/x-www-form-urlencoded', 'search=needle' );

		$this->assertSame( 200, $response->get_status(), 'QUERY should dispatch' );
		$data = $response->get_data();
		$this->assertCount( 1, $data, 'form body should have filtered the collection' );
	}

	/**
	 * Does schema validation run against body params? Send an out-of-range
	 * per_page, which the GET path rejects with a 400.
	 */
	public function test_query_json_body_is_schema_validated() {
		$response = $this->dispatch_query( 'application/json', '{"per_page":9999}' );

		$this->assertSame( 400, $response->get_status(), 'per_page=9999 should be rejected' );
	}
}
