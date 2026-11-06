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
 * Runs in the serial lifecycle suite because it changes site state.
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { ECHO, type Echo, rest } from '../http';
import { describeLocalSites } from '../sites';
import { Session } from './session';
import { deleteOption, FIXTURE_DEMO_ROUTE_OFF, snapshotOptions } from './site-state';

// Letters only: multisite rejects usernames with anything else.
const SUBSCRIBER = 'wphqdemosubscriber';
const SUBSCRIBER_PASSWORD = 'wphq-demo-subscriber-password';

/**
 * Deletes the subscriber if a run left one behind. Users belong to the
 * network on multisite, and a site-level delete only removes the user from
 * the site, so it's wpmu_delete_user() there.
 */
const DELETE_SUBSCRIBER_PHP = `
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$user = get_user_by( 'login', '${ SUBSCRIBER }' );
	if ( $user && is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $user->ID );
	} elseif ( $user ) {
		wp_delete_user( $user->ID );
	}`;

describeLocalSites( 'demo route: $label', ( site ) => {
	const admin = new Session( site );
	const subscriber = new Session( site, SUBSCRIBER, SUBSCRIBER_PASSWORD );
	let restore = () => {};

	beforeAll( async () => {
		restore = snapshotOptions( site, [ FIXTURE_DEMO_ROUTE_OFF ], {
			set: { [ FIXTURE_DEMO_ROUTE_OFF ]: 1 },
			setUp: `${ DELETE_SUBSCRIBER_PHP }
				$id = wp_insert_user( array(
					'user_login' => '${ SUBSCRIBER }',
					'user_pass'  => '${ SUBSCRIBER_PASSWORD }',
					'user_email' => '${ SUBSCRIBER }@example.test',
					'role'       => 'subscriber',
				) );
				if ( is_wp_error( $id ) ) {
					throw new Exception( $id->get_error_message() );
				}`,
			tearDown: DELETE_SUBSCRIBER_PHP,
		} );
		await admin.logIn();
		await subscriber.logIn();
	} );

	afterAll( () => restore() );

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
