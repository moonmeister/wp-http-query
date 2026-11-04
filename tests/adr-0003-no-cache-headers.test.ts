/**
 * Cache safety. ADR 0003, option C: emit nothing method-specific.
 *
 * No shipping cache stores a QUERY response (experiments/cache-survey/), so
 * the plugin deliberately sends no Cache-Control of its own. An earlier draft
 * sent `Cache-Control: private, no-cache` on every QUERY; this guards against
 * it coming back.
 *
 * Decision: docs/decisions/0003-cache-safety-default.md   Issue: #18
 */
import { describe, expect, it } from 'vitest';
import { ECHO, rest } from './http';
import { sites } from './sites';

describe.each( sites )( 'ADR 0003 no cache headers: $label', ( site ) => {
	it( 'adds no Cache-Control to an anonymous QUERY response', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {} } );

		expect( res.status ).toBe( 200 );
		expect( res.headers.get( 'cache-control' ) ).toBeNull();
	} );
} );
