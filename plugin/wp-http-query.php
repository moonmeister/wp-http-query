<?php
/**
 * Plugin Name:       WP HTTP QUERY
 * Plugin URI:        https://github.com/moonmeister/wp-http-query
 * Description:       HTTP QUERY (RFC 10008) support for the WordPress REST API, entirely in userland. No core patch required.
 * Version:           0.0.0-dev
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Author:            Alex Moon
 * Author URI:        https://moonmeister.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-http-query
 *
 * The project's deliverable: QUERY support shipped as a feature plugin on stock
 * core, ahead of any core patch. Each workaround is annotated with the core gap
 * it stands in for, so the plugin doubles as the spec for the eventual patch.
 *
 * See ../docs/scope.md §2.
 *
 * @package WPHttpQuery
 */

declare( strict_types = 1 );

namespace WPHttpQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const METHOD     = 'QUERY';
const NAMESPACE_ = 'wp-http-query/v1';

/**
 * Media types advertised in Accept-Query.
 *
 * Advertising is separate from parsing. ADR 0001 (option B) settled what is
 * *parsed*: JSON and form-encoded, matching core's other body-bearing
 * methods, and both keep working whatever this returns. What is *advertised*
 * is JSON only: accepting form-encoded is a bug fix, but advertising it
 * endorses a poor structured-query format (ADR 0001 open question 2, #20).
 *
 * This is the current default, not a final decision. Revisit it if real use
 * shows a need. Sites can change it with the filter.
 */
function advertised_query_types(): array {
	return (array) apply_filters(
		'wp_http_query_advertised_types',
		array( 'application/json' )
	);
}

/*
 * -------------------------------------------------------------------------
 * Gap 1 — Access-Control-Allow-Methods is a hardcoded string in core.
 *
 * rest_send_cors_headers() emits the list with a raw header() call and no hook
 * at that point, so a cross-origin QUERY preflight fails.
 *
 * Trac#46992 added a 'rest_allowed_cors_methods' filter in 7.2 (r64730).
 * Where that exists, one add_filter does the whole job. Where it does
 * not, core's callback has to be unhooked and replaced wholesale — which this
 * plugin will keep needing for as long as it supports cores that predate the
 * filter, so the fallback is permanent rather than temporary.
 * ----------------------------------------------------------------------
 */

/**
 * Whether core's CORS method list is filterable.
 *
 * Feature detection, not version sniffing: the $result, $request and $server
 * parameters arrived in the same change as the filter (r64730), and an alpha build of the release
 * carrying it would fail a version comparison against '7.2'.
 */
function core_cors_is_filterable(): bool {
	static $filterable = null;

	if ( null === $filterable ) {
		try {
			$filterable = ( new \ReflectionFunction( 'rest_send_cors_headers' ) )->getNumberOfParameters() >= 3;
		} catch ( \ReflectionException $e ) {
			$filterable = false;
		}
	}

	return $filterable;
}

add_action(
	'rest_api_init',
	static function (): void {
		if ( core_cors_is_filterable() ) {
			add_filter(
				'rest_allowed_cors_methods',
				static function ( array $methods ): array {
					if ( ! in_array( METHOD, $methods, true ) ) {
						$methods[] = METHOD;
					}

					return $methods;
				}
			);

			return;
		}

		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\send_cors_headers' );
	},
	20
);

/**
 * Replacement for core's rest_send_cors_headers() that includes QUERY.
 *
 * Mirrors core's behavior exactly apart from the method list.
 *
 * @param mixed $value Passthrough value.
 * @return mixed
 */
function send_cors_headers( $value ) {
	$origin = get_http_origin();

	if ( $origin ) {
		if ( 'null' !== $origin ) {
			$origin = sanitize_url( $origin );
		}
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE, QUERY' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin', false );
	} elseif ( ! headers_sent() && isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] && ! is_user_logged_in() ) {
		header( 'Vary: Origin', false );
	}

	return $value;
}

/*
 * -------------------------------------------------------------------------
 * Gap 3 — get_parameter_order() excludes QUERY from body-data parsing.
 *
 * class-wp-rest-request.php:377-380 hardcodes POST/PUT/PATCH/DELETE, so a
 * form-encoded QUERY body is parsed into params['POST'] and then never looked
 * up. JSON bodies are unaffected (parse_json_params runs unconditionally).
 *
 * Fixable from userland via the rest_request_parameter_order filter, which
 * usefully proves the one-line core fix is correct before anyone patches it.
 * ----------------------------------------------------------------------
 */

add_filter(
	'rest_request_parameter_order',
	static function ( array $order, \WP_REST_Request $request ): array {
		if ( METHOD !== $request->get_method() || in_array( 'POST', $order, true ) ) {
			return $order;
		}

		// Insert before GET, matching where core places it for other body methods.
		$index = array_search( 'GET', $order, true );
		if ( false === $index ) {
			$order[] = 'POST';
			return $order;
		}

		array_splice( $order, (int) $index, 0, array( 'POST' ) );
		return $order;
	},
	10,
	2
);

/*
 * -------------------------------------------------------------------------
 * Gap 2 — READABLE is 'GET', so no existing read route matches QUERY.
 *
 * ADR 0002 closed on an additive QUERYABLE constant, so core gives existing
 * read routes nothing. Which routes this plugin adds QUERY to is ADR 0005,
 * decided 2026-11-04: reach is OFF by default and opt-in, never every route.
 *
 * Why not every route: plugins read 'GET' as a synonym for "safe". Jetpack
 * registers READABLE routes whose callbacks do
 * `if ( 'GET' === $request->get_method() ) { read } else { write }`, so a
 * QUERY to them activates modules. See ADR 0005 and experiments/plugin-spike/.
 *
 * QUERY reaches a route in exactly three ways:
 *
 * 1. The route registers it. Put QUERYABLE (or 'QUERY') in 'methods':
 *
 *        register_rest_route( 'my/v1', '/things', array(
 *            'methods'  => WP_REST_Server::READABLE . ', ' . \WPHttpQuery\QUERYABLE,
 *            ...
 *        ) );
 *
 *    This already works on stock core; the plugin adds nothing for it.
 *
 * 2. A site lists the route in the 'wp_http_query_routes' filter.
 *
 * 3. A site enables a preset: the option set on Settings → HTTP QUERY, then
 *    the 'wp_http_query_presets' filter, which code can use to override it.
 *
 * Only handlers that accept GET gain QUERY. Write handlers on the same route
 * never do (add_query_to_handler()).
 *
 * match_request_to_handler() is a plain array-key lookup on the normalized
 * method map, and 'rest_endpoints' runs BEFORE that normalization
 * (class-wp-rest-server.php:973, normalizer at :1007). So what this filter
 * hands back is raw registration input — a bare string, a comma-separated
 * string, or an array — not the method map.
 * ----------------------------------------------------------------------
 */

