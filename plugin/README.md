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
- **Adds `QUERY` to CORS**, so browsers on other origins can send it, through core's
  `rest_allowed_cors_methods` filter. A site that removed core's CORS handler keeps REST CORS off,
  and a CORS plugin that sends its own `Access-Control-Allow-Methods` after core's still wins.
- **Sends `Accept-Query: "application/json"`** on routes that accept `QUERY`, so clients can see
  which body format to use.
- **Adds `QUERY` to the routes you choose**, and to no others. See
  [Choosing which routes accept QUERY](#choosing-which-routes-accept-query).
- **Tests your server.** A button on **Settings → HTTP QUERY**, and three Site Health tests, check
  that `QUERY` reaches WordPress intact, that no cache stores `QUERY` responses, and that CORS
  lets pages on other sites send `QUERY`. See
  [Check your server](#check-your-server).

It stores one option and adds no tables, cron jobs or cache headers.

## Requirements

- PHP 7.4 or later.
- WordPress 7.2 or later, including its betas and release candidates. Tested on nightly builds
  (7.2-beta4, as of 2026-11-05), single site and multisite. See [Multisite](#multisite).
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

## Check your server

Go to **Settings → HTTP QUERY** and click **Test your server**. It runs five checks, in two
groups, and explains each result in plain language. A summary line comes first: **All 5 checks
passed**, or how many problems it found and which checks they are.

**From your browser** goes through everything in front of your site (CDN, proxy, firewall), the
way visitors' requests do. **From your server** is WordPress calling itself, the way Site Health's
loopback test works: it tests the web server and PHP, usually skips the CDN, and catches
page-cache plugins.

| Check | What it sends |
|---|---|
| `QUERY` reaches WordPress from your browser | One `QUERY`, signed in as you, to the plugin's echo route, with a random value in a JSON body. Checks the method, the value and the `Accept-Query` header come back |
| No cache between your browser and WordPress stores `QUERY` responses | Two anonymous `QUERY`s with different bodies to the same URL. Checks the second answer isn't the first one again |
| `QUERY` reaches WordPress from the server itself | The same echo check, sent by your site to itself |
| No cache on the server's own path stores `QUERY` responses | The same cache check, sent by your site to itself |
| Pages on other sites may send `QUERY` (CORS) | The preflight a browser on another site sends before a `QUERY`: an anonymous `OPTIONS` to the cache check's route, with `Origin: https://cross-origin.example` and `Access-Control-Request-Method: QUERY`. Checks that `Access-Control-Allow-Methods` includes `QUERY`. Server only: the browser checks run on your own site, so the browser never sends a preflight |

Each result shows the request it made (method, URL path, signed in or anonymous), the HTTP status,
and one line saying why.

**Site Health** (**Tools → Site Health**) runs the three server checks too, as **HTTP QUERY requests
reach WordPress**, **No cache was seen storing HTTP QUERY responses** and **CORS allows HTTP QUERY
requests from other sites**.

The tests send a few small requests to your own site and change nothing. You need the
`manage_options` capability.

### What the results mean

Sample result, from an nginx site using plain permalinks:

> **QUERY reaches WordPress from the server itself: failed**
> The server sent itself: QUERY /?rest_route=/wp-http-query/v1/echo, signed in as you HTTP status 405.
> The web server refused QUERY before WordPress saw it. nginx does this when the request goes to
> the site root (/ or /?rest_route=…) instead of /index.php or /wp-json/: its index module answers
> 405 first. Check the URL tested; with plain permalinks, send QUERY to /index.php?rest_route=…
> instead.

| Result | Likely cause |
|---|---|
| Passed | `QUERY` reached WordPress with its method and body intact |
| `405` | nginx answering a `QUERY` to the site root. Use `/wp-json/…` or `/index.php?rest_route=…` ([matrix notes](../matrix/README.md)) |
| `403` from the web server, not WordPress | A rule that allows only `GET` and `POST`, such as nginx's `limit_except GET POST` or Apache's `<LimitExcept GET POST>` ([how to fix](#fixing-a-403-from-a-hardening-rule)), or a firewall |
| `501` | Varnish 8.0 – 9.0.x in front of the site, or PHP's built-in server (`php -S`) |
| `301`, `302` or another redirect | A client that follows it may switch `QUERY` to `GET` and drop the body. WordPress's HTTP API does on a `302`; browsers on a `303`. Check the Site Address in **Settings → General** |
| Method changed | Something between the client and WordPress turned `QUERY` into another method |
| Body lost or changed | Something between the client and WordPress dropped or rewrote the body |
| `Accept-Query` missing | `QUERY` works, but something in front of WordPress strips the header clients use to find the body format |
| Your browser can't send `QUERY` | The browser, not your server. The server checks still apply |
| Network error | The request didn't complete. If Site Health's loopback test fails too, fix that first |
| No CORS headers at all | The site turned REST CORS off (core sends the headers by default), often with a security plugin. The plugin respects that and doesn't turn it back on, so Site Health marks it **recommended**, not failed. Pages on other sites can't call your REST API from a browser with any method |
| `QUERY` allowed, but for other origins only | A CORS plugin that allows only some origins answered the test's origin with another. Pages on the origins it allows can likely send `QUERY`; the test can't confirm it, so it's **recommended** |
| CORS allows `QUERY` but not `Content-Type` | Something replaced core's `Access-Control-Allow-Headers`, which includes `Content-Type`, so browsers refuse a `QUERY` with a JSON body from other sites. Usually another CORS plugin or a server rule |
| CORS headers without `QUERY` | Something overrides the method list: most likely a `rest_allowed_cors_methods` callback that removes `QUERY`, or another CORS plugin or a server rule sending its own `Access-Control-Allow-Methods`. Add `QUERY` back there |
| A cache is storing `QUERY` responses | Two different bodies got the same answer, so one visitor's search can get another's results. The cache-related headers seen (`Age`, `X-Cache`, `CF-Cache-Status`, `X-Varnish`, `X-LiteSpeed-Cache` and others) are listed, with a guess at the vendor. A guess, not proof |

Site Health marks a failed check **critical**, and one that couldn't tell **recommended**.

**A clean cache result is a sample, not a guarantee.** Some caches only store a response after
several requests, or per region. LiteSpeed and OpenLiteSpeed haven't been tested by this project,
so a result naming LiteSpeed is an observation from your site, not a claim about LiteSpeed.

### With curl

The echo route answers admins only, unless you've made it public with the
[filter](#diagnostic-route). With it public, from anywhere:

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

Left admins only, the same request gets `401` with `"code":"rest_forbidden"`. That still shows
`QUERY` reached WordPress: WordPress checks permission only after it matched the `QUERY` route.

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

A `404` with `"code":"rest_no_route"` on a preset route means the plugin isn't active, or the
preset is off.

## Servers and caches we know about

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
way they block `PUT` or `DELETE`. See [Fixing a 403](#fixing-a-403-from-a-hardening-rule).

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
hosts. Not tested means unknown, not broken. [The self-test](#check-your-server) tells you about your
own stack.

### Fixing a 403 from a hardening rule

**First, check who sent it.** A `403` from WordPress has a JSON body with a `code` such as
`rest_forbidden`, and the self-test reports it as a WordPress error, not a server rule. A `403`
without that, and with nothing in the PHP or WordPress log, points to a rule that answered before
PHP ran: check the web server's access log, not PHP's.

**Widening the rule is your call.** Someone added it to shrink what the site accepts, so letting
`QUERY` through is a security decision for whoever runs the server.

**Apache.** Apache can name `QUERY`. Add it to both lists:

```apache
<Limit GET POST QUERY>
    Require all granted
</Limit>
<LimitExcept GET POST QUERY>
    Require all denied
</LimitExcept>
```

Keep both `Require` lines inside a `<Limit>` or `<LimitExcept>`. Apache ORs the `Require` lines in
one context, so an unscoped `Require all granted` next to `<LimitExcept GET POST>`'s
`Require all denied` grants every method: the rule looks hardened and blocks nothing. To check,
`curl -i -X DELETE` the site; Apache should answer `403`. This project's own test config fell into
this trap ([matrix notes](../matrix/README.md#two-traps-this-harness-fell-into-both-now-guarded)).

**nginx.** `limit_except` can't name `QUERY`: nginx has no method identifier for it, so
`limit_except GET POST QUERY` is a config error (`invalid method "QUERY"`) and nginx won't start
([docs/ecosystem.md](../docs/ecosystem.md#nginx--no-native-method-identifier)). A plain
`limit_except GET POST` refuses `QUERY` with a `403`. Instead, write the allowlist as a check on
`$request_method`, which keeps the method's name, in each location a REST request passes through
(usually `location /` and `location ~ \.php$`):

```nginx
if ($request_method !~ ^(GET|HEAD|POST|QUERY)$) {
    return 403;
}
```

Tested on nginx 1.30.6 (2026-11-05): `GET` and `QUERY` get through, `DELETE` gets a `403`. Send
`QUERY` and `DELETE` to your own site to confirm the same.

## Choosing which routes accept QUERY

**Nothing gains `QUERY` by default.** Plugins exist whose `GET` callbacks treat anything other than
`GET` as a write, so the plugin never adds `QUERY` to a route nobody chose
([ADR 0005](../docs/decisions/0005-reach-for-existing-read-routes.md)). Three ways to choose:

### 1. Your route registers it

```php
register_rest_route( 'my-plugin/v1', '/things', array(
	'methods'             => array( WP_REST_Server::READABLE, \WPHttpQuery\QUERYABLE ),
	'callback'            => 'my_plugin_list_things',
	'permission_callback' => '__return_true',
) );
```

`\WPHttpQuery\QUERYABLE` is `'QUERY'`, standing in for core's future `WP_REST_Server::QUERYABLE`.
`array( 'GET', 'QUERY' )` and `'GET, QUERY'` are equivalent.

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
type, body, query and merged params. The self-test uses it, so it's always there, but it reflects
its input, so by default **only admins** (`manage_options`) can call it. Anyone else gets `401`
(signed out) or `403`. To make it public while you check a setup with `curl`, then switch it back:

```php
add_filter( 'wp_http_query_public_demo_route', '__return_true' );
```

It's admins only whatever `WP_DEBUG` says, since staging sites that anyone can reach often run with
`WP_DEBUG` on.

The self-test's cache check uses a second route, `QUERY /wp-json/wp-http-query/v1/self-test/fingerprint`.
It's public, because caches usually skip signed-in requests, and safe to be: it answers with a
SHA-256 of the body it received and a value that's new on every request, and nothing else. It
echoes nothing back and sends no cache headers of its own. The CORS check sends its preflight
there too, since the plugin always registers it for `QUERY`.

## Multisite

Supported. Network-activate it or activate it site by site; it behaves the same on every site of a
subdirectory network, including REST routes under a site's path (`/blog/wp-json/...`). Tested on a
nightly build with the plugin network-activated, on the main site and a subsite. Subdomain
networks aren't tested: nothing in the plugin depends on the URL scheme (every URL comes from
`rest_url()`), but the test setup has no wildcard DNS.

Everything is per site:

- **The preset setting.** Each site has its own **Settings → HTTP QUERY**, for that site's
  administrators. There is no network settings screen. A site's routes, content and caches are its
  own, so whether they accept `QUERY` is a site decision.
- **The self-test and Site Health checks** test the site you run them from.
- **The filters** run on each site's requests, so a network-wide policy is code: put the
  [`wp_http_query_presets` filter](#3-your-site-enables-a-preset) in a network mu-plugin to turn a
  preset on (or off, with `array()`) on every site, whatever each site's setting says.

## Data

One option, `wp_http_query_presets`, holding the enabled preset names. Deactivating keeps it;
uninstalling deletes it. On multisite, uninstalling (from **Network Admin → Plugins**) deletes it
from every site of the network, archived, spam and deleted sites included.
