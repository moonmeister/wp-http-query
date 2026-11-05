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
 * ----------------------------------------------------------------------
 */

/** Settings page slug, and the option group and page the Settings API uses. */
const SETTINGS_PAGE = 'wp-http-query';

/**
 * Settings → HTTP QUERY's URL.
 */
function settings_page_url(): string {
	return admin_url( 'options-general.php?page=' . SETTINGS_PAGE );
}

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

		foreach ( presets() as $name => $preset ) {
			add_settings_field(
				PRESETS_OPTION . '-' . $name,
				esc_html( $preset['label'] ),
				__NAMESPACE__ . '\\render_preset_field',
				SETTINGS_PAGE,
				'wp_http_query_presets',
				array(
					'label_for' => PRESETS_OPTION . '-' . $name,
					'preset'    => $name,
				)
			);
		}
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
				esc_url( settings_page_url() ),
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
			// So a form with every preset box unticked still posts the field.
			printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( PRESETS_OPTION ) );
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
 * Renders one preset's checkbox, and says so when code overrides it.
 *
 * @param array $args The add_settings_field() arguments: 'label_for' and 'preset'.
 */
function render_preset_field( array $args ): void {
	$name      = $args['preset'];
	$stored    = in_array( $name, sanitize_presets( get_option( PRESETS_OPTION, array() ) ), true );
	$effective = in_array( $name, enabled_presets(), true );

	printf(
		'<label for="%2$s"><input type="checkbox" id="%2$s" name="%1$s[]" value="%3$s" %4$s /> %5$s</label>',
		esc_attr( PRESETS_OPTION ),
		esc_attr( $args['label_for'] ),
		esc_attr( $name ),
		checked( $stored, true, false ),
		esc_html( presets()[ $name ]['description'] )
	);

	if ( $stored !== $effective ) {
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
 * QUERY itself: 'route', or for the plugin's own routes, 'demo-public' or
 * 'demo-admins' (the echo route, from demo_route_is_public(), the same check
 * its permission_callback makes) and 'self-test' (the fingerprint route).
 *
 * @return array<string, array{type: string, preset?: string}> Route key => source, as route_source_label() reads it.
 */
function query_route_sources(): array {
	$opted_in = opted_in_route_sources();
	$sources  = array();

	foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
		if ( ! accepts_query( $handlers ) ) {
			continue;
		}

		if ( isset( $opted_in[ $route ] ) ) {
			$sources[ $route ] = $opted_in[ $route ];
		} elseif ( ECHO_ROUTE === $route ) {
			$sources[ $route ] = array( 'type' => demo_route_is_public() ? 'demo-public' : 'demo-admins' );
		} elseif ( FINGERPRINT_ROUTE === $route ) {
			$sources[ $route ] = array( 'type' => 'self-test' );
		} else {
			$sources[ $route ] = array( 'type' => 'route' );
		}
	}

	ksort( $sources );

	return $sources;
}

/**
 * The status table's "Why" for a source from query_route_sources().
 *
 * An unknown type is shown as is rather than guessed at.
 *
 * @param array $source array( 'type' => ..., 'preset' => ... ).
 */
function route_source_label( array $source ): string {
	$type = (string) ( $source['type'] ?? '' );

	switch ( $type ) {
		case 'preset':
			return sprintf(
				/* translators: %s: Preset name, such as core-collections. */
				__( 'Preset: %s', 'wp-http-query' ),
				(string) ( $source['preset'] ?? '' )
			);
		case 'filter':
			/* translators: wp_http_query_routes is a filter name; keep it as is. */
			return __( 'wp_http_query_routes filter', 'wp-http-query' );
		case 'route':
			return __( 'Registered by the route itself (methods include QUERY)', 'wp-http-query' );
		case 'demo-public':
			return __( 'Demo route: public', 'wp-http-query' );
		case 'demo-admins':
			return __( 'Demo route: admins only (used by the self-test)', 'wp-http-query' );
		case 'self-test':
			return __( 'Self-test cache probe: public, answers only with a hash of the body', 'wp-http-query' );
	}

	return $type;
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
		printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $route ), esc_html( route_source_label( $source ) ) );
	}

	echo '</tbody></table>';
}

