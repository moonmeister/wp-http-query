/**
 * CORS: the plugin respects a site that turned REST CORS off, or moved it.
 *
 * Before 7.2 the plugin wraps core's CORS handler wherever it's hooked when
 * the response is served (see swap_cors_handler() in
 * plugin/includes/core-gaps.php for why). This checks, on every site: core's
 * handler removed late in rest_api_init, or while the response is served,
 * means no CORS; re-hooked at another priority, or at two, means one list
 * with QUERY; a restricted CORS callback after core's still wins. From 7.2 the
 * plugin uses core's rest_allowed_cors_methods filter, so nightly must behave
 * the same.
 *
 * Runs in the serial lifecycle suite because it changes site state: the test
 * fixture's CORS option, set over WP-CLI and put back in afterAll. Every
 * request sends Connection: close (see session.ts).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, CORE_CORS_METHODS, ORIGIN, rest, tokens } from '../http';
import { wp } from '../seed';
import { NO_LOCAL_CLI, type Site, sites } from '../sites';
import { FRESH } from './session';
import { FIXTURE_CORS, setOption, snapshotOption } from './site-state';

const ROUTE = CORE_COLLECTIONS[ 0 ];

/** What the plugin's wrapper sends before 7.2, and core's filter gives from 7.2. */
const WITH_QUERY = [ ...CORE_CORS_METHODS, 'QUERY' ];

/** Sets the fixture's CORS scenario for the length of fn, then deletes it in one WP-CLI call. */
async function withCorsScenario< T >( site: Site, value: Record< string, unknown >, fn: () => Promise< T > ): Promise< T > {
	setOption( site, FIXTURE_CORS, value );
	try {
		return await fn();
	} finally {
		wp( site, [ 'option', 'delete', FIXTURE_CORS ] );
	}
}

/**
 * The Access-Control-Allow-* headers CORS handlers send. Not Allow-Headers:
 * WP_REST_Server::serve_request() sends it on every REST response, CORS on or off.
 */
function allowHeaders( res: Response ): string[] {
	return [ ...res.headers.keys() ].filter(
		( name ) => name.startsWith( 'access-control-allow-' ) && name !== 'access-control-allow-headers'
	);
}

describe.each( sites )( 'CORS, as the site configured it: $label', ( site ) => {
	if ( ! site.localCli ) {
		it.skip( NO_LOCAL_CLI, () => {} );
		return;
	}

	let restore = () => {};
	beforeAll( () => {
		restore = snapshotOption( site, FIXTURE_CORS );
	} );
	afterAll( () => restore() );

	const preflight = () =>
		rest( site, 'OPTIONS', ROUTE, { headers: { ...FRESH, origin: ORIGIN, 'access-control-request-method': 'QUERY' } } );
	const get = () => rest( site, 'GET', ROUTE, { headers: { ...FRESH, origin: ORIGIN } } );
	const bothRequests = () => Promise.all( [ preflight(), get() ] );

	it( 'adds QUERY when core’s handler is where core put it', async () => {
		const res = await preflight();
		expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( ORIGIN );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toContain( 'QUERY' );
	} );

	it.each( [
		[ 'late in rest_api_init', { scenario: 'remove-late' } ],
		[ 'while the response is served, before core’s priority', { scenario: 'remove-during-serve', priority: 0 } ],
	] )( 'leaves REST CORS off when the site removed core’s handler %s', async ( _, scenario ) => {
		for ( const res of await withCorsScenario( site, scenario, bothRequests ) ) {
			expect( allowHeaders( res ) ).toEqual( [] );
		}
	} );

	it.each( [
		[ 'at the priority the site moved it to', { scenario: 'rehook', priority: 11 } ],
		[ 'at every priority it’s hooked at', { scenario: 'double', priority: 20 } ],
	] )( 'adds QUERY to the one list when core’s handler is hooked %s', async ( _, scenario ) => {
		const res = await withCorsScenario( site, scenario, preflight );
		expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( ORIGIN );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toEqual( WITH_QUERY );
	} );

	it( 'keeps core’s place in the hook, so a CORS callback that ran after core’s still wins', async () => {
		const competing = { scenario: 'competing', priority: 10, origin: 'https://allowed.example', methods: 'GET, OPTIONS' };
		for ( const res of await withCorsScenario( site, competing, bothRequests ) ) {
			expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( competing.origin );
			expect( res.headers.get( 'access-control-allow-methods' ) ).toBe( competing.methods );
			expect( res.headers.get( 'access-control-allow-credentials' ) ).toBeNull();
		}
	} );
} );