/**
 * Userland stand-in for core's future WP_REST_Server::QUERYABLE (ADR 0002,
 * option B). Use it in 'methods' when registering a route that answers QUERY.
 */
const QUERYABLE = METHOD;

/** Option holding the enabled preset names. The only thing the plugin stores. */
const PRESETS_OPTION = 'wp_http_query_presets';

/**
 * Route lists, by preset name.
 *
 * 'core-collections' is core's public read collections: ADR 0005 option 2,
 * done by the plugin rather than by core. Each route's GET handler is a
 * WP_REST_Controller::get_items() that was checked for branching on the
 * request method (get_method(), is_method(), REQUEST_METHOD) in 7.0.8, 7.1.4
 * and 7.2-beta4: the only hits are is_method( 'HEAD' ), which QUERY does not
 * match, so QUERY gets the full GET response. See ADR 0005 for the record.
 *
 * Exact route keys, not patterns. Single-item routes are not included.
 *
 * @return array<string, string[]>
 */
function presets(): array {
	return array(
		'core-collections' => array(
			'/wp/v2/posts',
			'/wp/v2/pages',
			'/wp/v2/media',
			'/wp/v2/comments',
			'/wp/v2/categories',
			'/wp/v2/tags',
			'/wp/v2/users',
			'/wp/v2/search',
		),
	);
}

/**
 * Names of the enabled presets.
 *
 * The stored option, then the 'wp_http_query_presets' filter, so code can
 * enable or disable a preset whatever the setting says:
 *
 *     add_filter( 'wp_http_query_presets', function ( array $presets ) {
 *         $presets[] = 'core-collections';
 *         return $presets;
 *     } );
 *
 * Unknown names are ignored, with a _doing_it_wrong() notice.
 *
 * @return string[]
 */
function enabled_presets(): array {
	$names = (array) apply_filters( 'wp_http_query_presets', sanitize_presets( get_option( PRESETS_OPTION, array() ) ) );
	$known = array();

	foreach ( $names as $name ) {
		if ( is_string( $name ) && isset( presets()[ $name ] ) ) {
			$known[] = $name;
		} else {
			_doing_it_wrong(
				'wp_http_query_presets',
				sprintf(
					/* translators: 1: The preset name that was passed. 2: Comma-separated list of known preset names. */
					esc_html__( 'Unknown preset "%1$s". Known presets: %2$s.', 'wp-http-query' ),
					esc_html( is_scalar( $name ) ? (string) $name : gettype( $name ) ),
					esc_html( implode( ', ', array_keys( presets() ) ) )
				),
				'0.1.0'
			);
		}
	}

	return array_values( array_unique( $known ) );
}

/**
 * Route keys that gain QUERY on their GET handlers.
 *
 * Starts from the enabled presets' routes and passes them through the
 * 'wp_http_query_routes' filter. Add to the list rather than replacing it,
 * or the presets are dropped:
 *
 *     add_filter( 'wp_http_query_routes', function ( array $routes ) {
 *         $routes[] = '/my-plugin/v1/things';
 *         $routes[] = '/wp/v2/posts/(?P<id>[\d]+)'; // The registered regex, verbatim.
 *         return $routes;
 *     } );
 *
 * Entries match registered route keys exactly: no patterns, no prefixes.
 * The keys are listed at GET /wp-json/.
 *
 * @return string[]
 */
function opted_in_routes(): array {
	return array_keys( opted_in_route_sources() );
}

/**
 * Opted-in route keys, each mapped to why it is opted in.
 *
 * The one place reach is decided: opted_in_routes() is its keys, and the
 * settings page's status table reads the values. A route a preset lists is
 * 'preset:<name>' (the first enabled preset that lists it); anything the
 * 'wp_http_query_routes' filter added is 'filter'. A preset route the filter
 * removed is not opted in at all.
 *
 * @return array<string, string> Route key => 'preset:<name>' or 'filter'.
 */
function opted_in_route_sources(): array {
	$from_presets = array();
	foreach ( enabled_presets() as $name ) {
		foreach ( presets()[ $name ] as $route ) {
			if ( ! isset( $from_presets[ $route ] ) ) {
				$from_presets[ $route ] = 'preset:' . $name;
			}
		}
	}

	$routes  = array_filter(
		(array) apply_filters( 'wp_http_query_routes', array_keys( $from_presets ) ),
		'is_string'
	);
	$sources = array();

	foreach ( $routes as $route ) {
		if ( ! isset( $sources[ $route ] ) ) {
			$sources[ $route ] = $from_presets[ $route ] ?? 'filter';
		}
	}

	return $sources;
}

add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		foreach ( opted_in_routes() as $route ) {
			if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
				continue;
			}

			$handlers = $endpoints[ $route ];

			// Core wraps the single-handler shape after this filter, not before.
			if ( isset( $handlers['callback'] ) ) {
				$endpoints[ $route ] = add_query_to_handler( $handlers );
				continue;
			}

			foreach ( $handlers as $key => $handler ) {
				// Non-numeric keys are route options ('schema', ...), which core
				// moves out during normalization. Leave them alone.
				if ( ! is_numeric( $key ) || ! is_array( $handler ) ) {
					continue;
				}

				$endpoints[ $route ][ $key ] = add_query_to_handler( $handler );
			}
		}

		return $endpoints;
	}
);

/**
 * Adds QUERY alongside GET on a single route handler.
 *
 * Returns the handler untouched unless it accepts GET and does not already
 * accept QUERY.
 *
 * @param array $handler Raw, pre-normalization route handler.
 * @return array
 */
