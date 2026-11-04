# WP HTTP QUERY (feature plugin)

HTTP `QUERY` ([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008)) for the WordPress REST API, on
stock core. See the [project README](../README.md) for the why.

`QUERY` is a read, like `GET`, but the request carries a body. Clients use it when search
parameters are too large or too structured for a URL. This plugin lets the REST API accept it.
It changes nothing until you, or a plugin you run, turn it on for a route.

Plugin developers: [docs/integrating.md](../docs/integrating.md) covers adding `QUERY` to your
own routes, and sending it.

## What it does

- **Parses `QUERY` request bodies**, JSON and form-encoded, into the request's parameters, the
  same way core does for `POST`.
- **Adds `QUERY` to CORS**, so browsers on other origins can send it.
- **Sends `Accept-Query: "application/json"`** on routes that accept `QUERY`, so clients can see
  which body format to use.
- **Adds `QUERY` to the routes you choose**, and to no others. See
  [Choosing which routes accept QUERY](#choosing-which-routes-accept-query).
- **Registers a test route**, `/wp-json/wp-http-query/v1/echo`, that reports what the server
  received. Use it to check your server passes `QUERY` through.

It stores one option and adds no tables, cron jobs or cache headers.

## Requirements

- PHP 8.0 or later.
- WordPress 7.0 or later. Tested on 7.0.8, 7.1.4 and nightly builds (as of 2026-11-04).
- A web server that passes `QUERY` through to PHP. Most do. See
  [Check your server](#check-your-server).

## Install

The plugin isn't in the plugin directory yet ([#23](https://github.com/moonmeister/wp-http-query/issues/23)). Until it is:

1. Copy this `plugin/` directory to `wp-content/plugins/wp-http-query/`.
2. Activate **WP HTTP QUERY** under **Plugins**, or run `wp plugin activate wp-http-query`.

Activating it doesn't add `QUERY` to any of your site's existing routes.

## Turn it on for core's content

Go to **Settings → HTTP QUERY** (or click **Settings** under the plugin on the **Plugins** screen)
and tick **Accept QUERY on core’s read collections**. You need the `manage_options` capability.
It's off by default.

The same page lists every route that accepts `QUERY` right now and why: a preset, the
`wp_http_query_routes` filter, or the route registering it itself.

That enables the `core-collections` preset. These routes then accept `QUERY`, with the same
parameters as `GET`:

| Route | Lists |
|---|---|
| `/wp/v2/posts` | Posts |
| `/wp/v2/pages` | Pages |
| `/wp/v2/media` | Media |
| `/wp/v2/comments` | Comments |
| `/wp/v2/categories` | Categories |
| `/wp/v2/tags` | Tags |
| `/wp/v2/users` | Users |
| `/wp/v2/search` | Search results |

Collections only. Single items, like `/wp/v2/posts/123`, aren't included. Permissions don't
change: a `QUERY` sees exactly what a `GET` with the same parameters would.

If the setting has a note saying code overrides it, a plugin or theme on your site uses the
`wp_http_query_presets` filter, and that wins.

## Check it works

Send a `QUERY` to the test route. Replace `example.com` with your site:

```sh
curl -i -X QUERY 'https://example.com/wp-json/wp-http-query/v1/echo' \
  -H 'Content-Type: application/json' \
  --data '{"search":"hello"}'
```

A working setup answers `200`, with `Accept-Query: "application/json"` in the headers and this in
the JSON body:

```
"method":"QUERY"
...
"merged_params":{"search":"hello"}
```

If your site uses plain permalinks, use `https://example.com/index.php?rest_route=/wp-http-query/v1/echo`
instead. Keep the `index.php`: on nginx, `QUERY` to the bare `/` gets a `405` from nginx's index
module before WordPress sees it, while `/index.php` reaches PHP
([matrix notes](../matrix/README.md)).

With the preset on, try a real collection:

```sh
curl -X QUERY 'https://example.com/wp-json/wp/v2/posts' \
  -H 'Content-Type: application/json' \
  --data '{"search":"hello","per_page":1}'
```

### If it doesn't work

| You get | Likely cause |
|---|---|
| `404` with `"code":"rest_no_route"` | The plugin isn't active. On a preset route, the preset is off |
| `403` page from the web server, not JSON | A hardening rule that allows only `GET` and `POST`. See below |
| `501 Not Implemented` | PHP's built-in server (`php -S`), or Varnish 8.0 – 9.0.x in front of the site. See below |
| `405` | You may have sent it to `/`, not `/wp-json/...`. nginx rejects `QUERY` at the site root, which doesn't affect the REST API |

## Check your server

Results from the project's [compatibility matrix](../matrix/results/MATRIX.md), run 2026-08-16
over HTTP/1.1 with request bodies up to 64 KiB:

| Server | `QUERY` reaches WordPress? |
|---|---|
| nginx + PHP-FPM | Yes |
| Apache + mod_php | Yes |
| Apache + PHP-FPM (`mod_proxy_fcgi`) | Yes |
| Caddy + PHP-FPM | Yes |
| PHP built-in server (`php -S`) | No, `501`. Affects local development, not production |
| nginx with `limit_except GET POST { deny all; }` | No, `403` |
| Apache with `<LimitExcept GET POST>` | No, `403` |

The `403` rows are hardening rules that allow only `GET` and `POST`. They block `QUERY` the same
way they block `PUT` or `DELETE`. Apache accepts `QUERY` as a method name, so you can add it to a
`LimitExcept` list. nginx can't name `QUERY` in `limit_except` (as of 2026-08-10, see
[docs/ecosystem.md](../docs/ecosystem.md#nginx--no-native-method-identifier)).

**Caches and proxies in front of the site.** By default, no shipping cache stores a `QUERY`
response, so you won't get one visitor's results served to another. **One exception: W3 Total
Cache Pro with REST caching enabled.** It caches `QUERY` responses keyed by URL alone, without the
method or body, so different searches to the same URL can share one cached entry and anonymous
visitors can get the wrong public results. It doesn't expose private data: W3TC skips logged-in
requests, and anything in a `QUERY` body could be fetched with `GET` anyway. If you use the
preset or opt routes in, leave W3TC's REST caching off. One version range blocks `QUERY` outright: Varnish
8.0 – 9.0.x answers `QUERY` with `501` itself, and the request never reaches WordPress. Varnish
9.1.0 and later pass it through. Source: the
[cache survey](../experiments/cache-survey/README.md), from reading each cache's source and
default config (2026-08-19).

**Not tested:** LiteSpeed and OpenLiteSpeed, HTTP/2 and HTTP/3, TLS, and CDNs, WAFs and managed
hosts. Not tested means unknown, not broken. The `curl` check above tells you about your own
stack.

## Choosing which routes accept QUERY

**Nothing gains `QUERY` by default.** Plugins exist whose `GET` callbacks treat anything other than
`GET` as a write, so the plugin never adds `QUERY` to a route nobody chose
([ADR 0005](../docs/decisions/0005-reach-for-existing-read-routes.md)). Three ways to choose:

### 1. Your route registers it

```php
register_rest_route( 'my-plugin/v1', '/things', array(
	'methods'             => WP_REST_Server::READABLE . ', ' . \WPHttpQuery\QUERYABLE,
	'callback'            => 'my_plugin_list_things',
	'permission_callback' => '__return_true',
) );
```

`\WPHttpQuery\QUERYABLE` is `'QUERY'`, standing in for core's future `WP_REST_Server::QUERYABLE`.
`'GET, QUERY'` is equivalent. Prefer the string form: in the array form, an entry listing more
than one method, like `array( 'POST, PUT', 'QUERY' )`, can't be matched before 7.2 (Trac#65905).

A full, tested version, with feature detection and the read-only check, is in
[docs/integrating.md](../docs/integrating.md).

### 2. Your site lists routes

```php
add_filter( 'wp_http_query_routes', function ( array $routes ) {
	$routes[] = '/my-plugin/v1/things';
	$routes[] = '/wp/v2/posts/(?P<id>[\d]+)'; // Routes with parameters: the registered key, verbatim.
	return $routes;
} );
```

Entries match registered route keys exactly. No wildcards or prefixes. `GET /wp-json/` lists the
keys. Add to `$routes` rather than replacing it, since it already holds the enabled presets' routes.

**Only list routes whose code you've checked.** If the route's `GET` callback does something like
`if ( 'GET' === $request->get_method() ) { read } else { write }`, a `QUERY` runs the write. This
is why nothing is on by default. See
[the safe-method trap](../docs/integrating.md#the-safe-method-trap).

### 3. Your site enables a preset

`core-collections` adds `QUERY` to core's read collections: `/wp/v2/posts`, `/wp/v2/pages`,
`/wp/v2/media`, `/wp/v2/comments`, `/wp/v2/categories`, `/wp/v2/tags`, `/wp/v2/users` and
`/wp/v2/search`. Their `GET` handlers were checked for branching on the request method; see
ADR 0005.

Turn it on at **Settings → HTTP QUERY**, or in code, which overrides the setting:

```php
add_filter( 'wp_http_query_presets', function ( array $presets ) {
	$presets[] = 'core-collections';
	return $presets;
} );
```

Return `array()` from the same filter to force presets off.

### Either way

Only handlers that accept `GET` gain `QUERY`. A route's `POST`, `PUT`, `PATCH` and `DELETE`
handlers never do.

## Diagnostic route

`QUERY /wp-json/wp-http-query/v1/echo` reports how WordPress parsed a request: method, content
type, body, query and merged params. It needs no authentication and reflects its input, so it is
**off by default**. Turn it on while you check a setup, then turn it off again:

```php
add_filter( 'wp_http_query_register_demo_route', '__return_true' );
```

```sh
curl -X QUERY https://example.com/wp-json/wp-http-query/v1/echo \
	-H 'Content-Type: application/json' -d '{"search":"needle"}'
```

It is off whatever `WP_DEBUG` says, since staging sites that anyone can reach often run with
`WP_DEBUG` on.

## Data

One option, `wp_http_query_presets`, holding the enabled preset names. Deactivating keeps it;
uninstalling deletes it.
