# Changelog

All notable changes to the WP HTTP QUERY plugin (`plugin/`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `QUERY` (RFC 10008) support for the WordPress REST API on stock core, with no core patch.
  Requires WordPress 7.0 or later and PHP 8.0 or later.
- `QUERY` is opt-in. It reaches a route in three ways only:
  - The route registers it. `WPHttpQuery\QUERYABLE` (`'QUERY'`) stands in for core's future
    `WP_REST_Server::QUERYABLE`.
  - A site lists the route key in the `wp_http_query_routes` filter. Keys must match exactly.
  - A site enables a preset.
- The `core-collections` preset: `/wp/v2/posts`, `/wp/v2/pages`, `/wp/v2/media`,
  `/wp/v2/comments`, `/wp/v2/categories`, `/wp/v2/tags`, `/wp/v2/users` and `/wp/v2/search`.
  It is turned on with a checkbox on Settings → HTTP QUERY, stored in the `wp_http_query_presets`
  option. The `wp_http_query_presets` filter overrides the setting.
- A Settings → HTTP QUERY page, linked from the plugin's row on the Plugins screen. Besides the
  preset checkbox, it lists every route that accepts `QUERY` and why: a preset, the
  `wp_http_query_routes` filter, or the route registering it itself.
- Only handlers that accept `GET` gain `QUERY`. Write handlers on the same route never do.
  The handler's `methods` is written back as a string, so Trac#65905 can't affect it on any core version.
- CORS: `QUERY` is added to `Access-Control-Allow-Methods`. On WordPress 7.2+ this goes through
  core's `rest_allowed_cors_methods` filter. On older versions, core's CORS handler is replaced.
- Form-encoded `QUERY` bodies are parsed into request params, like `POST`/`PUT`/`PATCH`/`DELETE`.
  JSON bodies already worked.
- An `Accept-Query` response header on routes that accept `QUERY`. It advertises
  `application/json` by default, which can be changed with the `wp_http_query_advertised_types` filter.
- A diagnostic route, `QUERY /wp-json/wp-http-query/v1/echo`, that reports how the request was parsed.
  Admins only (`manage_options`) by default; the `wp_http_query_register_demo_route` filter makes it
  public.
- A self-test: **Test your server** on Settings → HTTP QUERY checks, from the browser and from the
  server (loopback), that `QUERY` reaches WordPress with its method and body intact, and that no
  cache returns one `QUERY` body's response for another. Each result gives the HTTP status and a
  likely cause: nginx at the bare root (`405`), a `GET`/`POST`-only rule (`403`), Varnish 8.0 – 9.0.x
  or `php -S` (`501`), a redirect, a changed method, a lost body, or a cache, named from its
  response headers where possible.
- Two Site Health tests running the server-side checks: **HTTP QUERY requests reach WordPress** and
  **No cache stores HTTP QUERY responses**.
- A public route for the cache check, `QUERY /wp-json/wp-http-query/v1/self-test/fingerprint`, that
  answers with a SHA-256 of the request body and a fresh value, and echoes nothing.
- `uninstall.php` deletes the `wp_http_query_presets` option (current site only on multisite).
  Deactivating keeps it.

[Unreleased]: https://github.com/moonmeister/wp-http-query/commits/main