function add_query_to_handler( array $handler ): array {
	if ( ! isset( $handler['methods'] ) ) {
		return $handler;
	}

	$entries = is_array( $handler['methods'] ) ? $handler['methods'] : array( $handler['methods'] );
	$names   = array();

	foreach ( $entries as $entry ) {
		if ( ! is_string( $entry ) ) {
			return $handler;
		}

		foreach ( explode( ',', $entry ) as $name ) {
			$names[] = strtoupper( trim( $name ) );
		}
	}

	if ( ! in_array( 'GET', $names, true ) || in_array( METHOD, $names, true ) ) {
		return $handler;
	}

	$names[] = METHOD;

	/*
	 * Hand back a STRING, never an array. get_routes() comma-splits the string
	 * form but not the array form, so an array entry carrying a multi-method
	 * constant registers one unmatchable key: Trac#65905, fixed in 7.2 (r63756).
	 * Writing the string form means this plugin cannot trip that bug on any
	 * core version, including those before 7.2.
	 *
	 * It also incidentally repairs the routes it touches: flattening
	 * array( 'GET', 'POST, PUT, PATCH' ) to a string makes POST/PUT/PATCH
	 * matchable again. Routes without GET are not touched and stay broken.
	 */
	$handler['methods'] = implode( ', ', $names );

	return $handler;
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

/**
 * Keeps known preset names only.
 *
 * Also the option's sanitize_callback, so it gets whatever was posted to
 * options.php: anything that isn't a list of known names reduces to the
 * known names in it, or to an empty list. Non-string entries are dropped
 * before comparing, so a nested array can't raise an array-to-string notice.
 *
 * @param mixed $value Raw option value.
 * @return string[]
 */
function sanitize_presets( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	return array_values( array_intersect( array_keys( presets() ), array_filter( $value, 'is_string' ) ) );
}

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
	'plugin_action_links_' . plugin_basename( __FILE__ ),
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

/*
 * -------------------------------------------------------------------------
 * Accept-Query advertisement (RFC 10008 §3).
 *
 * Structured Fields List syntax — NOT the legacy Accept grammar, despite the
 * surface similarity. Emitted alongside core's generic Allow header logic.
 * ----------------------------------------------------------------------
 */

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		$route = $response->get_matched_route();
		if ( ! $route ) {
			return $response;
		}

		$routes = $server->get_routes();
		foreach ( $routes[ $route ] ?? array() as $handler ) {
			if ( ! empty( $handler['methods'][ METHOD ] ) ) {
				$list = implode(
					', ',
					array_map(
						static fn ( string $type ): string => '"' . $type . '"',
						advertised_query_types()
					)
				);
				$response->header( 'Accept-Query', $list );
				break;
			}
		}

		return $response;
	},
	11,
	2
);

/*
 * -------------------------------------------------------------------------
 * Cache safety — deliberately nothing. ADR 0003, option C.
 *
 * An earlier version of this file sent Cache-Control: private, no-cache on
 * every QUERY response, on the theory that a body-blind cache would collide
 * two different request bodies on one URI-keyed entry. That threat model was
 * falsified: no shipping cache stores a QUERY response at all. Varnish
 * synth(501)s it before the origin sees it, nginx's cache-method bitmask holds
 * only GET/HEAD/POST and cannot be configured otherwise, mod_cache, Squid,
 * Fastly and Cloudflare all allowlist, and RFC 9111 §3 forbids storing a
 * response whose request method the cache has not understood.
 *
 * Emitting a header against a risk that does not exist would teach operators
 * that QUERY is uncacheable by nature. See experiments/cache-survey/.
 * ----------------------------------------------------------------------
 */

/*
 * -------------------------------------------------------------------------
 * Demo route: admins only unless a site makes it public.
 *
 * QUERY /wp-json/wp-http-query/v1/echo reports how the server parsed the
 * request. It is always registered, because the self-test below sends QUERY
 * to it, but by default only users with manage_options may call it: anyone
 * else gets core's standard 401 (signed out) or 403. It reflects its input,
 * so making it public is a site's choice, through the
 * 'wp_http_query_register_demo_route' filter (named for when it controlled
 * registration; the name stays so existing opt-ins keep working):
 *
 *     add_filter( 'wp_http_query_register_demo_route', '__return_true' );
 *
 * The default is false, not WP_DEBUG: WP_DEBUG is often left on for staging
 * sites that are publicly reachable, and a route that opens up with a
 * debugging constant is a surprise. Opting in is one line.
 *
 * The route is registrable on stock core with no patch: register_rest_route()
 * has no method allowlist, set_method() only uppercases, and
 * match_request_to_handler() is a plain array-key lookup. See scope.md §2.
 *
 * Also reachable via the core X-HTTP-Method-Override tunnel:
 *   POST /wp-json/wp-http-query/v1/echo  +  X-HTTP-Method-Override: QUERY
 * That is a tunnel, NOT the method — intermediaries see POST, so RFC 10008
 * cacheability and retry safety do not apply.
 * ----------------------------------------------------------------------
 */

/** The echo route's key, as the REST server lists it. */
const ECHO_ROUTE = '/' . NAMESPACE_ . '/echo';

/**
 * Whether anyone may call the echo route, not just admins.
 *
 * The one place this is decided: the route's permission_callback and the
 * settings page's status table both read it.
 */
function demo_route_is_public(): bool {
	/**
	 * Filters whether the diagnostic echo route is public.
	 *
	 * The route is always registered. When this is false, only users with
	 * manage_options may call it.
	 *
	 * @param bool $public Whether anyone may call QUERY /wp-http-query/v1/echo. Default false.
	 */
	return (bool) apply_filters( 'wp_http_query_register_demo_route', false );
}

/**
 * Who may call the echo route: anyone when it's public, otherwise admins.
 *
 * Returning false lets core send its standard rest_forbidden error, 401 for a
 * signed-out request and 403 for a signed-in one.
 */
function can_call_echo(): bool {
	return demo_route_is_public() || current_user_can( 'manage_options' );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			NAMESPACE_,
			'/echo',
			array(
				'methods'             => METHOD,
				'permission_callback' => __NAMESPACE__ . '\\can_call_echo',
				'callback'            => __NAMESPACE__ . '\\handle_echo',
			)
		);
	}
);

