/**
 * The WordPress sites every decision test runs against.
 *
 * From scripts/env.sh (`make up`): the nightly build, since the plugin needs
 * WordPress 7.2 or later.
 *
 * Limit with SITES, e.g. SITES=nightly. Override the URL with WP_NIGHTLY_URL
 * to point the suite at another install.
 *
 * An overridden site is HTTP-only: WP-CLI always reaches the local wp-env
 * container, never the overridden install, so anything needing WP-CLI
 * (seeding, option changes, plugin activation) is skipped for it. See
 * `localCli` and the guard in tests/seed.ts.
 */
export interface Site {
	key: 'nightly';
	label: string;
	url: string;
	/** True when `url` is the local wp-env site, so WP-CLI (scripts/env.sh) targets the same install. */
	localCli: boolean;
}

const all: Site[] = [
	{
		key: 'nightly',
		label: 'nightly',
		url: process.env.WP_NIGHTLY_URL ?? 'http://localhost:8872',
		localCli: ! process.env.WP_NIGHTLY_URL,
	},
];

const wanted = process.env.SITES?.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );

export const sites = wanted?.length ? all.filter( ( s ) => wanted.includes( s.key ) ) : all;

/** Why WP-CLI-dependent tests are skipped for a site whose URL is overridden. */
export const NO_LOCAL_CLI = 'skipped: URL overridden, and WP-CLI only reaches the local wp-env site';
