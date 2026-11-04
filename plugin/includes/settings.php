<?php
/**
 * Settings → HTTP QUERY: the preset field, the status table, the Plugins screen link, and the self-test's button and script.
 *
 * @package WPHttpQuery
 */

declare( strict_types = 1 );

namespace WPHttpQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -------------------------------------------------------------------------
 * Settings → HTTP QUERY.
 *
 * Only presets get UI. Route lists and QUERYABLE stay code-only. One option,
 * a list of enabled preset names, default empty, registered in the plugin's
 * own option group and saved through options.php. uninstall.php deletes it.
 *
 * The page also shows, read-only, which routes accept QUERY and why. That
 * comes from opted_in_route_sources() and the REST server's own route table,
 * the same two things that decide reach and Accept-Query, so it can't drift.
 *
 * The checkbox was on Settings → General in development builds. The option
 * name is unchanged, so a stored setting carries over.
 * ----------------------------------------------------------------------
 */

/** Settings page slug, and the option group and page the Settings API uses. */
const SETTINGS_PAGE = 'wp-http-query';

/** Where the settings page's docs link points. */
const DOCS_URL = 'https://github.com/moonmeister/wp-http-query/blob/main/plugin/README.md';

add_action(
	'init',
	static function (): void {
		register_setting(
			SETTINGS_PAGE,
			PRESETS_OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'HTTP QUERY presets enabled on this site.', 'wp-http-query' ),
				'sanitize_callback' => __NAMESPACE__ . '\\sanitize_presets',
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}
);

add_action(
	'admin_menu',
	static function (): void {
		add_options_page(
			/* translators: Settings page title. QUERY is the HTTP method's name; keep it as is. */
			__( 'HTTP QUERY', 'wp-http-query' ),
			/* translators: Settings menu label. QUERY is the HTTP method's name; keep it as is. */
			__( 'HTTP QUERY', 'wp-http-query' ),
			'manage_options',
			SETTINGS_PAGE,
			__NAMESPACE__ . '\\render_settings_page'
		);
	}
);

add_action(
	'admin_init',
	static function (): void {
		add_settings_section(
			'wp_http_query_presets',
			__( 'Presets', 'wp-http-query' ),
			'__return_false',
			SETTINGS_PAGE
		);

		add_settings_field(
			PRESETS_OPTION,
			__( 'Core collections', 'wp-http-query' ),
			__NAMESPACE__ . '\\render_presets_field',
			SETTINGS_PAGE,
			'wp_http_query_presets',
			array( 'label_for' => PRESETS_OPTION . '-core-collections' )
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( PLUGIN_FILE ),
	static function ( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . SETTINGS_PAGE ) ),
				esc_html__( 'Settings', 'wp-http-query' )
			)
		);

		return $links;
	}
);

/**
 * Renders Settings → HTTP QUERY.
 *
 * Saved notices come from options-head.php, which core includes on every
 * Settings submenu page, so this doesn't call settings_errors() itself.
 */
function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p>
			<?php esc_html_e( 'HTTP QUERY is a safe, repeatable request with a body. QUERY is off on existing routes until a preset, code, or the route itself turns it on.', 'wp-http-query' ); ?>
			<a href="<?php echo esc_url( DOCS_URL ); ?>"><?php esc_html_e( 'Documentation', 'wp-http-query' ); ?></a>
		</p>
		<form action="options.php" method="post">
			<?php
			settings_fields( SETTINGS_PAGE );
			do_settings_sections( SETTINGS_PAGE );
			submit_button();
			?>
		</form>
		<?php
		render_self_test();
		render_status();
		?>
	</div>
	<?php
}

/**
 * Renders the preset checkbox.
 */
