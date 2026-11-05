/**
 * CORS: the plugin respects a site that turned REST CORS off, or another CORS
 * handler.
 *
 * The plugin only adds QUERY through core's rest_allowed_cors_methods filter.
 * This checks the site's own CORS setup still decides: core's handler removed
 * means no CORS at all, and a CORS callback that runs after core's, as a CORS
 * plugin's might, still wins.
 *
 * Runs in the serial lifecycle suite because it changes site state: the test
 * fixture's CORS option, set over WP-CLI and put back in afterAll. Every
 * request sends Connection: close (see session.ts).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, ORIGIN, rest, tokens } from '../http';
import { wp } from '../seed';
import { NO_LOCAL_CLI, type Site, sites } from '../sites';
import { FRESH } from './session';
import { FIXTURE_CORS, setOption, snapshotOption } from './site-state';

const ROUTE = CORE_COLLECTIONS[ 0 ];

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

	it( 'leaves REST CORS off when the site removed core’s handler', async () => {
		for ( const res of await withCorsScenario( site, { scenario: 'remove-late' }, bothRequests ) ) {
			expect( allowHeaders( res ) ).toEqual( [] );
		}
	} );

	it( 'lets a CORS callback that runs after core’s win', async () => {
		const competing = { scenario: 'competing', origin: 'https://allowed.example', methods: 'GET, OPTIONS' };
		for ( const res of await withCorsScenario( site, competing, bothRequests ) ) {
			expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( competing.origin );
			expect( res.headers.get( 'access-control-allow-methods' ) ).toBe( competing.methods );
			expect( res.headers.get( 'access-control-allow-credentials' ) ).toBeNull();
		}
	} );
} );