/**
 * Echoes back what the server actually received.
 *
 * Diagnostic, not a real endpoint — it reports how the request was parsed so
 * the plumbing can be verified end to end.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function handle_echo( \WP_REST_Request $request ): \WP_REST_Response {
	$body = $request->get_body();

	return new \WP_REST_Response(
		array(
			'method'           => $request->get_method(),
			'content_type'     => $request->get_content_type(),
			'body_bytes'       => strlen( $body ),
			'json_params'      => $request->get_json_params(),
			'body_params'      => $request->get_body_params(),
			'query_params'     => $request->get_query_params(),
			'merged_params'    => $request->get_params(),
			'advertised_types' => advertised_query_types(),
			'via_override'     => isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ),
		),
		200
	);
}

/*
 * -------------------------------------------------------------------------
 * Self-test: does QUERY work on this hosting?
 *
 * Settings → HTTP QUERY has a "Test your server" button, and Site Health has
 * two tests. Between them they ask:
 *
 * 1. Does a QUERY, with its body, reach WordPress intact? The browser sends a
 *    real fetch() with method QUERY to the echo route; the server sends the
 *    same request to itself (a loopback, like Site Health's own). Both are
 *    signed in as the current admin, because the echo route is admins only.
 *
 * 2. Does a cache on the way store QUERY responses without keying on the
 *    body? Two different bodies go to the same URL, the public fingerprint
 *    route, signed out: caches usually skip signed-in requests, so a signed-in
 *    probe would always look clean. If the second answer is the first one
 *    again, something stored it. The browser path goes through any CDN; the
 *    loopback usually doesn't, but catches page-cache plugins, such as W3
 *    Total Cache Pro with REST caching on (experiments/cache-survey/).
 *
 * What a response means is decided here, in two pure functions,
 * diagnose_echo_response() and diagnose_cache_probe(), that take a status,
 * headers and body and return a verdict and a message. The loopback, Site
 * Health and the browser (through the diagnose route) all use them, and the
 * tests feed them fabricated responses. The causes they name come from the
 * compatibility matrix (matrix/README.md, matrix/results/MATRIX.md) and the
 * cache survey, and claim no more than those do.
 * ----------------------------------------------------------------------
 */

/** The body key the echo check puts its random marker under. */
const SELF_TEST_MARKER_KEY = 'wp_http_query_self_test';

/** The fingerprint route's key, as the REST server lists it. */
const FINGERPRINT_ROUTE = '/' . NAMESPACE_ . '/self-test/fingerprint';

/** Seconds a loopback request may take. Core's loopback test uses the same. */
const LOOPBACK_TIMEOUT = 10;

/** Site Health test keys, also the 'test' value in their results. */
const HEALTH_TEST_REACH = 'wp_http_query_reaches_wordpress';
const HEALTH_TEST_CACHE = 'wp_http_query_cache';

/**
 * Who may run the self-test and read its results.
 */
function can_run_self_test(): bool {
	return current_user_can( 'manage_options' );
}

add_action(
	'rest_api_init',
	static function (): void {
		/*
		 * Public on purpose, and safe to be: it echoes nothing back, not the
		 * body, not a parameter, not a header, so it reflects no input and
		 * reveals nothing. It returns a SHA-256 of the raw body it received
		 * and a value that is new on every request, which is all the cache
		 * probe needs to tell a fresh answer from a stored one. It reads and
		 * writes nothing. And it sends no Cache-Control of its own: ADR 0003,
		 * the plugin emits nothing method-specific, and the point is to see
		 * what the layers in front of WordPress do.
		 */
		register_rest_route(
			NAMESPACE_,
			'/self-test/fingerprint',
			array(
				'methods'             => METHOD,
				'permission_callback' => '__return_true',
				'callback'            => __NAMESPACE__ . '\\handle_fingerprint',
			)
		);

		register_rest_route(
			NAMESPACE_,
			'/self-test/server',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => __NAMESPACE__ . '\\can_run_self_test',
				'callback'            => static fn (): \WP_REST_Response => rest_ensure_response( health_result( HEALTH_TEST_REACH, run_server_check() ) ),
			)
		);

		register_rest_route(
			NAMESPACE_,
			'/self-test/cache',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => __NAMESPACE__ . '\\can_run_self_test',
				'callback'            => static fn (): \WP_REST_Response => rest_ensure_response( health_result( HEALTH_TEST_CACHE, run_cache_check() ) ),
			)
		);

		// What the browser saw, in; a verdict and message, out. Pure: it
		// sends nothing and stores nothing.
		register_rest_route(
			NAMESPACE_,
			'/self-test/diagnose',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => __NAMESPACE__ . '\\can_run_self_test',
				'callback'            => __NAMESPACE__ . '\\handle_diagnose',
				'args'                => array(
					'check' => array(
						'type'     => 'string',
						'enum'     => array( 'echo', 'cache' ),
						'required' => true,
					),
				),
			)
		);
	}
);

/**
 * Answers the cache probe: a hash of the body received, and a fresh value.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function handle_fingerprint( \WP_REST_Request $request ): \WP_REST_Response {
	return new \WP_REST_Response(
		array(
			'body_sha256' => hash( 'sha256', $request->get_body() ),
			'fresh'       => wp_generate_uuid4() . '@' . sprintf( '%.6F', microtime( true ) ),
		),
		200
	);
}

/**
 * Classifies what the browser saw.
 *
 * @param \WP_REST_Request $request Request with 'check' and the observation(s).
 * @return array
 */
function handle_diagnose( \WP_REST_Request $request ): array {
	if ( 'echo' === $request->get_param( 'check' ) ) {
		$seen = observation( $request->get_param( 'response' ) );
		return diagnose_echo_response( $seen['status'], $seen['headers'], $seen['body'], (string) $request->get_param( 'marker' ) );
	}

	return diagnose_cache_probe( observation( $request->get_param( 'first' ) ), observation( $request->get_param( 'second' ) ) );
}

/**
 * Coerces a posted observation into status, headers, body, sent and error.
 *
 * @param mixed $raw Decoded JSON.
 * @return array{status: int, headers: array<string, string>, body: string, sent: string, error: string}
 */