function render_presets_field(): void {
	$stored    = sanitize_presets( get_option( PRESETS_OPTION, array() ) );
	$effective = enabled_presets();
	$id        = PRESETS_OPTION . '-core-collections';

	printf(
		'<input type="hidden" name="%1$s[]" value="" /><label for="%2$s"><input type="checkbox" id="%2$s" name="%1$s[]" value="core-collections" %3$s /> %4$s</label>',
		esc_attr( PRESETS_OPTION ),
		esc_attr( $id ),
		checked( in_array( 'core-collections', $stored, true ), true, false ),
		esc_html__( 'Accept QUERY on core’s read collections (posts, pages, media, comments, categories, tags, users, search)', 'wp-http-query' )
	);

	if ( in_array( 'core-collections', $stored, true ) !== in_array( 'core-collections', $effective, true ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Code on this site overrides this setting through the wp_http_query_presets filter.', 'wp-http-query' )
		);
	}
}

/**
 * Routes that accept QUERY right now, each with why.
 *
 * Reads the REST server's normalized route table, the same one that matches
 * requests and drives Accept-Query, so it lists what actually answers QUERY.
 * Anything there that opted_in_route_sources() doesn't account for registered
 * QUERY itself. The plugin's own echo route is 'demo:public' or 'demo:admins',
 * from demo_route_is_public(), the same check its permission_callback makes.
 *
 * The self-test's fingerprint route is 'self-test'.
 *
 * @return array<string, string> Route key => 'preset:<name>', 'filter', 'route', 'demo:public', 'demo:admins' or 'self-test'.
 */
function query_route_sources(): array {
	$opted_in = opted_in_route_sources();
	$sources  = array();

	foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
		foreach ( $handlers as $handler ) {
			if ( ! empty( $handler['methods'][ METHOD ] ) ) {
				if ( isset( $opted_in[ $route ] ) ) {
					$sources[ $route ] = $opted_in[ $route ];
				} elseif ( ECHO_ROUTE === $route ) {
					$sources[ $route ] = demo_route_is_public() ? 'demo:public' : 'demo:admins';
				} elseif ( FINGERPRINT_ROUTE === $route ) {
					$sources[ $route ] = 'self-test';
				} else {
					$sources[ $route ] = 'route';
				}
				break;
			}
		}
	}

	ksort( $sources );

	return $sources;
}

/**
 * Renders the read-only status table.
 */
function render_status(): void {
	$sources = query_route_sources();

	printf( '<h2>%s</h2>', esc_html__( 'Routes that accept QUERY', 'wp-http-query' ) );

	if ( ! $sources ) {
		printf( '<p>%s</p>', esc_html__( 'None. No route on this site accepts QUERY.', 'wp-http-query' ) );
		return;
	}

	echo '<table class="widefat striped" id="wp-http-query-status"><thead><tr>';
	printf( '<th scope="col">%s</th>', esc_html__( 'Route', 'wp-http-query' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Why', 'wp-http-query' ) );
	echo '</tr></thead><tbody>';

	foreach ( $sources as $route => $source ) {
		if ( 'filter' === $source ) {
			/* translators: wp_http_query_routes is a filter name; keep it as is. */
			$why = __( 'wp_http_query_routes filter', 'wp-http-query' );
		} elseif ( 'route' === $source ) {
			$why = __( 'Registered by the route itself (methods include QUERY)', 'wp-http-query' );
		} elseif ( 'demo:public' === $source ) {
			$why = __( 'Demo route: public', 'wp-http-query' );
		} elseif ( 'demo:admins' === $source ) {
			$why = __( 'Demo route: admins only (used by the self-test)', 'wp-http-query' );
		} elseif ( 'self-test' === $source ) {
			$why = __( 'Self-test cache probe: public, answers only with a hash of the body', 'wp-http-query' );
		} else {
			$why = sprintf(
				/* translators: %s: Preset name, such as core-collections. */
				__( 'Preset: %s', 'wp-http-query' ),
				substr( $source, strlen( 'preset:' ) )
			);
		}

		printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $route ), esc_html( $why ) );
	}

	echo '</tbody></table>';
}

/**
 * Settings → HTTP QUERY's hook suffix, for loading the script there only.
 */
function settings_hook_suffix(): string {
	return 'settings_page_' . SETTINGS_PAGE;
}

