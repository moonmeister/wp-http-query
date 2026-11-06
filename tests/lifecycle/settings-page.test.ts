/**
 * Settings → HTTP QUERY, and the Settings link on the Plugins screen.
 *
 * Real HTTP as an admin: log in through wp-login.php, read the pages, and
 * save the preset through the page's own form and options.php, the way a
 * browser does. WP-CLI only reads the stored option back and sets up state.
 *
 * Runs in the serial lifecycle suite because saving changes site state. The
 * test fixture's presets filter would force the preset on and hide the
 * setting, so it is switched off with the fixture-only option, as in
 * adr-0005-preset-setting.test.ts, and everything is put back in afterAll.
 *
 * Decision: docs/decisions/0005-reach-for-existing-read-routes.md
 */
import { afterAll, beforeAll, expect, it } from 'vitest';
import { CORE_COLLECTIONS, ECHO, FIXTURE_OPTED_IN_ROUTE, rest, SETTINGS_PAGE } from '../http';
import { describeLocalSites, type Site } from '../sites';
import {
	changeSite,
	deleteOption,
	FIXTURE_DEMO_ROUTE_OFF,
	FIXTURE_PRESET_FILTER_OFF,
	getOption,
	PRESETS_OPTION,
	PRESET_SETTING_UNSET,
	setOption,
} from './site-state';
import { Session } from './session';

const CHECKBOX = 'id="wp_http_query_presets-core-collections"';
const PRESET_ROUTE = CORE_COLLECTIONS[ 0 ];
/** Registered by the test fixture with 'QUERY' in its methods. */
const SELF_REGISTERED_ROUTE = '/wp-http-query-test/v1/queryable';

/** A logged-in wp-admin session that can also submit the settings page's form. */
class Admin extends Session {
	/** Submits the settings page's form, as a browser would, with or without the box ticked. */
	async savePreset( checked: boolean ): Promise< Response > {
		const page = await this.html( SETTINGS_PAGE );
		const form = new URLSearchParams( {
			'option_page': hiddenField( page, 'option_page' ),
			action: hiddenField( page, 'action' ),
			_wpnonce: hiddenField( page, '_wpnonce' ),
			'_wp_http_referer': hiddenField( page, '_wp_http_referer' ),
		} );
		// The page's hidden empty entry, so an unticked box still posts the field.
		form.append( `${ PRESETS_OPTION }[]`, '' );
		if ( checked ) {
			form.append( `${ PRESETS_OPTION }[]`, 'core-collections' );
		}
		form.append( 'submit', 'Save Changes' );

		return this.request( '/wp-admin/options.php', { form } );
	}
}

/** Value of a hidden input, matched by name, whichever quotes WordPress used. */
function hiddenField( html: string, name: string ): string {
	const tag = html.match( new RegExp( `<input[^>]*name=['"]${ name }['"][^>]*>` ) )?.[ 0 ];
	const value = tag?.match( /value=['"]([^'"]*)['"]/ )?.[ 1 ];
	if ( value === undefined ) {
		throw new Error( `No ${ name } field on the settings page` );
	}
	return value;
}

/** The status table's rows as route => why, unescaped. */
function statusRows( html: string ): Record< string, string > {
	const table = html.match( /<table[^>]*id="wp-http-query-status"[^>]*>([\s\S]*?)<\/table>/ )?.[ 1 ] ?? '';
	const decode = ( s: string ) => s.replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&amp;/g, '&' );
	return Object.fromEntries(
		[ ...table.matchAll( /<tr><td><code>(.*?)<\/code><\/td><td>(.*?)<\/td><\/tr>/g ) ].map( ( [ , route, why ] ) => [
			decode( route ),
			decode( why ),
		] )
	);
}

const query = async ( site: Site, route: string ) =>
	( await rest( site, 'QUERY', route, { json: {} } ) ).status;

