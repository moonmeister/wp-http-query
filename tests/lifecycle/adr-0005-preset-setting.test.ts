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
import { afterAll, beforeAll, expect, it } from 'vitest';
import { CORE_COLLECTIONS, FIXTURE_OPTED_IN_ROUTE, rest } from '../http';
import { wpWithStderr } from '../seed';
import { describeLocalSites, type Site } from '../sites';
import {
	changeSite,
	deleteOption,
	FIXTURE_PRESET_FILTER_OFF,
	getOption,
	PRESET_SETTING_UNSET,
	PRESETS_OPTION,
	setOption,
} from './site-state';

/** QUERY status per route, the requests sent together. */
async function statuses( site: Site, routes: string[] ): Promise< Record< string, number > > {
	const responses = await Promise.all( routes.map( ( route ) => rest( site, 'QUERY', route, { json: {} } ) ) );
	return Object.fromEntries( routes.map( ( route, i ) => [ route, responses[ i ].status ] ) );
}

const all = ( status: number ) => Object.fromEntries( CORE_COLLECTIONS.map( ( r ) => [ r, status ] ) );

describeLocalSites( 'ADR 0005 preset setting: $label', ( site ) => {
	let restore = () => {};

	beforeAll( () => {
		restore = changeSite( site, PRESET_SETTING_UNSET );
	} );

	afterAll( () => restore() );

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

	it( 'drops malformed entries without PHP warnings or notices', () => {
		// A crafted options.php post can nest arrays; this once raised an
		// "Array to string conversion" notice inside the sanitizer. WP-CLI runs
		// the registered sanitize callback, and the test sites run with WP_DEBUG
		// on, so a warning or notice from it shows up on stderr.
		const malformed = [ [ 'core-collections' ], { name: 'core-collections' }, 42, null, 'core-collections' ];
		const { stderr } = wpWithStderr( site, [ 'option', 'update', PRESETS_OPTION, JSON.stringify( malformed ), '--format=json' ] );

		expect( getOption( site, PRESETS_OPTION ) ).toEqual( [ 'core-collections' ] );
		expect( stderr ).not.toMatch( /\b(Warning|Notice|Deprecated|Fatal error)\b/ );
	} );
} );