function observation( $raw ): array {
	$raw     = is_array( $raw ) ? $raw : array();
	$headers = array();

	foreach ( (array) ( $raw['headers'] ?? array() ) as $name => $value ) {
		if ( is_string( $name ) && is_scalar( $value ) ) {
			$headers[ $name ] = (string) $value;
		}
	}

	return array(
		'status'  => (int) ( $raw['status'] ?? 0 ),
		'headers' => $headers,
		'body'    => is_string( $raw['body'] ?? null ) ? $raw['body'] : '',
		'sent'    => is_string( $raw['sent'] ?? null ) ? $raw['sent'] : '',
		'error'   => is_string( $raw['error'] ?? null ) ? $raw['error'] : '',
	);
}

/**
 * Header names lower-cased, and repeated headers joined with ", ".
 *
 * @param array $headers Header name => value or list of values.
 * @return array<string, string>
 */
function normalize_headers( array $headers ): array {
	$normalized = array();

	foreach ( $headers as $name => $value ) {
		$normalized[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
	}

	return $normalized;
}

/**
 * A WordPress REST error in a response body, if that's what it is.
 *
 * @param string $body Response body.
 * @return array{code: string, message: string}|null
 */
function rest_error_in( string $body ): ?array {
	$data = json_decode( $body, true );

	if ( is_array( $data ) && is_string( $data['code'] ?? null ) && is_string( $data['message'] ?? null ) ) {
		return array(
			'code'    => $data['code'],
			'message' => $data['message'],
		);
	}

	return null;
}

/**
 * Site Health status for each verdict.
 *
 * Critical when QUERY doesn't reach WordPress intact, or a cache serves one
 * body's answer to another; recommended when the test couldn't tell.
 *
 * @return array<string, string>
 */
function verdict_statuses(): array {
	return array(
		'pass'                => 'good',
		'reached'             => 'good',
		'clean'               => 'good',
		'no_accept_query'     => 'recommended',
		'wordpress_error'     => 'recommended',
		'network_error'       => 'recommended',
		'unexpected'          => 'recommended',
		'inconclusive'        => 'recommended',
		'altered'             => 'recommended',
		'not_allowed'         => 'critical',
		'forbidden'           => 'critical',
		'not_implemented'     => 'critical',
		'redirect'            => 'critical',
		'method_changed'      => 'critical',
		'body_lost'           => 'critical',
		'stored'              => 'critical',
		'browser_unsupported' => 'recommended',
	);
}

/**
 * Builds a verdict.
 *
 * @param string $verdict     One of verdict_statuses()' keys.
 * @param string $message     One-line explanation, translated.
 * @param int    $http_status Status code, 0 if there was no response.
 * @return array{verdict: string, status: string, http_status: int, message: string}
 */
function verdict( string $verdict, string $message, int $http_status ): array {
	return array(
		'verdict'     => $verdict,
		'status'      => verdict_statuses()[ $verdict ],
		'http_status' => $http_status,
		'message'     => $message,
	);
}

/**
 * What a QUERY to the echo route got back, in plain language.
 *
 * Pure: no I/O, no globals. The causes named come from the compatibility
 * matrix and the cache survey.
 *
 * @param int    $status        HTTP status, 0 if the request failed.
 * @param array  $headers       Response headers.
 * @param string $body          Response body.
 * @param string $marker        The random value sent under SELF_TEST_MARKER_KEY.
 * @param bool   $authenticated Whether the request was signed in. A signed-out
 *                              request to the admins-only route expects a 401.
 * @param string $error         Transport error message, if the request failed.
 * @return array{verdict: string, status: string, http_status: int, message: string}
 */
function diagnose_echo_response( int $status, array $headers, string $body, string $marker, bool $authenticated = true, string $error = '' ): array {
	$headers = normalize_headers( $headers );

	if ( '' !== $error || 0 === $status ) {
		return verdict(
			'network_error',
			sprintf(
				/* translators: %s: The error message from the HTTP request. */
				__( 'The request didn’t complete (%s). That says nothing about QUERY yet: the site couldn’t reach its own address. If Site Health’s loopback test fails too, fix that first.', 'wp-http-query' ),
				'' !== $error ? $error : __( 'no response', 'wp-http-query' )
			),
			0
		);
	}

	if ( $status >= 300 && $status < 400 ) {
		return verdict(
			'redirect',
			sprintf(
				/* translators: %s: The URL the response redirected to. */
				__( 'The request was redirected to %s. A client that follows a redirect may switch QUERY to GET and drop the body: WordPress’s own HTTP API does on a 302, browsers on a 303. Check that the Site Address in Settings → General matches the address the site is served from, including https and www.', 'wp-http-query' ),
				'' !== ( $headers['location'] ?? '' ) ? $headers['location'] : __( 'another address', 'wp-http-query' )
			),
			$status
		);
	}

	if ( 200 === $status ) {
		$echo = json_decode( $body, true );

		if ( ! is_array( $echo ) || ! isset( $echo['method'] ) ) {
			return verdict( 'unexpected', __( 'Something answered 200, but not with the test route’s reply. A cache or proxy in front of WordPress may have answered instead.', 'wp-http-query' ), $status );
		}

		if ( METHOD !== $echo['method'] ) {
			return verdict(
				'method_changed',
				sprintf(
					/* translators: %s: The HTTP method WordPress received, such as GET. */
					__( 'WordPress received the request as %s, not QUERY. Something between the client and WordPress changed the method.', 'wp-http-query' ),
					is_string( $echo['method'] ) ? $echo['method'] : '?'
				),
				$status
			);
		}

		$received = $echo['merged_params'][ SELF_TEST_MARKER_KEY ] ?? null;
		if ( $received !== $marker ) {
			$message = empty( $echo['body_bytes'] )
				? __( 'QUERY reached WordPress, but its body didn’t: WordPress saw an empty body. Something between the client and WordPress dropped it.', 'wp-http-query' )
				: __( 'QUERY reached WordPress, but its body arrived changed: the test value sent in it didn’t come back.', 'wp-http-query' );
			return verdict( 'body_lost', $message, $status );
		}

		if ( '' === ( $headers['accept-query'] ?? '' ) ) {
			return verdict( 'no_accept_query', __( 'QUERY reached WordPress intact, but the Accept-Query response header was missing. Something in front of WordPress may be removing it, so clients can’t see which body format to send.', 'wp-http-query' ), $status );
		}

		return verdict( 'pass', __( 'QUERY reached WordPress with its method and body intact, and the Accept-Query header came back.', 'wp-http-query' ), $status );
	}

	$rest_error = rest_error_in( $body );
	if ( null !== $rest_error ) {
		if ( 404 === $status && 'rest_no_route' === $rest_error['code'] ) {
			return verdict( 'method_changed', __( 'WordPress got the request but not as QUERY: the test route answers only QUERY, and WordPress found no route for the method it saw. Something between the client and WordPress may have changed the method.', 'wp-http-query' ), $status );
		}

		// The permission check runs only once a QUERY handler has matched, so
		// this 401 shows the method arrived as QUERY.
		if ( 401 === $status && ! $authenticated && 'rest_forbidden' === $rest_error['code'] ) {
			return verdict( 'reached', __( 'QUERY reached WordPress: the admins-only test route turned away the signed-out request with 401, which happens only after WordPress matched the QUERY method. The body wasn’t checked; the button on Settings → HTTP QUERY checks it.', 'wp-http-query' ), $status );
		}

		if ( 401 === $status || 403 === $status ) {
			return verdict(
				'wordpress_error',
				sprintf(
					/* translators: %s: A WordPress REST API error code, such as rest_forbidden. */
					__( 'QUERY reached WordPress, but WordPress didn’t accept the test’s sign-in (%s), so the body couldn’t be checked. Reload the page and try again.', 'wp-http-query' ),
					$rest_error['code']
				),
				$status
			);
		}

		return verdict(
			'wordpress_error',
			sprintf(
				/* translators: 1: A WordPress REST API error code. 2: Its message. */
				__( 'WordPress answered with an error (%1$s: %2$s), so the test couldn’t tell whether QUERY works.', 'wp-http-query' ),
				$rest_error['code'],
				wp_strip_all_tags( $rest_error['message'] )
			),
			$status
		);
	}

	if ( 405 === $status ) {
		return verdict( 'not_allowed', __( 'The web server refused QUERY before WordPress saw it. nginx does this when the request goes to the site root (/ or /?rest_route=…) instead of /index.php or /wp-json/: its index module answers 405 first. Check the URL tested; with plain permalinks, send QUERY to /index.php?rest_route=… instead.', 'wp-http-query' ), $status );
	}

	if ( 403 === $status ) {
		return verdict( 'forbidden', __( 'A server rule or firewall refused QUERY before WordPress saw it. Rules that allow only GET and POST do this, such as nginx’s limit_except GET POST or Apache’s <LimitExcept GET POST>. Apache can add QUERY to that list; nginx can’t name QUERY there, so ask your host.', 'wp-http-query' ), $status );
	}

	if ( 501 === $status ) {
		$message = __( 'Something in front of WordPress doesn’t implement QUERY. Varnish 8.0 to 9.0.x answers QUERY with 501 itself (9.1.0 and later pass it through; for older versions, add if (req.method == "QUERY") { return (pass); } to vcl_req_method), and so does PHP’s built-in server (php -S).', 'wp-http-query' );
		if ( in_array( 'Varnish', array_column( cache_header_hints( $headers ), 'hint' ), true ) ) {
			$message .= ' ' . __( 'This response has Varnish headers.', 'wp-http-query' );
		}
		return verdict( 'not_implemented', $message, $status );
	}

	return verdict( 'unexpected', __( 'Unexpected response, so the test couldn’t tell whether QUERY works.', 'wp-http-query' ), $status );
}

/**
 * What a response header suggests about the cache or proxy that sent it.
 *
 * The one place headers map to vendors. A hint, not proof: anything can send
 * any header, and many are shared between vendors. Returns null for headers
 * that say nothing about caching, and '' for ones worth showing that name no
 * vendor (Cache-Control). LiteSpeed is named from its headers only; this
 * project hasn't tested it (matrix/README.md).
 *
 * @param string $name  Header name, lower case.
 * @param string $value Header value.
 * @return string|null
 */
function cache_header_hint( string $name, string $value ): ?string {
	$value = strtolower( $value );

	switch ( $name ) {
		case 'age':
			return __( 'set by a cache: seconds it has held this response', 'wp-http-query' );
		case 'cache-control':
			return '';
		case 'x-cache':
			return __( 'a CDN or proxy cache; Fastly, CloudFront and Squid are among those that send it', 'wp-http-query' );
		case 'cf-cache-status':
			return 'Cloudflare';
		case 'x-varnish':
			return 'Varnish';
		case 'x-served-by':
			return __( 'a CDN; Fastly sends it', 'wp-http-query' );
		case 'x-litespeed-cache':
			return 'LiteSpeed';
		case 'x-kinsta-cache':
			return 'Kinsta';
		case 'x-proxy-cache':
		case 'x-cache-status':
			return __( 'an nginx proxy or FastCGI cache, as many hosts configure it', 'wp-http-query' );
		case 'x-cacheable':
			return __( 'a host’s page cache', 'wp-http-query' );
		case 'via':
			if ( str_contains( $value, 'varnish' ) ) {
				return 'Varnish';
			}
			if ( str_contains( $value, 'cloudfront' ) ) {
				return 'Amazon CloudFront';
			}
			return __( 'a proxy', 'wp-http-query' );
		case 'server':
			foreach ( array(
				'litespeed'  => 'LiteSpeed',
				'cloudflare' => 'Cloudflare',
				'varnish'    => 'Varnish',
			) as $needle => $vendor ) {
				if ( str_contains( $value, $needle ) ) {
					return $vendor;
				}
			}
			return null;
	}

	if ( preg_match( '/^x-(?:fastcgi|nginx|srcache|proxy)[-_]?cache/', $name ) ) {
		return __( 'an nginx proxy or FastCGI cache, as many hosts configure it', 'wp-http-query' );
	}

	return null;
}

/**
 * The cache-related headers in a response, each with its hint.
 *
 * @param array $headers Response headers.
 * @return array<int, array{header: string, value: string, hint: string}>
 */
function cache_header_hints( array $headers ): array {
	$seen = array();

	foreach ( normalize_headers( $headers ) as $name => $value ) {
		$hint = cache_header_hint( $name, $value );
		if ( null !== $hint ) {
			$seen[] = array(
				'header' => $name,
				'value'  => $value,
				'hint'   => $hint,
			);
		}
	}

	return $seen;
}

/**
 * Whether a cache answered the second of two different QUERY bodies, sent to
 * the same URL, with the first one's response.
 *
 * Pure: no I/O, no globals. Each response is what the fingerprint route
 * returned and what was sent to it.
 *
 * @param array $first  status, headers, body, sent (the request body), error.
 * @param array $second The same, for the second request.
 * @return array{verdict: string, status: string, http_status: int, http_statuses: int[], message: string, cache_headers: array}
 */
function diagnose_cache_probe( array $first, array $second ): array {
	$responses = array();
	$headers   = array();

	foreach ( array( $first, $second ) as $n => $response ) {
		$data        = json_decode( (string) ( $response['body'] ?? '' ), true );
		$responses[] = array(
			'status' => (int) ( $response['status'] ?? 0 ),
			'error'  => (string) ( $response['error'] ?? '' ),
			'sent'   => hash( 'sha256', (string) ( $response['sent'] ?? '' ) ),
			'hash'   => is_array( $data ) && is_string( $data['body_sha256'] ?? null ) ? $data['body_sha256'] : null,
			'fresh'  => is_array( $data ) && is_string( $data['fresh'] ?? null ) ? $data['fresh'] : null,
		);
		foreach ( cache_header_hints( (array) ( $response['headers'] ?? array() ) ) as $seen ) {
			$headers[] = array( 'response' => $n + 1 ) + $seen;
		}
	}

	list( $a, $b ) = $responses;
	$statuses      = array( $a['status'], $b['status'] );
	$result        = static function ( string $verdict, string $message ) use ( $statuses, $headers ): array {
		return array_merge(
			verdict( $verdict, $message, $statuses[1] ),
			array(
				'http_statuses' => $statuses,
				'cache_headers' => $headers,
			)
		);
	};

	foreach ( $responses as $response ) {
		if ( '' !== $response['error'] || 0 === $response['status'] ) {
			return $result(
				'inconclusive',
				sprintf(
					/* translators: %s: The error message from the HTTP request. */
					__( 'Couldn’t check for caching: a probe request didn’t complete (%s).', 'wp-http-query' ),
					'' !== $response['error'] ? $response['error'] : __( 'no response', 'wp-http-query' )
				)
			);
		}
		if ( 200 !== $response['status'] || null === $response['hash'] || null === $response['fresh'] ) {
			return $result(
				'inconclusive',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Couldn’t check for caching: a probe request got HTTP %d instead of the probe route’s answer. The QUERY check above says why QUERY isn’t getting through.', 'wp-http-query' ),
					$response['status']
				)
			);
		}
	}

	$repeated_hash = $a['sent'] !== $b['sent'] && $a['hash'] === $b['hash'];
	if ( $repeated_hash || $a['fresh'] === $b['fresh'] ) {
		$vendors = array_unique( array_filter( array_column( $headers, 'hint' ), static fn ( string $hint ): bool => '' !== $hint && ! str_contains( $hint, ' ' ) ) );
		$message = __( 'A cache between you and WordPress is storing QUERY responses without keying on the body: two different requests to the same URL got the same answer, so one visitor’s search can get another’s results.', 'wp-http-query' );
		if ( $vendors ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Comma-separated vendor names, such as Varnish or Cloudflare. */
				__( 'The response headers point to %s (a hint, not proof).', 'wp-http-query' ),
				implode( ', ', $vendors )
			);
		}
		if ( in_array( 'LiteSpeed', $vendors, true ) ) {
			$message .= ' ' . __( 'This project hasn’t tested LiteSpeed, so this is an observation from this site only.', 'wp-http-query' );
		}
		return $result( 'stored', $message );
	}

	if ( $a['hash'] !== $a['sent'] || $b['hash'] !== $b['sent'] ) {
		return $result( 'altered', __( 'Couldn’t check for caching: the probe’s body arrived changed, so its answers can’t be compared.', 'wp-http-query' ) );
	}

	return $result( 'clean', __( 'No caching detected: two different QUERY bodies sent to the same URL got two different, fresh answers.', 'wp-http-query' ) );
}

