# Integrating with HTTP QUERY

For plugin developers: how to make your REST routes answer `QUERY`, and how to send it. Site
owners want [plugin/README.md](../plugin/README.md) instead.

`QUERY` ([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008)) is a safe, idempotent method with a
request body. Use it for reads whose parameters don't fit in a URL: long lists of IDs, nested
filters, anything you'd otherwise send as a `POST` to a read endpoint.

Every full route below comes from the example plugin,
[`examples/query-route-example/`](../examples/query-route-example/query-route-example.php). Its
tests, [`tests/examples.test.ts`](../tests/examples.test.ts) and
[`tests/lifecycle/examples-feature-detection.test.ts`](../tests/lifecycle/examples-feature-detection.test.ts),
run it over HTTP on WordPress 7.0, 7.1 and nightly. Copy from the example rather than from this
page if they ever differ, and report the difference.

## Contents

- [Add QUERY to your own route](#add-query-to-your-own-route)
- [The safe-method trap](#the-safe-method-trap)
- [Feature detection](#feature-detection)
- [Opting in routes you don't own](#opting-in-routes-you-dont-own)
- [Body formats](#body-formats)
- [What Accept-Query advertises](#what-accept-query-advertises)
- [Sending QUERY](#sending-query)

## Add QUERY to your own route

Three things:

1. **List `QUERY` in `methods` next to `GET`**, on the same handler.
2. **Read parameters with `$request->get_param()` or `get_params()`.** They come from the query
   string for `GET` and from the body for `QUERY`. The handler doesn't need to know which.
3. **Declare your parameters as `args`.** Core validates a `QUERY` body against them exactly as it
   validates a `GET` query string, and answers `400` when a value doesn't fit.

The registration, from the example:

```php
add_action(
	'rest_api_init',
	static function (): void {
		$read_methods = \WP_REST_Server::READABLE;

		$query = query_method();
		if ( null !== $query ) {
			// String form, so cores before 7.2 can't trip Trac#65905.
			$read_methods .= ', ' . $query;
		}

		register_rest_route(
			ROUTE_NAMESPACE,
			'/books',
			array(
				array(
					'methods'             => $read_methods,
					'callback'            => __NAMESPACE__ . '\\get_books',
					'permission_callback' => __NAMESPACE__ . '\\check_permission',
					'args'                => array(
						'search'   => array(
							'description' => 'Case-insensitive match against the title.',
							'type'        => 'string',
						),
						'author'   => array(
							'description' => 'Only books by these authors.',
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'per_page' => array(
							'description' => 'Maximum number of books to return.',
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 10,
							'default'     => 10,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => __NAMESPACE__ . '\\create_book',
					'permission_callback' => __NAMESPACE__ . '\\check_permission',
					'args'                => array(
						'title' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}
);
```

`query_method()` is the feature check; see [Feature detection](#feature-detection). When the
plugin is active, `$read_methods` is `'GET, QUERY'`.

The read handler. Nothing in it is specific to `QUERY`:

```php
function get_books( \WP_REST_Request $request ): \WP_REST_Response {
	$search  = (string) $request->get_param( 'search' );
	$authors = (array) $request->get_param( 'author' );

	$books = array_values(
		array_filter(
			books(),
			static function ( array $book ) use ( $search, $authors ): bool {
				if ( '' !== $search && false === stripos( $book['title'], $search ) ) {
					return false;
				}

				return ! $authors || in_array( $book['author'], $authors, true );
			}
		)
	);

	return new \WP_REST_Response( array_slice( $books, 0, (int) $request->get_param( 'per_page' ) ) );
}
```

What the tests check against this route, on every test site:

- `QUERY` with `{"search":"the","per_page":2}` as JSON returns the first two matches.
- `QUERY` with `author[]=Ursula K. Le Guin&per_page=1` form-encoded returns one book.
- `QUERY` with a body and `GET` with the same query string return the same JSON.
- `{"per_page":100}` as JSON, and `per_page=0` form-encoded, both return `400 rest_invalid_param`.
- An anonymous `QUERY` gets the read (`200`); an anonymous `POST` to the same route gets `401`.

**Use the string form of `methods`.** `'GET, QUERY'` works on every core version.

> **Warning:** before WordPress 7.2, a comma-separated string inside the **array** form breaks
> the route. `'methods' => array( WP_REST_Server::READABLE . ', ' . QUERYABLE )` registers one
> key, `GET, QUERY`, that no request matches. Use the string form,
> `WP_REST_Server::READABLE . ', ' . QUERYABLE`, or one method per array element,
> `array( 'GET', QUERYABLE )`.

In the array form, an entry that lists more than one method, like `array( 'POST, PUT', 'QUERY' )`,
registers one unmatchable key on cores before 7.2
([Trac#65905](https://core.trac.wordpress.org/ticket/65905), fixed in
[r63756](https://core.trac.wordpress.org/changeset/63756)). `array( 'GET', 'QUERY' )`, one method
per entry, is also safe.

**Keep reads and writes in separate handlers.** Core's own controllers do: `get_items` for reads,
`create_item` and friends for writes, never one callback for both. The example's `POST` handler is
a separate function, `create_book()`.

## The safe-method trap

**Never use `'GET' === $request->get_method()` to mean "this request is read-only".** `QUERY` is
read-only too, and it isn't `GET`. Code that asks "is it `GET`?" treats every `QUERY` as a write.

This isn't hypothetical. Jetpack (3M installs) registers read routes whose callback does this:

```php
if ( 'GET' === $request->get_method() ) {
	return $this->get_modules();
} else {
	return static::activate_modules( $request );
}
```

Send that route a `QUERY` and it activates modules. Between 28 and 65 plugins in the directory
both branch on the method and register read routes with `READABLE`; see
[#38](https://github.com/moonmeister/wp-http-query/issues/38) and the
[method-branching sweep](../experiments/plugin-spike/README.md#method-branching-sweep) (2026-09-15).
It's why the plugin adds `QUERY` to no route by default
([ADR 0005](decisions/0005-reach-for-existing-read-routes.md)).

**Instead, ask whether the method is safe.** From the example:

```php
function is_safe_method( string $method ): bool {
	return in_array( strtoupper( $method ), array( 'GET', 'HEAD', 'OPTIONS', 'QUERY' ), true );
}
```

It lists the safe methods, so a method it doesn't know counts as a write. That fails closed. The
opposite check, "is it `POST`, `PUT`, `PATCH` or `DELETE`?", fails open.

The example uses it in the one place its code is shared between reads and writes, the permission
callback:

```php
function check_permission( \WP_REST_Request $request ): bool {
	if ( is_safe_method( $request->get_method() ) ) {
		return true;
	}

	return current_user_can( 'edit_posts' );
}
```

With `'GET' === $request->get_method()` in place of `is_safe_method()`, an anonymous `QUERY` would
get `401`. The test `treats QUERY as a read, not a write` checks that it gets `200`.

Better still is not to need the check: give each handler its own callback and its own permission
callback. One side effect of sharing a permission callback: core builds the `Allow` header by
calling every handler's permission callback with the current request, so a shared callback can
list `POST` in `Allow` for a user who can't `POST`. That's core behavior for `GET` too, not
something `QUERY` adds.

Also check your filters. Code hooked on `rest_pre_dispatch`, `rest_request_before_callbacks` or
similar sees every method on every route. If it treats "not `GET`" as a write, it will treat
`QUERY` as one.

## Feature detection

Register `QUERY` only when something on the site supports it. From the example:

```php
function query_method(): ?string {
	// A future core release. Not in any release as of 2026-11-04.
	if ( defined( 'WP_REST_Server::QUERYABLE' ) ) {
		return constant( 'WP_REST_Server::QUERYABLE' );
	}

	// The wp-http-query feature plugin.
	if ( defined( 'WPHttpQuery\QUERYABLE' ) ) {
		return constant( 'WPHttpQuery\QUERYABLE' );
	}

	return null;
}
```

When it returns `null`, register `GET` only. The route keeps working, and a `QUERY` gets core's
`404`. The lifecycle test deactivates wp-http-query and checks exactly that.

**Why not register `QUERY` anyway?** Core will dispatch it without the plugin, but it gets three
things wrong. Form-encoded bodies are silently dropped, so a filtered search returns everything
with a `200`. Cross-origin browsers are refused at the preflight. And there's no `Accept-Query`.
The silent wrong answer is the reason to check.

**Call it on `rest_api_init`, not when your plugin file loads.** Plugins load in alphabetical
order, so wp-http-query may not have loaded yet when your file runs. By `rest_api_init` every
plugin has.

**Core versions.** Nothing changes for your route at 7.2:

| Core | What differs | What to do |
|---|---|---|
| Before 7.2 | Array-form `methods` aren't comma-split (Trac#65905). CORS has no filter, so the plugin replaces core's CORS handler | Use the string form of `methods`. The plugin handles CORS |
| 7.2 and later | Both fixed ([r63756](https://core.trac.wordpress.org/changeset/63756), [r64730](https://core.trac.wordpress.org/changeset/64730)). The plugin adds `QUERY` through core's `rest_allowed_cors_methods` filter | Nothing |
| A future core with `QUERYABLE` | Not proposed yet; parked until the plugin ships ([ADR 0002](decisions/0002-allmethods-vs-queryable.md)). The constant is planned as `WP_REST_Server::QUERYABLE` | `query_method()` already checks for it first |

Don't compare version numbers. Alpha and beta builds of a release compare lower than the release.
Check for the constant.

## Opting in routes you don't own

To add `QUERY` to a route registered by core or another plugin, list it in the
`wp_http_query_routes` filter:

```php
add_filter( 'wp_http_query_routes', function ( array $routes ) {
	$routes[] = '/my-plugin/v1/things';
	$routes[] = '/wp/v2/posts/(?P<id>[\d]+)'; // Routes with parameters: the registered key, verbatim.
	return $routes;
} );
```

- **Entries match registered route keys exactly.** No wildcards, no prefixes. For a route with
  parameters, use the regex key as registered. `GET /wp-json/` lists every key.
- **Append; don't replace.** The filter's starting value holds the routes of any enabled preset.
  Returning a fresh array turns the preset off.
- **Only the route's `GET` handlers gain `QUERY`.** Its `POST`, `PUT`, `PATCH` and `DELETE`
  handlers don't.
- **Read the route's code first.** You're vouching that its `GET` callback doesn't branch on the
  method. See [the safe-method trap](#the-safe-method-trap).

Presets are named lists of routes. There's one, `core-collections`, which site owners turn on in
**Settings → HTTP QUERY**. Code can force it on or off with `wp_http_query_presets`, which receives
the stored setting and wins over it:

```php
add_filter( 'wp_http_query_presets', function ( array $presets ) {
	$presets[] = 'core-collections';
	return $presets;
} );
```

Return `array()` to force presets off. The routes in `core-collections` are listed in
[plugin/README.md](../plugin/README.md#turn-it-on-for-cores-content).

This is for sites, and for plugins that integrate with a specific other plugin. If you own the
route, put `QUERY` in its `methods` instead.

## Body formats

Two body formats are parsed into the request's parameters
([ADR 0001](decisions/0001-query-body-media-type.md), option B):

| `Content-Type` | Parsed? |
|---|---|
| `application/json` | Yes. Core does this even without the plugin |
| `application/x-www-form-urlencoded` | Yes, with the plugin. Core alone drops it silently |
| Anything else | No. Read it yourself with `$request->get_body()` |

Body parameters win over query-string parameters with the same name, as for `POST`.

If your route takes a real query language, like SQL or JSONPath, read `get_body()` and parse it in
your handler. Nothing in core or the plugin stops you. You'd also want to change what
`Accept-Query` says; see the next section.

## What Accept-Query advertises

On any response from a route that accepts `QUERY`, the plugin sends:

```http
Accept-Query: "application/json"
```

[RFC 10008 §3](https://www.rfc-editor.org/rfc/rfc10008#section-3) defines it: the body formats the
resource accepts, as a Structured Fields list. Clients can read it to pick a format.

**JSON only, though form-encoded is parsed too.** Parsing form-encoded bodies fixes a silent data
loss. Advertising it would recommend a poor format for structured queries. This is the current
default (adopted 2026-11-04) and open to change if real use calls for it
([ADR 0001](decisions/0001-query-body-media-type.md), open question 2;
[#20](https://github.com/moonmeister/wp-http-query/issues/20)).

Sites can change the list with the `wp_http_query_advertised_types` filter. It applies to every
route; there's no per-route setting.

## Sending QUERY

### curl

```sh
curl -X QUERY 'https://example.com/wp-json/query-route-example/v1/books' \
  -H 'Content-Type: application/json' \
  --data '{"author":["Octavia E. Butler"]}'
```

### Browser `fetch()`

```js
const response = await fetch( '/wp-json/query-route-example/v1/books', {
	method: 'QUERY',
	headers: { 'Content-Type': 'application/json' },
	body: JSON.stringify( { search: 'the', per_page: 2 } ),
} );
```

What's known, as of 2026-08-16 ([docs/ecosystem.md](ecosystem.md#browsers)):

- **The Fetch standard lets `fetch()` send `QUERY`.** It isn't a forbidden method.
- **Write `'QUERY'` in capitals.** Fetch doesn't normalize its case, and method names are
  case-sensitive, so `'query'` is sent as a different method.
- **Cross-origin requests always preflight.** `QUERY` isn't CORS-safelisted. The plugin adds it to
  `Access-Control-Allow-Methods`, and the test suite checks the preflight.
- **Browser support isn't settled.** Fetch's handling of `QUERY`
  ([whatwg/fetch#1938](https://github.com/whatwg/fetch/issues/1938)) is open, and Mozilla and
  WebKit haven't published positions. `<form method="query">` isn't supported yet.
- **This project hasn't tested a browser.** The test suite sends `QUERY` with Node's `fetch()`
  (undici), not from a browser.

Logged-in requests need the usual REST nonce, in an `X-WP-Nonce` header.

### `@wordpress/api-fetch`: unverified

Whether `apiFetch( { method: 'QUERY', ... } )` works hasn't been checked. Earlier claims about it
didn't survive verification, so treat it as unknown until
[#28](https://github.com/moonmeister/wp-http-query/issues/28) reads the source and its middleware.
Use `fetch()` in the meantime.

### PHP: `wp_remote_request()`

```php
$response = wp_remote_request(
	'https://example.com/wp-json/query-route-example/v1/books',
	array(
		'method'  => 'QUERY',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array( 'search' => 'the', 'per_page' => 2 ) ),
	)
);
```

This works, with caveats ([scope.md §5.1](scope.md#51-the-requests-library--already-works-undocumented)):

- **It works, but isn't supported.** The bundled Requests library sends the method and body
  verbatim on both transports, cURL and fsockopen. That was established by reading the source of
  Requests 2.0.17, not by a test in this repo. `WP_Http`'s documentation lists the methods it
  supports, and `QUERY` isn't one.
- **There's no `Requests::QUERY` constant yet.** Use the string `'QUERY'`.
  [Requests#1075](https://github.com/WordPress/Requests/pull/1075) adds one; it was open, in the
  2.1.0 milestone, when last checked on 2026-08-16. Tracked here as
  [#6](https://github.com/moonmeister/wp-http-query/issues/6).
- **Some redirects turn `QUERY` into `GET`.** `WP_Http::browser_redirect_compatibility()` switches
  **any** method to `GET` on a `302`, and Requests does the same on a `303`. The request data
  goes with it, sent as a `GET` instead of a `QUERY` body. `301`, `307` and `308` keep the method. If the method matters, call the final URL directly, or
  pass `'redirection' => 0` and handle the `Location` header yourself. (Read from WordPress 7.1's
  `class-wp-http.php` and Requests 2.0.17's `Requests.php`; not tested here.)

### Fallback: the method override tunnel

For a client or network that can't send `QUERY`, core accepts a `POST` carrying an override
header:

```sh
curl -X POST 'https://example.com/wp-json/query-route-example/v1/books' \
  -H 'X-HTTP-Method-Override: QUERY' \
  -H 'Content-Type: application/json' \
  --data '{"search":"the"}'
```

Your handler sees `QUERY`. Everything between the client and WordPress sees `POST`, so caches and
proxies treat it as a write and don't retry it. It's a tunnel, not the method. The test suite
checks it against the plugin's echo route, which applies the same permission check either way:
admins only, unless a site makes it public.
