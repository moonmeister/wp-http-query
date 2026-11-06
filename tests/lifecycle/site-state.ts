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

export interface SiteChanges {
	/** Options to snapshot and restore without changing them. Those in `set` and `delete` are snapshotted too. */
	snapshot?: string[];
	/** Options to store, name => value. */
	set?: Record< string, unknown >;
	/** Options to delete. */
	delete?: string[];
	/** More PHP for the same `wp eval`, run after the option changes. */
	setUp?: string;
	/** More PHP for the restoring `wp eval`, run before the options are put back. */
	tearDown?: string;
}

/**
 * Changes site state for a test file in one `wp eval`, and returns a function
 * that undoes it in one more: tearDown's PHP, then every snapshotted option
 * back as it was, its stored value or no row at all.
 */
export function changeSite( site: Site, changes: SiteChanges ): () => void {
	const set = changes.set ?? {};
	const del = changes.delete ?? [];
	const names = [ ...new Set( [ ...( changes.snapshot ?? [] ), ...Object.keys( set ), ...del ] ) ];

	const putBack = `foreach ( $prior as $name => $was ) {
		null === $was ? delete_option( $name ) : update_option( $name, $was['value'] );
	}`;

	// The sentinel tells a missing row from any stored value; passing a default
	// also keeps a registered default from standing in for a missing row. If
	// setUp throws, the caller never gets a restore function, so the options go
	// back here before the error surfaces.
	const prior = evalJson< Record< string, { value: unknown } | null > >(
		site,
		`$missing = new stdClass();
		$prior = array();
		foreach ( $input['names'] as $name ) {
			$value = get_option( $name, $missing );
			$prior[ $name ] = $value === $missing ? null : array( 'value' => $value );
		}
		try {
			foreach ( $input['set'] as $name => $value ) {
				update_option( $name, $value );
			}
			foreach ( $input['delete'] as $name ) {
				delete_option( $name );
			}
			${ changes.setUp ?? '' }
		} catch ( Throwable $e ) {
			${ putBack }
			throw $e;
		}
		return $prior;`,
		{ names, set, delete: del }
	);

	return () => {
		evalJson( site, `${ changes.tearDown ?? '' }\n$prior = $input;\n${ putBack }`, prior );
	};
}

/** The stored preset setting in charge, and unset: the fixture's presets filter switched off. */
export const PRESET_SETTING_UNSET: SiteChanges = {
	set: { [ FIXTURE_PRESET_FILTER_OFF ]: 1 },
	delete: [ PRESETS_OPTION ],
};

export function activatePlugin( site: Site ): void {
	evalJson( site, ACTIVATE_PLUGIN_PHP );
}

export function deactivatePlugin( site: Site ): void {
	// --network on a network site, where the plugin is network-activated.
	wp( site, [ 'plugin', 'deactivate', PLUGIN, ...( site.network ? [ '--network' ] : [] ) ] );
}

/** The plugin's status as `wp plugin list` reports it: active, active-network or inactive. */
export function pluginStatus( site: Site ): string {
	return wp( site, [ 'plugin', 'list', `--name=${ PLUGIN }`, '--field=status' ] ).trim();
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