/**
 * Sends a QUERY from the site to itself.
 *
 * Signed in as the current user when asked, the way core's loopback test
 * does it (WP_Site_Health::can_perform_loopback()): the browser's cookies
 * forwarded, plus a REST nonce, since cookie auth needs one. HTTP Basic auth
 * is passed on too, but only when signed in: an Authorization header makes
 * caches skip the request, which would hide what the cache probe looks for.
 *
 * 'redirection' => 0, so a redirect is reported rather than followed:
 * WP_Http turns any method into GET on a 302.
 *
 * @param string $url            URL.
 * @param string $body           JSON request body.
 * @param bool   $as_current_user Whether to sign in as the current user.
 * @return array{status: int, headers: array, body: string, error: string}
 */
function loopback_query( string $url, string $body, bool $as_current_user ): array {
	$headers = array( 'Content-Type' => 'application/json' );
	$cookies = array();

	if ( $as_current_user ) {
		$headers['X-WP-Nonce'] = wp_create_nonce( 'wp_rest' );
		// Forwarded verbatim, as core's loopback test does.
		$cookies = wp_unslash( $_COOKIE ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, passed on verbatim as core's loopback test does.
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
		}
	}

	$response = wp_remote_request(
		$url,
		array(
			'method'      => METHOD,
			'headers'     => $headers,
			'body'        => $body,
			'cookies'     => $cookies,
			'timeout'     => LOOPBACK_TIMEOUT,
			'redirection' => 0,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as core's loopback test does.
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status'  => 0,
			'headers' => array(),
			'body'    => '',
			'error'   => $response->get_error_message(),
		);
	}

	$headers = wp_remote_retrieve_headers( $response );

	return array(
		'status'  => (int) wp_remote_retrieve_response_code( $response ),
		'headers' => is_array( $headers ) ? $headers : $headers->getAll(),
		'body'    => wp_remote_retrieve_body( $response ),
		'error'   => '',
	);
}

