# WP HTTP QUERY (feature plugin)

HTTP `QUERY` ([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008)) for the WordPress REST API, on
stock core. See the [project README](../README.md) for the why.

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

### 3. Your site enables a preset

`core-collections` adds `QUERY` to core's read collections: `/wp/v2/posts`, `/wp/v2/pages`,
`/wp/v2/media`, `/wp/v2/comments`, `/wp/v2/categories`, `/wp/v2/tags`, `/wp/v2/users` and
`/wp/v2/search`. Their `GET` handlers were checked for branching on the request method; see
ADR 0005.

Turn it on at **Settings → General → HTTP QUERY**, or in code, which overrides the setting:

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
