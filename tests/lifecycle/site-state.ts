import { evalJson, wp } from '../seed';
import type { Site } from '../sites';

export const PLUGIN = 'wp-http-query';

/** The plugin's one stored option: enabled preset names. */
export const PRESETS_OPTION = 'wp_http_query_presets';

/** Test fixture switch that stops its presets filter forcing the preset on. */
export const FIXTURE_PRESET_FILTER_OFF = 'wp_http_query_fixture_preset_filter_off';

/** Test fixture switch that stops it turning the plugin's echo route on. */
export const FIXTURE_DEMO_ROUTE_OFF = 'wp_http_query_fixture_demo_route_off';

/** Test fixture option that changes how REST CORS is handled: { scenario, priority, ... }. See the fixture's CORS. */
export const FIXTURE_CORS = 'wp_http_query_fixture_cors';

/** Whether an option row exists, read from the database, ignoring registered defaults. */
export function optionExists( site: Site, name: string ): boolean {
	return wp( site, [ 'option', 'list', `--search=${ name }`, '--format=count' ] ).trim() !== '0';
}

export function setOption( site: Site, name: string, value: unknown ): void {
	wp( site, [ 'option', 'update', name, JSON.stringify( value ), '--format=json' ] );
}

export function getOption( site: Site, name: string ): unknown {
	return JSON.parse( wp( site, [ 'option', 'get', name, '--format=json' ] ) );
}

/** Deletes an option, whether or not it exists. */
export function deleteOption( site: Site, name: string ): void {
	evalJson( site, 'delete_option( $input );', name );
}

/** PHP that activates the plugin as `wp plugin activate` does: network-wide on a network. No-op if already active. */
export const ACTIVATE_PLUGIN_PHP = `
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$activated = activate_plugin( '${ PLUGIN }/${ PLUGIN }.php', '', is_multisite() );
	if ( is_wp_error( $activated ) ) {
		throw new Exception( $activated->get_error_message() );
	}`;

export interface OptionChanges {
	/** Options to store, name => value. */
	set?: Record< string, unknown >;
	/** Options to delete. */
	delete?: string[];
	/** More PHP to run in the same `wp eval`, after the changes. */
	setUp?: string;
	/** More PHP for restore() to run in its `wp eval`, before putting the options back. */
	tearDown?: string;
}

/**
 * Snapshots options, applies `changes`, and returns a function that puts the
 * options back: each stored value if there was one, otherwise no row at all.
 * One `wp eval` each way.
 */
export function snapshotOptions( site: Site, names: string[], changes: OptionChanges = {} ): () => void {
	// The sentinel tells a missing row from any stored value; passing a default
	// also keeps a registered default from standing in for a missing row.
	const prior = evalJson< Record< string, { value: unknown } | null > >(
		site,
		`$missing = new stdClass();
		$prior = array();
		foreach ( $input['names'] as $name ) {
			$value = get_option( $name, $missing );
			$prior[ $name ] = $value === $missing ? null : array( 'value' => $value );
		}
		foreach ( $input['set'] as $name => $value ) {
			update_option( $name, $value );
		}
		foreach ( $input['delete'] as $name ) {
			delete_option( $name );
		}
		${ changes.setUp ?? '' }
		return $prior;`,
		{ names, set: changes.set ?? {}, delete: changes.delete ?? [] }
	);

	return () => {
		evalJson(
			site,
			`${ changes.tearDown ?? '' }
			foreach ( $input as $name => $was ) {
				null === $was ? delete_option( $name ) : update_option( $name, $was['value'] );
			}`,
			prior
		);
	};
}

/** WP-CLI's --network on a network site, where the plugin is network-activated. */
const networkFlag = ( site: Site ): string[] => ( site.network ? [ '--network' ] : [] );

export function activatePlugin( site: Site ): void {
	wp( site, [ 'plugin', 'activate', PLUGIN, ...networkFlag( site ) ] );
}

export function deactivatePlugin( site: Site ): void {
	wp( site, [ 'plugin', 'deactivate', PLUGIN, ...networkFlag( site ) ] );
}

/**
 * Runs the plugin's uninstall routine (uninstall.php) WITHOUT deleting it.
 *
 * --skip-delete is not optional here: plugin/ is this checkout, mounted into
 * the site, so a real uninstall would delete the working tree's plugin files.
 * Everything else is what WordPress does on uninstall: deactivate, then
 * uninstall_plugin(), which defines WP_UNINSTALL_PLUGIN and includes
 * uninstall.php.
 */
export function uninstallPluginKeepingFiles( site: Site ): void {
	// Not `uninstall --deactivate`: on a network it warns, leaves the plugin
	// network-active and uninstalls it anyway.
	deactivatePlugin( site );
	wp( site, [ 'plugin', 'uninstall', PLUGIN, '--skip-delete' ] );
}