add_action(
	'admin_enqueue_scripts',
	static function ( string $hook_suffix ): void {
		// Settings → HTTP QUERY only.
		if ( 'settings_page_' . SETTINGS_PAGE !== $hook_suffix ) {
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
		'echoUrl'        => rest_url( ECHO_ROUTE ),
		'fingerprintUrl' => rest_url( FINGERPRINT_ROUTE ),
		'serverUrl'      => rest_url( SERVER_ROUTE ),
		'cacheUrl'       => rest_url( CACHE_ROUTE ),
		'corsUrl'        => rest_url( CORS_ROUTE ),
		'corsOrigin'     => CORS_TEST_ORIGIN,
		'diagnoseUrl'    => rest_url( DIAGNOSE_ROUTE ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
		'markerKey'      => SELF_TEST_MARKER_KEY,
		'statuses'       => verdict_statuses(),
		'i18n'           => array(
			'groups'       => array(
				'browser' => array(
					'title'       => __( 'From your browser', 'wp-http-query' ),
					'description' => __( 'Goes through everything in front of the site (CDN, proxy, firewall), the way visitors’ requests do.', 'wp-http-query' ),
				),
				'server'  => array(
					'title'       => __( 'From your server', 'wp-http-query' ),
					'description' => __( 'WordPress calls itself (a loopback). Tests the web server and PHP; usually skips the CDN, but catches page-cache plugins.', 'wp-http-query' ),
				),
			),
			'checks'       => array(
				'browserEcho'  => __( 'QUERY reaches WordPress from your browser', 'wp-http-query' ),
				'browserCache' => __( 'No cache between your browser and WordPress stores QUERY responses', 'wp-http-query' ),
				'serverEcho'   => __( 'QUERY reaches WordPress from the server itself', 'wp-http-query' ),
				'serverCache'  => __( 'No cache on the server’s own path stores QUERY responses', 'wp-http-query' ),
				'serverCors'   => __( 'Pages on other sites may send QUERY (CORS)', 'wp-http-query' ),
			),
			'descriptions' => array(
				'echo'  => __( 'Sends one QUERY with a JSON body, and checks that WordPress gets the method and the body intact.', 'wp-http-query' ),
				'cache' => __( 'Sends two anonymous QUERYs with different bodies to the same URL, and checks that the answers differ.', 'wp-http-query' ),
				'cors'  => __( 'Sends the preflight a browser sends before a QUERY from another site, and checks that the methods the answer allows include QUERY.', 'wp-http-query' ),
			),
			'sent'         => array(
				/* translators: %s: URL path. */
				'browserEcho'  => __( 'Your browser sent: QUERY %s, signed in as you', 'wp-http-query' ),
				/* translators: %s: URL path. */
				'browserCache' => __( 'Your browser sent: 2 × QUERY %s, anonymous', 'wp-http-query' ),
				'serverEcho'   => array(
					/* translators: %s: URL path. */
					'signedIn'  => __( 'The server sent itself: QUERY %s, signed in as you', 'wp-http-query' ),
					/* translators: %s: URL path. */
					'anonymous' => __( 'The server sent itself: QUERY %s, anonymous', 'wp-http-query' ),
				),
				/* translators: %s: URL path. */
				'serverCache'  => __( 'The server sent itself: 2 × QUERY %s, anonymous', 'wp-http-query' ),
				/* translators: 1: URL path. 2: An origin, such as https://example.com. */
				'serverCors'   => __( 'The server sent itself: OPTIONS %1$s, Origin: %2$s, Access-Control-Request-Method: QUERY, anonymous', 'wp-http-query' ),
			),
			'summary'      => self_test_summary_strings( 2 + count( server_checks() ) ), // The two browser checks, and the server's.
			/* translators: 1: A check's title. 2: Its result, such as "passed". */
			'titleStatus'  => __( '%1$s: %2$s', 'wp-http-query' ),
			'status'       => array(
				'good'        => __( 'passed', 'wp-http-query' ),
				'recommended' => __( 'needs a look', 'wp-http-query' ),
				'critical'    => __( 'failed', 'wp-http-query' ),
			),
			/* translators: %s: HTTP status code(s). */
			'httpStatus'   => __( 'HTTP status %s.', 'wp-http-query' ),
			'cacheHeaders' => __( 'Cache-related response headers seen (hints, not proof):', 'wp-http-query' ),
			'running'      => __( 'Testing…', 'wp-http-query' ),
			/* translators: %s: Error detail, such as an HTTP status code. */
			'testFailed'   => __( 'The test itself failed (%s). Reload the page and try again.', 'wp-http-query' ),
			'limits'       => self_test_limits(),
		),
	);
}

/**
 * The self-test's summary line, for every outcome.
 *
 * @param int $checks How many checks run.
 * @return array{passed: string, problems: array<int, string>} 'problems' is indexed by how many checks didn't pass.
 */
function self_test_summary_strings( int $checks ): array {
	$problems = array();
	for ( $n = 1; $n <= $checks; $n++ ) {
		/* translators: %d: Number of checks that didn't pass. */
		$problems[ $n ] = sprintf( _n( '%d problem found:', '%d problems found:', $n, 'wp-http-query' ), $n );
	}

	return array(
		/* translators: %d: Number of checks, more than one. */
		'passed'   => sprintf( __( 'All %d checks passed.', 'wp-http-query' ), $checks ),
		'problems' => $problems,
	);
}

/**
 * Renders the "Test your server" section of Settings → HTTP QUERY.
 */
function render_self_test(): void {
	printf( '<h2>%s</h2>', esc_html__( 'Test your server', 'wp-http-query' ) );
	printf(
		'<p>%s <a href="%s">%s</a></p>',
		esc_html__( 'Checks that QUERY requests reach WordPress intact, from this browser and from the server itself, whether a cache on the way stores QUERY responses, and whether CORS lets pages on other sites send QUERY. It sends a few small requests to this site and changes nothing.', 'wp-http-query' ),
		esc_url( admin_url( 'site-health.php' ) ),
		esc_html__( 'Site Health runs the server checks too.', 'wp-http-query' )
	);
	printf( '<p><button type="button" class="button" id="wp-http-query-self-test">%s</button></p>', esc_html__( 'Test your server', 'wp-http-query' ) );
	echo '<div id="wp-http-query-self-test-results" aria-live="polite"></div>';
}
