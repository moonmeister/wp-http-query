<?php
/**
 * Probe: register_rest_route() does not split comma-separated method strings
 * when 'methods' is given as an array.
 *
 * Not intended for core. Scratch test for the ADR 0002 blast-radius experiment.
 *
 * @group restapi
 */
class Tests_REST_Array_Methods_Probe extends WP_Test_REST_TestCase {

	protected static $admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	private function register( $methods, $route ) {
		register_rest_route(
			'probe/v1',
			$route,
			array(
				'methods'             => $methods,
				'callback'            => static function () {
					return new WP_REST_Response( 'ok', 200 );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Control: a single-method constant in an array works fine.
	 */
	public function test_array_with_single_method_constants_works() {
		$this->register( array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ), '/control' );

		foreach ( array( 'GET', 'POST' ) as $method ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( $method, '/probe/v1/control' ) );
			$this->assertSame( 200, $response->get_status(), "$method should route" );
		}
	}

	/**
	 * The bug: EDITABLE is 'POST, PUT, PATCH'. In the array form it is never
	 * split, so it becomes one bogus method key that can never match.
	 */
	public function test_array_with_multi_method_constant_is_broken() {
		$this->register( array( WP_REST_Server::READABLE, WP_REST_Server::EDITABLE ), '/broken' );

		// GET is a single-method constant, so it still routes.
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/probe/v1/broken' ) );
		$this->assertSame( 200, $response->get_status(), 'GET should route' );

		// Show what the route actually registered.
		$routes   = rest_get_server()->get_routes();
		$handler  = $routes['/probe/v1/broken'][0];
		$declared = array_keys( $handler['methods'] );
		$this->assertSame(
			array( 'GET', 'POST, PUT, PATCH' ),
			$declared,
			'Registered method keys reveal the unsplit constant'
		);

		// Each method inside EDITABLE should route, and does not.
		foreach ( array( 'POST', 'PUT', 'PATCH' ) as $method ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( $method, '/probe/v1/broken' ) );
			$this->assertSame( 200, $response->get_status(), "$method should route but does not" );
		}
	}

	/**
	 * Control: the string form splits correctly, proving the two forms diverge.
	 */
	public function test_string_form_splits_correctly() {
		$this->register( WP_REST_Server::READABLE . ', ' . WP_REST_Server::EDITABLE, '/string' );

		foreach ( array( 'GET', 'POST', 'PUT', 'PATCH' ) as $method ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( $method, '/probe/v1/string' ) );
			$this->assertSame( 200, $response->get_status(), "$method should route via string form" );
		}
	}
}
