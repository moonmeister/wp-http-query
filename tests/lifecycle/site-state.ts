import { wp } from '../seed';
import type { Site } from '../sites';

export const PLUGIN = 'wp-http-query';

/** The plugin's one stored option: enabled preset names. */
export const PRESETS_OPTION = 'wp_http_query_presets';

/** Test fixture switch that stops its presets filter forcing the preset on. */
export const FIXTURE_PRESET_FILTER_OFF = 'wp_http_query_fixture_preset_filter_off';

/** Test fixture switch that stops it turning the plugin's echo route on. */
export const FIXTURE_DEMO_ROUTE_OFF = 'wp_http_query_fixture_demo_route_off';

/** Test fixture switch that removes core's REST CORS handler, as a site turning REST CORS off does. */
export const FIXTURE_REMOVE_CORE_CORS = 'wp_http_query_fixture_remove_core_cors';

/** Test fixture switch that moves core's REST CORS handler to priority 11. */
export const FIXTURE_REHOOK_CORE_CORS = 'wp_http_query_fixture_rehook_core_cors';

/** Test fixture switch that removes core's REST CORS handler late, on rest_api_init at priority 99. */
export const FIXTURE_REMOVE_CORE_CORS_LATE = 'wp_http_query_fixture_remove_core_cors_late';

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

export function deleteOption( site: Site, name: string ): void {
	if ( optionExists( site, name ) ) {
		wp( site, [ 'option', 'delete', name ] );
	}
}

/**
 * Snapshots an option and returns a function that puts it back: the stored
 * value if there was one, otherwise no row at all.
 */
export function snapshotOption( site: Site, name: string ): () => void {
	const existed = optionExists( site, name );
	const value = existed ? getOption( site, name ) : undefined;

	return () => {
		if ( existed ) {
			setOption( site, name, value );
		} else {
			deleteOption( site, name );
		}
	};
}

export function activatePlugin( site: Site ): void {
	wp( site, [ 'plugin', 'activate', PLUGIN ] );
}

export function deactivatePlugin( site: Site ): void {
	wp( site, [ 'plugin', 'deactivate', PLUGIN ] );
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
	wp( site, [ 'plugin', 'uninstall', PLUGIN, '--deactivate', '--skip-delete' ] );
}