/**
 * The server check: a signed-in QUERY loopback to the echo route.
 *
 * Without a signed-in user (Site Health's scheduled run, WP-CLI) it goes
 * signed out, and a 401 from the route still shows QUERY reached WordPress.
 *
 * @return array Verdict from diagnose_echo_response(), plus 'url'.
 */
function run_server_check(): array {
	$url           = rest_url( NAMESPACE_ . '/echo' );
	$marker        = wp_generate_password( 20, false );
	$authenticated = is_user_logged_in() && ! empty( $_COOKIE );
	$response      = loopback_query( $url, (string) wp_json_encode( array( SELF_TEST_MARKER_KEY => $marker ) ), $authenticated );

	return diagnose_echo_response( $response['status'], $response['headers'], $response['body'], $marker, $authenticated, $response['error'] ) + array( 'url' => $url );
}

/**
 * The cache check: two different signed-out QUERY loopbacks to one URL.
 *
 * @return array Verdict from diagnose_cache_probe(), plus 'url'.
 */
function run_cache_check(): array {
	$url       = rest_url( NAMESPACE_ . '/self-test/fingerprint' );
	$responses = array();

	foreach ( array( 1, 2 ) as $n ) {
		$sent        = (string) wp_json_encode( array( 'probe' => wp_generate_password( 20, false ) . $n ) );
		$responses[] = loopback_query( $url, $sent, false ) + array( 'sent' => $sent );
	}

	return diagnose_cache_probe( $responses[0], $responses[1] ) + array( 'url' => $url );
}

