/**
 * CORS: the plugin respects a site that turned REST CORS off, or moved it.
 *
 * Before 7.2 core has no filter on its CORS method list, so the plugin swaps
 * core's rest_send_cors_headers() for its own copy. It does that only while
 * core's handler is hooked, and at the priority it's hooked at:
 *
 * - A site that removed core's handler has turned REST CORS off. Adding the
 *   plugin's copy anyway would turn it back on, echoing any Origin with
 *   Access-Control-Allow-Credentials: true.
 * - A site that re-hooked core's handler at another priority would otherwise
 *   get both, and core's, running later, would overwrite QUERY out of the list.
 *
 * From 7.2 the plugin uses core's rest_allowed_cors_methods filter, which
 * doesn't run once core's handler is gone, so nightly must behave the same.
 *
 * Runs in the serial lifecycle suite because it changes site state: the test
 * fixture's CORS switches are fixture-only options, set over WP-CLI and put
 * back in afterAll. Every request sends Connection: close (see session.ts).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, ORIGIN, rest, tokens } from '../http';
import { NO_LOCAL_CLI, sites } from '../sites';
import { FRESH } from './session';
import { deleteOption, FIXTURE_REHOOK_CORE_CORS, FIXTURE_REMOVE_CORE_CORS, setOption, snapshotOption } from './site-state';

const ROUTE = CORE_COLLECTIONS[ 0 ];

describe.each( sites )( 'CORS, as the site configured it: $label', ( site ) => {
	if ( ! site.localCli ) {
		it.skip( NO_LOCAL_CLI, () => {} );
		return;
	}

	let restoreRemove = () => {};
	let restoreRehook = () => {};

	beforeAll( () => {
		restoreRemove = snapshotOption( site, FIXTURE_REMOVE_CORE_CORS );
		restoreRehook = snapshotOption( site, FIXTURE_REHOOK_CORE_CORS );
	} );

	afterAll( () => {
		restoreRehook();
		restoreRemove();
	} );

	const preflight = () =>
		rest( site, 'OPTIONS', ROUTE, { headers: { ...FRESH, origin: ORIGIN, 'access-control-request-method': 'QUERY' } } );
	const get = () => rest( site, 'GET', ROUTE, { headers: { ...FRESH, origin: ORIGIN } } );

	it( 'adds QUERY when core’s handler is where core put it', async () => {
		const res = await preflight();
		expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( ORIGIN );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toContain( 'QUERY' );
	} );

	it( 'leaves REST CORS off when the site removed core’s handler', async () => {
		setOption( site, FIXTURE_REMOVE_CORE_CORS, 1 );
		try {
			for ( const res of [ await preflight(), await get() ] ) {
				expect( res.headers.get( 'access-control-allow-origin' ) ).toBeNull();
				expect( res.headers.get( 'access-control-allow-methods' ) ).toBeNull();
				expect( res.headers.get( 'access-control-allow-credentials' ) ).toBeNull();
			}
		} finally {
			deleteOption( site, FIXTURE_REMOVE_CORE_CORS );
		}
	} );

	it( 'replaces core’s handler at the priority the site moved it to, so QUERY stays in the one list', async () => {
		setOption( site, FIXTURE_REHOOK_CORE_CORS, 1 );
		try {
			const res = await preflight();
			expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( ORIGIN );
			const methods = tokens( res.headers.get( 'access-control-allow-methods' ) );
			expect( methods ).toContain( 'QUERY' );
			expect( methods ).toContain( 'GET' );
			expect( methods.filter( ( m ) => m === 'GET' ) ).toHaveLength( 1 );
		} finally {
			deleteOption( site, FIXTURE_REHOOK_CORE_CORS );
		}
	} );
} );
