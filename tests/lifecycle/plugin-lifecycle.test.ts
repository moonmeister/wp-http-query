/**
 * Deactivation and uninstall: issue #22.
 *
 * Deactivated, the site behaves like stock WordPress: no QUERY anywhere, no
 * QUERY in CORS, no Accept-Query. Reactivating brings it all back. Uninstall
 * deletes the plugin's one option and nothing else.
 *
 * Runs in the serial lifecycle suite because it deactivates the plugin.
 * afterAll reactivates it and restores the option even if a test fails.
 *
 * Issue: #22
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, ECHO, FIXTURE_OPTED_IN_ROUTE, ORIGIN, rest, tokens } from '../http';
import { describeLocalSites, networkSites } from '../sites';
import {
	ACTIVATE_PLUGIN_PHP,
	activatePlugin,
	changeSite,
	deactivatePlugin,
	optionExists,
	pluginStatus,
	PRESETS_OPTION,
	setOption,
	uninstallPluginKeepingFiles,
} from './site-state';

/** In the fixture's preset, so it has QUERY while the plugin is active. */
const PRESET_ROUTE = CORE_COLLECTIONS[ 0 ];

describeLocalSites( 'plugin lifecycle: $label', ( site ) => {
	const restorePresets: ( () => void )[] = [];

	beforeAll( () => {
		// On a network, uninstall deletes the option from every site. This site
		// goes first: its snapshot and restore also make sure the plugin is active.
		// Each restore is kept as soon as it exists, so a later failure can't drop it.
		restorePresets.push( changeSite( site, { snapshot: [ PRESETS_OPTION ], setUp: ACTIVATE_PLUGIN_PHP, tearDown: ACTIVATE_PLUGIN_PHP } ) );
		for ( const other of site.network ? networkSites.filter( ( s ) => s !== site ) : [] ) {
			restorePresets.push( changeSite( other, { snapshot: [ PRESETS_OPTION ] } ) );
		}
	} );

	afterAll( () => restorePresets.forEach( ( restore ) => restore() ) );

	describe( 'deactivated', () => {
		beforeAll( () => deactivatePlugin( site ) );

		it( 'no longer registers the echo route', async () => {
			expect( ( await rest( site, 'QUERY', ECHO, { json: {} } ) ).status ).toBe( 404 );
		} );

		it( 'answers QUERY with 404 on routes that were opted in', async () => {
			expect( ( await rest( site, 'QUERY', FIXTURE_OPTED_IN_ROUTE, { json: {} } ) ).status ).toBe( 404 );
			expect( ( await rest( site, 'QUERY', PRESET_ROUTE, { json: {} } ) ).status ).toBe( 404 );
		} );

		it( 'leaves QUERY out of Access-Control-Allow-Methods', async () => {
			const res = await rest( site, 'OPTIONS', PRESET_ROUTE, {
				headers: { origin: ORIGIN, 'access-control-request-method': 'QUERY' },
			} );
			const methods = tokens( res.headers.get( 'access-control-allow-methods' ) );
			expect( methods ).toContain( 'GET' );
			expect( methods ).not.toContain( 'QUERY' );
		} );

		it( 'sends no Accept-Query, and no QUERY in Allow', async () => {
			const res = await rest( site, 'GET', PRESET_ROUTE );
			expect( res.status ).toBe( 200 );
			expect( res.headers.has( 'accept-query' ) ).toBe( false );
			expect( tokens( res.headers.get( 'allow' ) ) ).not.toContain( 'QUERY' );
		} );
	} );

	it( 'restores everything on reactivation', async () => {
		activatePlugin( site );

		expect( ( await rest( site, 'QUERY', ECHO, { json: {} } ) ).status ).toBe( 200 );
		const res = await rest( site, 'GET', PRESET_ROUTE, { headers: { origin: ORIGIN } } );
		expect( res.headers.get( 'accept-query' ) ).toBe( '"application/json"' );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toContain( 'QUERY' );
	} );

	it( 'deletes its option on uninstall, and only on uninstall', () => {
		setOption( site, PRESETS_OPTION, [ 'core-collections' ] );

		deactivatePlugin( site );
		expect( optionExists( site, PRESETS_OPTION ) ).toBe( true );

		uninstallPluginKeepingFiles( site );
		expect( optionExists( site, PRESETS_OPTION ) ).toBe( false );
		// --skip-delete kept the files, so the plugin can come back.
		expect( pluginStatus( site ) ).toBe( 'inactive' );

		activatePlugin( site );
	} );
} );