/**
 * A check's result as a Site Health test result, with the check attached.
 *
 * @param string $test  HEALTH_TEST_REACH or HEALTH_TEST_CACHE.
 * @param array  $check A verdict from run_server_check() or run_cache_check().
 * @return array
 */
function health_result( string $test, array $check ): array {
	$labels = array(
		HEALTH_TEST_REACH => array(
			'good'        => __( 'HTTP QUERY requests reach WordPress', 'wp-http-query' ),
			'recommended' => __( 'Couldn’t confirm that HTTP QUERY requests reach WordPress', 'wp-http-query' ),
			'critical'    => __( 'HTTP QUERY requests don’t reach WordPress intact', 'wp-http-query' ),
		),
		HEALTH_TEST_CACHE => array(
			'good'        => __( 'No cache was seen storing HTTP QUERY responses', 'wp-http-query' ),
			'recommended' => __( 'Couldn’t check whether a cache stores HTTP QUERY responses', 'wp-http-query' ),
			'critical'    => __( 'A cache stores HTTP QUERY responses without the request body', 'wp-http-query' ),
		),
	);

	$description = sprintf( '<p>%s</p>', esc_html( $check['message'] ) );

	$statuses = $check['http_statuses'] ?? ( $check['http_status'] ? array( $check['http_status'] ) : array() );
	if ( $statuses ) {
		$description .= sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: HTTP status code(s). 2: URL. */
					__( 'HTTP status: %1$s. URL tested: %2$s', 'wp-http-query' ),
					implode( ', ', $statuses ),
					$check['url'] ?? ''
				)
			)
		);
	}

	if ( ! empty( $check['cache_headers'] ) ) {
		$description .= sprintf( '<p>%s</p><ul>', esc_html__( 'Cache-related response headers seen (hints, not proof):', 'wp-http-query' ) );
		foreach ( $check['cache_headers'] as $seen ) {
			$description .= sprintf(
				'<li><code>#%d %s: %s</code>%s</li>',
				(int) ( $seen['response'] ?? 0 ),
				esc_html( $seen['header'] ),
				esc_html( $seen['value'] ),
				'' !== $seen['hint'] ? ' (' . esc_html( $seen['hint'] ) . ')' : ''
			);
		}
		$description .= '</ul>';
	}

	if ( HEALTH_TEST_CACHE === $test ) {
		$description .= sprintf( '<p>%s</p>', esc_html( self_test_limits() ) );
	}

	return array(
		'label'       => $labels[ $test ][ $check['status'] ],
		'status'      => $check['status'],
		'badge'       => array(
			/* translators: Site Health badge. QUERY is the HTTP method's name; keep it as is. */
			'label' => __( 'HTTP QUERY', 'wp-http-query' ),
			'color' => 'good' === $check['status'] ? 'blue' : ( 'critical' === $check['status'] ? 'red' : 'orange' ),
		),
		'description' => $description,
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=' . SETTINGS_PAGE ) ),
			esc_html__( 'Test from your browser too, on Settings → HTTP QUERY', 'wp-http-query' )
		),
		'test'        => $test,
		'check'       => $check,
	);
}

/**
 * What a clean cache result doesn't prove. Shown on the page and in Site Health.
 */
function self_test_limits(): string {
	return __( 'A clean cache result is a sample, not a guarantee: some caches store a response only after several requests, or per region. The loopback usually bypasses a CDN, so the browser check is the one that goes through it. LiteSpeed and OpenLiteSpeed haven’t been tested by this project, so LiteSpeed results are observations, not claims.', 'wp-http-query' );
}

add_filter(
	'site_status_tests',
	static function ( array $tests ): array {
		// Async, like core's loopback test: each run sends requests to the site.
		$tests['async'][ HEALTH_TEST_REACH ] = array(
			'label'             => __( 'HTTP QUERY requests reach WordPress', 'wp-http-query' ),
			'test'              => rest_url( NAMESPACE_ . '/self-test/server' ),
			'has_rest'          => true,
			'async_direct_test' => static fn (): array => health_result( HEALTH_TEST_REACH, run_server_check() ),
		);
		$tests['async'][ HEALTH_TEST_CACHE ] = array(
			'label'             => __( 'No cache stores HTTP QUERY responses', 'wp-http-query' ),
			'test'              => rest_url( NAMESPACE_ . '/self-test/cache' ),
			'has_rest'          => true,
			'async_direct_test' => static fn (): array => health_result( HEALTH_TEST_CACHE, run_cache_check() ),
		);

		return $tests;
	}
);

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

		$path = __DIR__ . '/assets/self-test.js';

		wp_enqueue_script(
			'wp-http-query-self-test',
			plugins_url( 'assets/self-test.js', __FILE__ ),
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
