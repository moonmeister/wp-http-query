/**
 * The diagnostic echo route is off by default.
 *
 * QUERY /wp-http-query/v1/echo is unauthenticated and reflects its input, so
 * the plugin registers it only when wp_http_query_register_demo_route returns
 * true. The default is false. The test fixture turns it on for the rest of
 * the suite; this file switches the fixture's filter off with a fixture-only
 * option, set over WP-CLI, and puts it back in afterAll.
 *
 * Runs in the serial lifecycle suite because it changes site state.
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { ECHO, rest } from '../http';
import { sites } from '../sites';
import { deleteOption, FIXTURE_DEMO_ROUTE_OFF, setOption, snapshotOption } from './site-state';

describe.each( sites )( 'demo route: $label', ( site ) => {
	let restoreSwitch = () => {};

	beforeAll( () => {
		restoreSwitch = snapshotOption( site, FIXTURE_DEMO_ROUTE_OFF );
	} );

	afterAll( () => {
		restoreSwitch();
	} );

	it( 'is not registered when the filter is left at its default', async () => {
		setOption( site, FIXTURE_DEMO_ROUTE_OFF, 1 );
		expect( ( await rest( site, 'QUERY', ECHO, { json: {} } ) ).status ).toBe( 404 );
		// Nor through the method-override tunnel.
		const tunnel = await rest( site, 'POST', ECHO, {
			json: {},
			headers: { 'x-http-method-override': 'QUERY' },
		} );
		expect( tunnel.status ).toBe( 404 );
	} );

	it( 'is registered when the filter returns true', async () => {
		deleteOption( site, FIXTURE_DEMO_ROUTE_OFF ); // The fixture's filter turns it on.
		expect( ( await rest( site, 'QUERY', ECHO, { json: {} } ) ).status ).toBe( 200 );
	} );
} );
