<?php
/**
 * Probe: does a QUERY request body populate request params on trunk today?
 *
 * ADR 0002 needs this to decide between options A/B/C: if an ALLMETHODS route
 * that starts receiving QUERY sees its params populated, the BC objection to
 * option A is much weaker than if it silently sees nothing.
 *
 * Scratch test. Not intended for core.
 *
 * @group restapi
 */
class Tests_REST_Query_Body_Probe extends WP_Test_REST_TestCase {

	private function make( $method, $content_type, $body ) {
		$request = new WP_REST_Request( $method, '/probe/v1/whatever' );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );
		return $request;
	}

	/**
	 * Control: POST with a JSON body populates params.
	 */
	public function test_post_json_body_populates() {
		$request = $this->make( 'POST', 'application/json', '{"search":"hello"}' );
		$this->assertSame( 'hello', $request->get_param( 'search' ) );
	}

	/**
	 * The question. QUERY is not in $accepts_body_data — but the JSON branch of
	 * get_parameter_order() is not gated on method at all.
	 */
	public function test_query_json_body_populates() {
		$request = $this->make( 'QUERY', 'application/json', '{"search":"hello"}' );
		$this->assertSame( 'hello', $request->get_param( 'search' ) );
	}

	/**
	 * Control: PUT with a form-encoded body populates params.
	 *
	 * PUT rather than POST deliberately. get_parameter_order() skips
	 * parse_body_params() for POST — core relies on the SAPI having filled
	 * $_POST — so a synthetic POST request object can never see a form body.
	 * PUT exercises the parse path, which is what QUERY would need.
	 */
	public function test_put_form_body_populates() {
		$request = $this->make( 'PUT', 'application/x-www-form-urlencoded', 'search=hello' );
		$this->assertSame( 'hello', $request->get_param( 'search' ) );
	}

	/**
	 * Gap 3 proper: QUERY is missing from $accepts_body_data, so the 'POST'
	 * param source is never consulted for a form-encoded QUERY body.
	 */
	public function test_query_form_body_populates() {
		$request = $this->make( 'QUERY', 'application/x-www-form-urlencoded', 'search=hello' );
		$this->assertSame( 'hello', $request->get_param( 'search' ) );
	}

	/**
	 * Record the parameter order for each case, so the ADR can cite it directly.
	 */
	public function test_parameter_order_is_recorded() {
		$reflect = new ReflectionMethod( 'WP_REST_Request', 'get_parameter_order' );
		$reflect->setAccessible( true );

		$cases = array(
			'POST json'  => $this->make( 'POST', 'application/json', '{"search":"hello"}' ),
			'QUERY json' => $this->make( 'QUERY', 'application/json', '{"search":"hello"}' ),
			'PUT form'   => $this->make( 'PUT', 'application/x-www-form-urlencoded', 'search=hello' ),
			'QUERY form' => $this->make( 'QUERY', 'application/x-www-form-urlencoded', 'search=hello' ),
			'GET json'   => $this->make( 'GET', 'application/json', '{"search":"hello"}' ),
		);

		$observed = array();
		foreach ( $cases as $label => $request ) {
			$observed[ $label ] = implode( ' > ', $reflect->invoke( $request ) );
		}

		// Deliberately wrong, so the diff prints the table.
		$this->assertSame( array(), $observed );
	}
}
