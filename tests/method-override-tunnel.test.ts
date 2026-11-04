/**
 * The X-HTTP-Method-Override tunnel: POST carrying QUERY, for clients and
 * intermediaries that cannot send the method itself. Core supports it; this
 * checks the plugin's QUERY handling works through it too.
 *
 * It is a tunnel, not the method: intermediaries see POST, so RFC 10008
 * cacheability and retry safety do not apply.
 *
 * Issue: #22
 */
import { describe, expect, it } from 'vitest';
import { ECHO, rest, type Echo } from './http';
import { sites } from './sites';

describe.each( sites )( 'method override tunnel: $label', ( site ) => {
	it( 'dispatches POST + X-HTTP-Method-Override: QUERY as QUERY', async () => {
		const res = await rest( site, 'POST', ECHO, {
			json: { search: 'needle' },
			headers: { 'x-http-method-override': 'QUERY' },
		} );
		expect( res.status ).toBe( 200 );

		const echo: Echo = await res.json();
		expect( echo.method ).toBe( 'QUERY' );
		expect( echo.via_override ).toBe( true );
		expect( echo.merged_params ).toMatchObject( { search: 'needle' } );
	} );

	it( 'does not reach a QUERY-only route with a plain POST', async () => {
		const res = await rest( site, 'POST', ECHO, { json: {} } );
		expect( res.status ).toBe( 404 );
	} );
} );
