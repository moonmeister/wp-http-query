<?php
/**
 * Plugin Name:       WP HTTP QUERY
 * Plugin URI:        https://github.com/moonmeister/wp-http-query
 * Description:       HTTP QUERY (RFC 10008) support for the WordPress REST API, entirely in userland. No core patch required.
 * Version:           0.1.0-dev
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
		<?php render_status(); ?>
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
 * QUERY itself.
 *
 * @return array<string, string> Route key => 'preset:<name>', 'filter' or 'route'.
 */
function query_route_sources(): array {
	$opted_in = opted_in_route_sources();
	$sources  = array();

	foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
		foreach ( $handlers as $handler ) {
			if ( ! empty( $handler['methods'][ METHOD ] ) ) {
				$sources[ $route ] = $opted_in[ $route ] ?? 'route';
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
 * Demo route: off unless a site turns it on.
 *
 * QUERY /wp-json/wp-http-query/v1/echo reports how the server parsed the
 * request. It is unauthenticated and reflects its input, so it is only
 * registered when the 'wp_http_query_register_demo_route' filter returns true:
 *
 *     add_filter( 'wp_http_query_register_demo_route', '__return_true' );
 *
 * The default is false, not WP_DEBUG: WP_DEBUG is often left on for staging
 * sites that are publicly reachable, and a route that appears or disappears
 * with a debugging constant is a surprise. Opting in is one line.
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

add_action(
	'rest_api_init',
	static function (): void {
		/**
		 * Filters whether to register the diagnostic echo route.
		 *
		 * @param bool $register Whether to register QUERY /wp-http-query/v1/echo. Default false.
		 */
		if ( ! apply_filters( 'wp_http_query_register_demo_route', false ) ) {
			return;
		}

		register_rest_route(
			NAMESPACE_,
			'/echo',
			array(
				'methods'             => METHOD,
				'permission_callback' => '__return_true',
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