describeLocalSites( 'settings page: $label', ( site ) => {
	const admin = new Admin( site );
	let restore = () => {};

	beforeAll( async () => {
		restore = changeSite( site, { ...PRESET_SETTING_UNSET, snapshot: [ FIXTURE_DEMO_ROUTE_OFF ] } );
		await admin.logIn();
	} );

	afterAll( () => restore() );

	it( 'sends a logged-out visitor to the login page instead', async () => {
		const res = await new Session( site ).request( SETTINGS_PAGE );
		expect( res.status ).toBe( 302 );
		expect( res.headers.get( 'location' ) ).toContain( '/wp-login.php' );
		expect( await res.text() ).not.toContain( CHECKBOX );
	} );

	it( 'renders the preset checkbox, unticked, and the status table', async () => {
		const page = await admin.html( SETTINGS_PAGE );

		expect( page ).toContain( '<h1>HTTP QUERY</h1>' );
		expect( page ).toContain( "name='option_page' value='wp-http-query'" );
		expect( page ).toMatch( new RegExp( `<input type="checkbox" ${ CHECKBOX } name="wp_http_query_presets\\[\\]" value="core-collections"\\s+/>` ) );
		// The setting is in charge, so no override notice.
		expect( page ).not.toContain( 'overrides this setting' );
		expect( page ).toContain( 'https://github.com/moonmeister/wp-http-query/blob/main/plugin/README.md' );

		const rows = statusRows( page );
		expect( rows[ FIXTURE_OPTED_IN_ROUTE ] ).toBe( 'wp_http_query_routes filter' );
		expect( rows[ '/wp/v2/posts/(?P<id>[\\d]+)' ] ).toBe( 'wp_http_query_routes filter' );
		expect( rows[ SELF_REGISTERED_ROUTE ] ).toBe( 'Registered by the route itself (methods include QUERY)' );
		// The test fixture makes the echo route public.
		expect( rows[ ECHO ] ).toBe( 'Demo route: public' );
		expect( rows ).not.toHaveProperty( PRESET_ROUTE );
	} );

	it( 'says the echo route is admins only when the filter is left at its default', async () => {
		setOption( site, FIXTURE_DEMO_ROUTE_OFF, 1 );
		try {
			expect( statusRows( await admin.html( SETTINGS_PAGE ) )[ ECHO ] ).toBe( 'Demo route: admins only (used by the self-test)' );
		} finally {
			deleteOption( site, FIXTURE_DEMO_ROUTE_OFF );
		}
	} );

	it( 'links to the page from the Plugins screen', async () => {
		const plugins = await admin.html( '/wp-admin/plugins.php' );
		const row = plugins.match( /<tr[^>]*data-plugin="wp-http-query\/wp-http-query\.php"[\s\S]*?<\/tr>/ )?.[ 0 ] ?? '';
		expect( row ).toMatch(
			new RegExp( `<a href="${ site.url }/wp-admin/options-general\\.php\\?page=wp-http-query">Settings</a>` )
		);
	} );

	it( 'saves the ticked box through options.php and turns the preset on', async () => {
		const res = await admin.savePreset( true );
		expect( res.status ).toBe( 302 );
		expect( res.headers.get( 'location' ) ).toContain( 'page=wp-http-query' );
		expect( res.headers.get( 'location' ) ).toContain( 'settings-updated=true' );

		expect( getOption( site, PRESETS_OPTION ) ).toEqual( [ 'core-collections' ] );
		expect( await query( site, PRESET_ROUTE ) ).toBe( 200 );

		const page = await admin.html( SETTINGS_PAGE );
		expect( page ).toMatch( new RegExp( `${ CHECKBOX } name="wp_http_query_presets\\[\\]" value="core-collections"\\s+checked='checked'` ) );
		const rows = statusRows( page );
		expect( rows[ PRESET_ROUTE ] ).toBe( 'Preset: core-collections' );
		expect( rows[ FIXTURE_OPTED_IN_ROUTE ] ).toBe( 'wp_http_query_routes filter' );
	} );

	it( 'saves the unticked box and turns the preset off again', async () => {
		const res = await admin.savePreset( false );
		expect( res.status ).toBe( 302 );

		expect( getOption( site, PRESETS_OPTION ) ).toEqual( [] );
		expect( await query( site, PRESET_ROUTE ) ).toBe( 404 );
		expect( statusRows( await admin.html( SETTINGS_PAGE ) ) ).not.toHaveProperty( PRESET_ROUTE );
	} );

	it( 'says so when the wp_http_query_presets filter overrides the setting', async () => {
		deleteOption( site, FIXTURE_PRESET_FILTER_OFF ); // The fixture's filter enables the preset.

		const page = await admin.html( SETTINGS_PAGE );
		expect( page ).toContain( 'Code on this site overrides this setting through the wp_http_query_presets filter.' );
		expect( statusRows( page )[ PRESET_ROUTE ] ).toBe( 'Preset: core-collections' );
	} );
} );
