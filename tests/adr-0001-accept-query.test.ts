/**
 * Accept-Query advertisement (RFC 10008 §3). ADR 0001 open question 2, #20.
 *
 * Current default, adopted 2026-11-04 and revisitable: advertise
 * application/json ONLY, while still parsing form-encoded bodies (see
 * gap-3-body-parsing.test.ts). Accepting form-encoded is a bug fix;
 * advertising it would endorse a poor structured-query format.
 *
 * If that default changes, change the expected list here, on purpose.
 *
 * Decision: docs/decisions/0001-query-body-media-type.md   Issues: #4, #20
 */
import { parseList, Token } from 'structured-headers';
import { describe, expect, it } from 'vitest';
import { ECHO, rest } from './http';
import { sites } from './sites';

const ADVERTISED = [ 'application/json' ];

describe.each( sites )( 'Accept-Query: $label', ( site ) => {
	it( 'is sent on a route that accepts QUERY', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {} } );
		expect( res.headers.get( 'accept-query' ) ).not.toBeNull();
	} );

	it( 'is a valid Structured Fields list of media types', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {} } );
		const list = parseList( res.headers.get( 'accept-query' ) ?? '' );

		const types = list.map( ( [ item ] ) => {
			expect( typeof item === 'string' || item instanceof Token ).toBe( true );
			return item.toString();
		} );

		expect( types.length ).toBeGreaterThan( 0 );
	} );

	it( 'advertises application/json only', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {} } );
		const types = parseList( res.headers.get( 'accept-query' ) ?? '' ).map( ( [ item ] ) => item.toString() );

		expect( types ).toEqual( ADVERTISED );
	} );

	it( 'does not advertise form-encoded, even though it is parsed', async () => {
		const res = await rest( site, 'QUERY', ECHO, { form: { search: 'needle' } } );
		expect( res.status ).toBe( 200 );
		expect( res.headers.get( 'accept-query' ) ).not.toContain( 'x-www-form-urlencoded' );
	} );
} );
