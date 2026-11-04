/**
 * Reach, the preset setting: ADR 0005, issue #1.
 *
 * The 'core-collections' preset is switched by one stored option
 * (wp_http_query_presets, Settings → HTTP QUERY, default off), then by the
 * wp_http_query_presets filter, so code can override the setting.
 *
 * Runs in the serial lifecycle suite because it changes site state. The test
 * fixture's presets filter would force the preset on and hide the setting, so
 * these tests switch that filter off with a fixture-only option, set over
 * WP-CLI, and put everything back in afterAll. The fixture's route-filter
 * opt-in stays on throughout, which checks the two paths don't disturb each
 * other.
 *
 * Decision: docs/decisions/0005-reach-for-existing-read-routes.md   Issue: #1
 */
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, FIXTURE_OPTED_IN_ROUTE, rest } from '../http';
import { sites } from '../sites';
import {
	deleteOption,
	FIXTURE_PRESET_FILTER_OFF,
	getOption,
	PRESETS_OPTION,
	setOption,
	snapshotOption,
} from './site-state';

const ENV_SH = fileURLToPath( new URL( '../../scripts/env.sh', import.meta.url ) );

/**
 * Stores a value through WP-CLI, which runs the registered sanitize callback,
 * and returns what PHP wrote to stderr. The test sites run with WP_DEBUG on, so
 * a warning or notice from the sanitizer shows up there.
 */
function setOptionCapturingStderr( site: ( typeof sites )[ number ], name: string, value: unknown ): string {
	const result = spawnSync(
		ENV_SH,
		[ 'wp', site.key, 'option', 'update', name, JSON.stringify( value ), '--format=json' ],
		{ encoding: 'utf8' }
	);
	if ( result.status !== 0 ) {
		throw new Error( `wp option update failed: ${ result.stderr }` );
	}
	return result.stderr;
}

async function statuses( site: ( typeof sites )[ number ], routes: string[] ): Promise< Record< string, number > > {
	const out: Record< string, number > = {};
	for ( const route of routes ) {
		out[ route ] = ( await rest( site, 'QUERY', route, { json: {} } ) ).status;
	}
	return out;
}

const all = ( status: number ) => Object.fromEntries( CORE_COLLECTIONS.map( ( r ) => [ r, status ] ) );

describe.each( sites )( 'ADR 0005 preset setting: $label', ( site ) => {
	let restorePresets = () => {};
	let restoreFilterSwitch = () => {};

	beforeAll( () => {
		restorePresets = snapshotOption( site, PRESETS_OPTION );
		restoreFilterSwitch = snapshotOption( site, FIXTURE_PRESET_FILTER_OFF );
		setOption( site, FIXTURE_PRESET_FILTER_OFF, 1 );
		deleteOption( site, PRESETS_OPTION );
	} );

	afterAll( () => {
		restoreFilterSwitch();
		restorePresets();
	} );

	it( 'is off when the option is unset', async () => {
		expect( await statuses( site, CORE_COLLECTIONS ) ).toEqual( all( 404 ) );
		// The route filter is a separate path and keeps working.
		expect( ( await rest( site, 'QUERY', FIXTURE_OPTED_IN_ROUTE, { json: {} } ) ).status ).toBe( 200 );
	} );

	it( 'adds QUERY to every preset route when enabled, and nowhere else', async () => {
		setOption( site, PRESETS_OPTION, [ 'core-collections' ] );
		expect( await statuses( site, CORE_COLLECTIONS ) ).toEqual( all( 200 ) );
		expect( ( await rest( site, 'QUERY', '/wp/v2/types' ) ).status ).toBe( 404 );
	} );

	it( 'stores known preset names only', () => {
		setOption( site, PRESETS_OPTION, [ 'no-such-preset', 'core-collections' ] );
		expect( getOption( site, PRESETS_OPTION ) ).toEqual( [ 'core-collections' ] );
	} );

	it( 'removes QUERY again when disabled', async () => {
		setOption( site, PRESETS_OPTION, [] );
		expect( await statuses( site, CORE_COLLECTIONS ) ).toEqual( all( 404 ) );
	} );

	it( 'lets the wp_http_query_presets filter override the setting', async () => {
		setOption( site, PRESETS_OPTION, [] );
		deleteOption( site, FIXTURE_PRESET_FILTER_OFF ); // The fixture's filter enables the preset.
		expect( await statuses( site, CORE_COLLECTIONS ) ).toEqual( all( 200 ) );
	} );

	// Last in the block: it spends seconds in WP-CLI, and a following HTTP test could
	// reuse a keep-alive socket Apache has already closed.
	it( 'drops malformed entries without PHP warnings or notices', () => {
		// A crafted options.php post can nest arrays; this once raised an
		// "Array to string conversion" notice inside the sanitizer.
		const stderr = setOptionCapturingStderr( site, PRESETS_OPTION, [
			[ 'core-collections' ],
			{ name: 'core-collections' },
			42,
			null,
			'core-collections',
		] );

		expect( getOption( site, PRESETS_OPTION ) ).toEqual( [ 'core-collections' ] );
		expect( stderr ).not.toMatch( /\b(Warning|Notice|Deprecated|Fatal error)\b/ );
	} );
} );
