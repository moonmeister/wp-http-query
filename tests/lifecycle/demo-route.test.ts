/**
 * The diagnostic echo route is admins only by default.
 *
 * QUERY /wp-http-query/v1/echo reflects its input, so unless
 * wp_http_query_public_demo_route returns true, only manage_options users
 * may call it. It is always registered, because the self-test sends QUERY to
 * it. The test fixture makes it public for the rest of the suite; this file
 * switches the fixture's filter off with a fixture-only option, set over
 * WP-CLI, and puts it back in afterAll, along with the subscriber it creates.
 *
 * Runs in the serial lifecycle suite because it changes site state. Every
 * request sends Connection: close (see FRESH in http.ts).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { ECHO, type Echo, rest } from '../http';
import { wp } from '../seed';
import { NO_LOCAL_CLI, sites } from '../sites';
import { Session } from './session';
import { deleteOption, FIXTURE_DEMO_ROUTE_OFF, networkFlag, setOption, snapshotOption } from './site-state';

// Letters only: multisite rejects usernames with anything else.
const SUBSCRIBER = 'wphqdemosubscriber';
const SUBSCRIBER_PASSWORD = 'wphq-demo-subscriber-password';

describe.each( sites )( 'demo route: $label', ( site ) => {
	if ( ! site.localCli ) {
		it.skip( NO_LOCAL_CLI, () => {} );
		return;
	}

	const admin = new Session( site );
	const subscriber = new Session( site, SUBSCRIBER, SUBSCRIBER_PASSWORD );
	let restoreSwitch = () => {};

	// On a network a site-level delete only removes the user from the site.
	const network = networkFlag( site );
	const deleteSubscriber = () => {
		if ( wp( site, [ 'user', 'list', `--login=${ SUBSCRIBER }`, '--format=count', ...network ] ).trim() !== '0' ) {
			wp( site, [ 'user', 'delete', SUBSCRIBER, '--yes', ...network ] );
		}
	};

	beforeAll( async () => {
		restoreSwitch = snapshotOption( site, FIXTURE_DEMO_ROUTE_OFF );
		setOption( site, FIXTURE_DEMO_ROUTE_OFF, 1 );
		deleteSubscriber();
		wp( site, [ 'user', 'create', SUBSCRIBER, `${ SUBSCRIBER }@example.test`, '--role=subscriber', `--user_pass=${ SUBSCRIBER_PASSWORD }` ] );
		await admin.logIn();
		await subscriber.logIn();
	} );

	afterAll( () => {
		deleteSubscriber();
		restoreSwitch();
	} );

	describe( 'filter left at its default', () => {
		it( 'refuses a signed-out QUERY with 401', async () => {
			const res = await rest( site, 'QUERY', ECHO, { json: {} } );
			expect( res.status ).toBe( 401 );
			expect( ( await res.json() ).code ).toBe( 'rest_forbidden' );
		} );

		it( 'refuses a signed-out request through the method-override tunnel with 401', async () => {
			const res = await rest( site, 'POST', ECHO, {
				json: {},
				headers: { 'x-http-method-override': 'QUERY' },
			} );
			expect( res.status ).toBe( 401 );
			expect( ( await res.json() ).code ).toBe( 'rest_forbidden' );
		} );

		it( 'refuses a signed-in non-admin with 403', async () => {
			const res = await subscriber.rest( 'QUERY', ECHO, { json: {} } );
			expect( res.status ).toBe( 403 );
			expect( ( await res.json() ).code ).toBe( 'rest_forbidden' );
		} );

		it( 'answers an admin (cookie and nonce) with the echoed method and body', async () => {
			const marker = `m-${ Math.random().toString( 36 ).slice( 2 ) }`;
			const res = await admin.rest( 'QUERY', ECHO, { json: { marker } } );
			expect( res.status ).toBe( 200 );
			expect( res.headers.get( 'accept-query' ) ).toBe( '"application/json"' );
			const echo: Echo = await res.json();
			expect( echo.method ).toBe( 'QUERY' );
			expect( echo.merged_params.marker ).toBe( marker );
		} );

		it( 'treats an admin cookie without a nonce as signed out', async () => {
			const res = await admin.rest( 'QUERY', ECHO, { json: {}, nonce: null } );
			expect( res.status ).toBe( 401 );
		} );
	} );

	describe( 'filter returning true', () => {
		it( 'answers a signed-out QUERY', async () => {
			deleteOption( site, FIXTURE_DEMO_ROUTE_OFF ); // The fixture's filter makes it public.
			const res = await rest( site, 'QUERY', ECHO, { json: { search: 'needle' } } );
			expect( res.status ).toBe( 200 );
			const echo: Echo = await res.json();
			expect( echo.method ).toBe( 'QUERY' );
			expect( echo.merged_params.search ).toBe( 'needle' );
		} );
	} );
} );
