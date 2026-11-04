/**
 * The WordPress sites every decision test runs against.
 *
 * All come from scripts/env.sh (`make up`): the last two releases, pinned, and
 * nightly. The plugin has two CORS code paths: 7.2 added the
 * `rest_allowed_cors_methods` filter (r64730), so nightly uses it and 7.0/7.1
 * use the plugin's replacement handler.
 *
 * Limit with SITES, e.g. SITES=7.0,nightly. Override a URL with WP_70_URL,
 * WP_71_URL or WP_NIGHTLY_URL to point the suite at another install.
 *
 * An overridden site is HTTP-only: WP-CLI always reaches the local wp-env
 * container, never the overridden install, so anything needing WP-CLI
 * (seeding, option changes, plugin activation) is skipped for it. See
 * `localCli` and the guard in tests/seed.ts.
 */
export interface Site {
	key: '7.0' | '7.1' | 'nightly';
	label: string;
	url: string;
	/** True when `url` is the local wp-env site, so WP-CLI (scripts/env.sh) targets the same install. */
	localCli: boolean;
}

const all: Site[] = [
	{
		key: '7.0',
		label: '7.0.8 (CORS fallback handler)',
		url: process.env.WP_70_URL ?? 'http://localhost:8870',
		localCli: ! process.env.WP_70_URL,
	},
	{
		key: '7.1',
		label: '7.1.4 (CORS fallback handler)',
		url: process.env.WP_71_URL ?? 'http://localhost:8871',
		localCli: ! process.env.WP_71_URL,
	},
	{
		key: 'nightly',
		label: 'nightly (rest_allowed_cors_methods)',
		url: process.env.WP_NIGHTLY_URL ?? 'http://localhost:8872',
		localCli: ! process.env.WP_NIGHTLY_URL,
	},
];

const wanted = process.env.SITES?.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );

export const sites = wanted?.length ? all.filter( ( s ) => wanted.includes( s.key ) ) : all;

/** Why WP-CLI-dependent tests are skipped for a site whose URL is overridden. */
export const NO_LOCAL_CLI = 'skipped: URL overridden, and WP-CLI only reaches the local wp-env site';
