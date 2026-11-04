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
  It is turned on with a checkbox on Settings → General, stored in the `wp_http_query_presets`
  option. The `wp_http_query_presets` filter overrides the setting.
- Only handlers that accept `GET` gain `QUERY`. Write handlers on the same route never do.
  The handler's `methods` is written back as a string, so Trac#65905 can't affect it on any core version.
- CORS: `QUERY` is added to `Access-Control-Allow-Methods`. On WordPress 7.2+ this goes through
  core's `rest_allowed_cors_methods` filter. On older versions, core's CORS handler is replaced.
- Form-encoded `QUERY` bodies are parsed into request params, like `POST`/`PUT`/`PATCH`/`DELETE`.
  JSON bodies already worked.
- An `Accept-Query` response header on routes that accept `QUERY`. It advertises
  `application/json` by default, which can be changed with the `wp_http_query_advertised_types` filter.
- A diagnostic route, `QUERY /wp-json/wp-http-query/v1/echo`, that reports how the request was parsed.
  Off by default; the `wp_http_query_register_demo_route` filter turns it on.
- `uninstall.php` deletes the `wp_http_query_presets` option (current site only on multisite).
  Deactivating keeps it.

[Unreleased]: https://github.com/moonmeister/wp-http-query/commits/main
