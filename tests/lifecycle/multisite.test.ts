/**
 * Multisite: issue #46.
 *
 * The rest of the suite runs on the network's main site and its /sub/ subsite
 * as well (SITES=multisite,multisite-sub), which covers reach, CORS,
 * Accept-Query and the self-test under a site path. This file covers what
 * only a network has:
 *
 * - The preset setting is per site: enabling it on one site changes nothing
 *   on another.
 * - Uninstall, run once for the whole network, deletes the option on every
 *   site, across uninstall.php's batches of 100, including archived, spam and
 *   deleted sites.
 *
 * Runs only when SITES includes multisite. Restores the plugin's network
 * activation and both sites' options in afterAll. The extra sites it creates
 * for the batch boundary are kept, so later runs skip creating them.
 *
 * Issue: #46
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, rest } from '../http';
import { evalJson } from '../seed';
import { networkSites, sites } from '../sites';
import {
	ACTIVATE_PLUGIN_PHP,
	changeSite,
	deleteOption,
	pluginStatus,
	PRESET_SETTING_UNSET,
	PRESETS_OPTION,
	setOption,
	uninstallPluginKeepingFiles,
} from './site-state';

const [ main, sub ] = networkSites;

/** uninstall.php's get_sites() batch size. The network needs more sites than this. */
const BATCH = 100;

/** Slug prefix of the sites this file adds to cross a batch boundary. */
const FILLER = 'wphq-batch-';

/** A preset route; the fixture's own route opt-ins don't include it. */
const PRESET_ROUTE = CORE_COLLECTIONS[ 0 ];

/** Runs PHP on the main site through `wp eval` and returns what it returns. */
const evalPhp = < T = unknown >( php: string ): T => evalJson< T >( main, php );

describe.skipIf( ! sites.includes( main ) || ! main.localCli )( 'multisite network', () => {
	const restore: ( () => void )[] = [];

	beforeAll( () => {
		// The fixture's presets filter would force the preset on everywhere. The
		// main site's restore network-activates the plugin again first. Each
		// restore is kept as soon as it exists, so a later failure can't drop it.
		for ( const site of [ main, sub ] ) {
			restore.push( changeSite( site, { ...PRESET_SETTING_UNSET, tearDown: site === main ? ACTIVATE_PLUGIN_PHP : '' } ) );
		}
	} );

	afterAll( () => restore.forEach( ( fn ) => fn() ) );

	it( 'keeps the preset setting per site', async () => {
		for ( const [ on, off ] of [ [ sub, main ], [ main, sub ] ] ) {
			deleteOption( off, PRESETS_OPTION );
			setOption( on, PRESETS_OPTION, [ 'core-collections' ] );
			expect( ( await rest( on, 'QUERY', PRESET_ROUTE, { json: {} } ) ).status ).toBe( 200 );
			expect( ( await rest( off, 'QUERY', PRESET_ROUTE, { json: {} } ) ).status ).toBe( 404 );
		}
	} );

	// Last: it uninstalls the plugin, and afterAll network-activates it again.
	it( 'deletes the option on every site of the network on uninstall', () => {
		// BATCH sites of its own, plus / and /sub/, make a second batch whatever else
		// is on the network. One wp eval, not a wp site create per site: each WP-CLI
		// call through wp-env costs about a second. Idempotent by slug.
		evalPhp( `
			$network = get_network();
			for ( $i = 1; $i <= ${ BATCH }; $i++ ) {
				$path = $network->path . '${ FILLER }' . $i . '/';
				if ( ! domain_exists( $network->domain, $path, $network->id ) ) {
					wp_insert_site( array( 'domain' => $network->domain, 'path' => $path, 'title' => '${ FILLER }' . $i ) );
				}
			}` );

		// The option on every site, and one site each archived, spam and deleted:
		// get_sites() includes them by default, so uninstall must clean them too.
		const total = evalPhp< number >( `
			$ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $ids as $id ) {
				update_blog_option( $id, '${ PRESETS_OPTION }', array( 'core-collections' ) );
			}
			// Only this file's own sites: never flag a site a developer added by hand.
			$fillers = get_sites( array( 'fields' => 'ids', 'search' => '${ FILLER }', 'search_columns' => array( 'path' ), 'number' => 3 ) );
			foreach ( array( 'archived', 'spam', 'deleted' ) as $i => $flag ) {
				update_blog_status( $fillers[ $i ], $flag, 1 );
			}
			return count( $ids );` );
		expect( total ).toBeGreaterThan( BATCH );

		// Rows, straight from each site's options table: nothing registered can stand in.
		const withOption = `
			global $wpdb;
			$left = 0;
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
				$left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->get_blog_prefix( $id )}options WHERE option_name = %s", '${ PRESETS_OPTION }' ) );
			}
			return $left;`;
		expect( evalPhp( withOption ) ).toBe( total );

		uninstallPluginKeepingFiles( main );

		expect( evalPhp( withOption ) ).toBe( 0 );
		expect( pluginStatus( main ) ).toBe( 'inactive' );
	} );
} );