add_action(
	'admin_enqueue_scripts',
	static function ( string $hook_suffix ): void {
		if ( settings_hook_suffix() !== $hook_suffix ) {
			return;
		}

		$path = dirname( PLUGIN_FILE ) . '/assets/self-test.js';

		wp_enqueue_script(
			'wp-http-query-self-test',
			plugins_url( 'assets/self-test.js', PLUGIN_FILE ),
			array(),
			(string) filemtime( $path ),
			array( 'in_footer' => true )
		);

		wp_localize_script( 'wp-http-query-self-test', 'wpHttpQuerySelfTest', self_test_script_data() );
	}
);

/**
 * URLs, nonce and translated strings for the self-test script.
 *
 * @return array
 */
function self_test_script_data(): array {
	return array(
		'echoUrl'        => rest_url( NAMESPACE_ . '/echo' ),
		'fingerprintUrl' => rest_url( NAMESPACE_ . '/self-test/fingerprint' ),
		'serverUrl'      => rest_url( NAMESPACE_ . '/self-test/server' ),
		'cacheUrl'       => rest_url( NAMESPACE_ . '/self-test/cache' ),
		'diagnoseUrl'    => rest_url( NAMESPACE_ . '/self-test/diagnose' ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
		'markerKey'      => SELF_TEST_MARKER_KEY,
		'i18n'           => array(
			'checks'              => array(
				'browserEcho'  => __( 'From your browser: QUERY reaches WordPress', 'wp-http-query' ),
				'browserCache' => __( 'From your browser: caches', 'wp-http-query' ),
				'serverEcho'   => __( 'From the server (loopback): QUERY reaches WordPress', 'wp-http-query' ),
				'serverCache'  => __( 'From the server (loopback): caches', 'wp-http-query' ),
			),
			'status'              => array(
				'good'        => __( 'passed', 'wp-http-query' ),
				'recommended' => __( 'needs a look', 'wp-http-query' ),
				'critical'    => __( 'failed', 'wp-http-query' ),
			),
			/* translators: %s: HTTP status code(s). */
			'httpStatus'          => __( 'HTTP status %s.', 'wp-http-query' ),
			/* translators: %s: URL. */
			'urlTested'           => __( 'URL tested: %s', 'wp-http-query' ),
			'cacheHeaders'        => __( 'Cache-related response headers seen (hints, not proof):', 'wp-http-query' ),
			'running'             => __( 'Testing…', 'wp-http-query' ),
			/* translators: %s: Error detail, such as an HTTP status code. */
			'testFailed'          => __( 'The test itself failed (%s). Reload the page and try again.', 'wp-http-query' ),
			'browserUnsupported'  => __( 'Your browser can’t send QUERY yet, so this check couldn’t run. That’s the browser, not your server: the server checks still apply.', 'wp-http-query' ),
			'browserNetworkError' => __( 'The request didn’t complete: the browser reported a network error before any response arrived. A browser extension, proxy or firewall may have blocked it.', 'wp-http-query' ),
			'browserRedirect'     => __( 'The server answered with a redirect. A client that follows it may switch QUERY to GET and drop the body. Check that the Site Address in Settings → General matches the address the site is served from.', 'wp-http-query' ),
			'limits'              => self_test_limits(),
		),
	);
}

/**
 * Renders the "Test your server" section of Settings → HTTP QUERY.
 */
function render_self_test(): void {
	printf( '<h2>%s</h2>', esc_html__( 'Test your server', 'wp-http-query' ) );
	printf(
		'<p>%s <a href="%s">%s</a></p>',
		esc_html__( 'Checks that QUERY requests reach WordPress intact, from this browser and from the server itself, and whether a cache on the way stores QUERY responses. It sends a few small requests to this site and changes nothing.', 'wp-http-query' ),
		esc_url( admin_url( 'site-health.php' ) ),
		esc_html__( 'Site Health runs the server checks too.', 'wp-http-query' )
	);
	printf( '<p><button type="button" class="button" id="wp-http-query-self-test">%s</button></p>', esc_html__( 'Test your server', 'wp-http-query' ) );
	echo '<div id="wp-http-query-self-test-results" aria-live="polite"></div>';
}
