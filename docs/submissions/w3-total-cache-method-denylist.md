# Draft — W3 Total Cache: method denylist + REST caching = URI-keyed `QUERY` responses

**Status: written and parked. Do not file yet.** Filing triggers on the feature plugin's
publication ([#23](../../../../issues/23)) or a core release carrying `QUERY` dispatch
([#11](../../../../issues/11)) — whichever lands first. Alex files; see
[#39](../../../../issues/39).

- **Target:** [`BoldGrid/w3-total-cache`](https://github.com/BoldGrid/w3-total-cache) — issues
  enabled (`W3EDGE/w3-total-cache` redirects here)
- **Type:** issue, not PR
- **Read against:** W3 Total Cache **2.10.5** (wordpress.org download, 2026-08-19)
- **Severity:** **cache confusion, not disclosure.** Say so explicitly — see below

**Before filing:** re-read `PgCache_ContentGrabber.php` against the then-current release — every
line number below will have moved, and the `pgcache.rest` gating may have changed shape. Then
decide whether this goes to the public tracker or to their security contact.
Our read is **public tracker** — the reasoning is in "On disclosure" at the bottom, and it is
worth a second opinion rather than a default.

---

## Title

> Method check is a denylist: with `pgcache.rest = cache`, unknown methods land in the `rest`
> cache group keyed by URI alone

## Body

`PgCache_ContentGrabber.php:662`, in `_can_read_cache()`, decides cacheability by listing the
methods it will *not* serve from cache:

```php
if ( in_array( strtoupper( $request_method ), array( 'DELETE', 'PUT', 'OPTIONS', 'TRACE', 'CONNECT', 'POST' ), true ) ) {
	$this->cache_reject_reason = sprintf( 'Requested method is %s', $request_method );
	return false;
}
```

Six methods are named. Anything else — including any method defined after this list was
written — passes.

That is safe while REST requests are excluded, which they are by default at
`PgCache_ContentGrabber.php:769-775`:

```php
if ( 'cache' !== $this->_config->get_string( 'pgcache.rest' ) ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$this->cache_reject_reason = 'REST request';
```

**When an administrator sets `pgcache.rest` to `cache`,** that guard is skipped, and
`get_cache_group_by_uri()` (`:1467-1473`) routes REST requests into a dedicated `rest` group
for Pro installs. At that point the method gate is the only thing left, and it is a denylist.

### The concrete case

The IETF published **[RFC 10008](https://www.rfc-editor.org/rfc/rfc10008.html)**, defining
`QUERY`: a safe, idempotent, **cacheable** read that carries its parameters in a **request
body** rather than the query string. RFC 10008 §2.7 requires that a cache key
*"MUST incorporate the request content and related metadata."*

W3TC's page key does not incorporate the body. `_get_page_key()` (`:1672`) extends the URL part
with `useragent`, `referrer`, `cookie` and `encryption` (`:1710-1716`) — no method, no body. So
with REST caching enabled:

- `QUERY /wp-json/wp/v2/posts` with body `{"search":"alpha"}` → stored under the URI
- `QUERY /wp-json/wp/v2/posts` with body `{"search":"beta"}` → **served the "alpha" response**
- A plain `GET /wp-json/wp/v2/posts` → collides with both

### Severity: confusion, not disclosure

Stating this up front because it bounds the fix's urgency:

- Every parameter expressible in a `QUERY` body is equally expressible in a `GET` query string
- The route, the handler and the permission callback are identical either way
- W3TC does not cache requests carrying logged-in cookies

So the worst case is an anonymous visitor poisoning a URI-keyed entry with a response **they
could already have fetched anonymously**. Other anonymous visitors get wrong public data. That
is a correctness bug worth fixing, not a privilege boundary crossing.

Note also that WordPress core does not ship `QUERY` support today — this is reachable via any
plugin that registers a route with `'methods' => 'QUERY'`, which works on stock core right now.

### Suggested fix

Invert to an allowlist:

```php
if ( ! in_array( strtoupper( $request_method ), array( 'GET', 'HEAD' ), true ) ) {
	$this->cache_reject_reason = sprintf( 'Requested method is %s', $request_method );
	return false;
}
```

`HEAD` already has its own handling immediately below at `:668-676`, so it may want to stay
listed here and be rejected there, or move entirely — maintainers' call.

If instead you want to *support* `QUERY` properly rather than reject it, the requirement is
RFC 10008 §2.7: hash the request body into the page key. That is a real feature and a much
larger change; rejecting is the correct default until then, and rejecting is what RFC 9111 §3
requires of a cache that has not implemented the method's caching behavior.

### The structural point

`QUERY` is the trigger, but not really the bug. A denylist default-allows every method that
does not exist yet, so each new one has to be caught by hand. An allowlist is safe by
construction and does not need revisiting.

### Context

Found while surveying what actually happens to a `QUERY` request across edge and page caches:
[experiments/cache-survey](https://github.com/moonmeister/wp-http-query/tree/main/experiments/cache-survey).
Every edge cache surveyed — Varnish, nginx, `mod_cache`, Squid, Fastly, Cloudflare — refuses to
store `QUERY` responses, five by allowlist and Varnish by returning `501`. W3TC with REST
caching enabled was the **only** layer of ten where the response gets stored.

Note that a `Cache-Control` header from WordPress would not change this: the decision here
happens in PHP on the read path, before the response exists. `DONOTCACHEPAGE` (honored at
`:762-767`) is the only signal that reaches it, and WordPress core defines it nowhere.

## Notes for Alex, not for the issue

### On disclosure

Our read is that this belongs on the **public tracker**, not a security contact:

- It requires a **non-default, admin-enabled** Pro setting
- It requires a plugin that registers a `QUERY` route — a set that is currently close to empty
- The impact is one anonymous visitor seeing another anonymous visitor's public response

Publishing the reasoning is also the point of the exercise: the survey argues in public that
`QUERY` is cache-safe, and quietly omitting the one place it is not would undercut that. If
you would rather start privately, the report stands unchanged — send it and offer to file
publicly once they have looked.

### Other

- File this and the WPSC report together and cross-link them. Two denylists is a pattern; one
  is an anecdote
- Do **not** open a PR on either. The allowlist contents are a product decision, and an
  unsolicited PR narrows the conversation to our guess at the right list
- The `pgcache.rest = cache` path is read from source only. Neither the setting nor the
  collision has been reproduced on a live install. **Say so in the issue** if you file it
  without reproducing first — the survey is documentary throughout and has been labelled that
  way everywhere else
