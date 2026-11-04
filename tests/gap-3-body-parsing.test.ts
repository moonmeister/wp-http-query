/**
 * Gap 3: request body parsing. ADR 0001, option B.
 *
 * Core parses JSON and form-encoded bodies for QUERY like any other
 * body-bearing method. Stock core drops form-encoded QUERY bodies, because
 * QUERY is missing from $accepts_body_data; the plugin restores them through
 * rest_request_parameter_order. Losing them is the one silent-wrong-answer
 * failure in the project: a collection route returns everything, with a 200.
 *
 * Decision: docs/decisions/0001-query-body-media-type.md   Issue: #2
 */
import { describe, expect, it } from 'vitest';
import { ECHO, rest, type Echo } from './http';
import { sites } from './sites';

describe.each( sites )( 'gap 3 body parsing: $label', ( site ) => {
	it( 'reads a JSON body into the request params', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: { search: 'needle', per_page: 5 } } );
		expect( res.status ).toBe( 200 );

		const echo: Echo = await res.json();
		expect( echo.method ).toBe( 'QUERY' );
		expect( echo.merged_params ).toMatchObject( { search: 'needle', per_page: 5 } );
	} );

	it( 'reads a form-encoded body into the request params', async () => {
		const res = await rest( site, 'QUERY', ECHO, { form: { search: 'needle' } } );
		expect( res.status ).toBe( 200 );

		const echo: Echo = await res.json();
		expect( echo.merged_params ).toMatchObject( { search: 'needle' } );
	} );

	it( 'lets the body win over the query string, as for POST', async () => {
		const res = await fetch( `${ site.url }/wp-json${ ECHO }?search=from-url`, {
			method: 'QUERY',
			headers: { 'content-type': 'application/x-www-form-urlencoded' },
			body: 'search=from-body',
		} );

		const echo: Echo = await res.json();
		expect( echo.merged_params.search ).toBe( 'from-body' );
	} );
} );
