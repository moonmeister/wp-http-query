/**
 * Gap 1: CORS. Access-Control-Allow-Methods must include QUERY, or browsers
 * block cross-origin QUERY at the preflight.
 *
 * The plugin adds QUERY through core's rest_allowed_cors_methods filter
 * (r64730, Trac#46992). Core's default methods must survive: the plugin adds,
 * never replaces.
 *
 * Issue: #3   Core: https://core.trac.wordpress.org/changeset/64730
 */
import { describe, expect, it } from 'vitest';
import { CORE_CORS_METHODS, ECHO, ORIGIN, rest, tokens } from './http';
import { sites } from './sites';

describe.each( sites )( 'gap 1 CORS: $label', ( site ) => {
	it( 'lists QUERY on a cross-origin QUERY response', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {}, headers: { origin: ORIGIN } } );

		expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( ORIGIN );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toContain( 'QUERY' );
	} );

	it( 'lists QUERY on the preflight', async () => {
		const res = await rest( site, 'OPTIONS', ECHO, {
			headers: { origin: ORIGIN, 'access-control-request-method': 'QUERY' },
		} );

		expect( res.status ).toBe( 200 );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toContain( 'QUERY' );
	} );

	it( "keeps core's default methods", async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {}, headers: { origin: ORIGIN } } );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toEqual(
			expect.arrayContaining( CORE_CORS_METHODS )
		);
	} );

	it( 'sends QUERY once', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {}, headers: { origin: ORIGIN } } );
		const methods = tokens( res.headers.get( 'access-control-allow-methods' ) );
		expect( methods.filter( ( m ) => m === 'QUERY' ) ).toHaveLength( 1 );
	} );
} );
