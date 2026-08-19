# Draft — WP Super Cache: method denylist default-allows unknown HTTP methods

**Status: written and parked. Do not file yet.** Filing triggers on the feature plugin's
publication ([#23](../../../../issues/23)) or a core release carrying `QUERY` dispatch
([#11](../../../../issues/11)) — whichever lands first. Alex files; see
[#39](../../../../issues/39).

- **Target:** [`Automattic/wp-super-cache`](https://github.com/Automattic/wp-super-cache) — issues enabled
- **Type:** issue, not PR. The fix is one line, but which methods belong on an allowlist is
  the maintainers' call, not ours
- **Read against:** WP Super Cache **3.1.1** (wordpress.org download, 2026-08-19)
- **Severity:** latent. Not exploitable today — see "Why this is not urgent" below

**Before filing:** re-read `wp-cache-phase1.php` against the then-current release — line numbers
will have moved — and check the issue tracker for an existing report of the same shape. Check
one thing in particular: **if the REST exclusion at `wp-cache-phase2.php:2145` has been narrowed
in the meantime, this report changes from "latent" to "live"** and needs rewriting, not just
re-numbering.

---

## Title

> Method check is a denylist, so any new HTTP method is cached by default

## Body

`wp-cache-phase1.php:151` decides whether a request is cacheable by listing the methods it
will *not* cache:

```php
if ( isset( $_SERVER['REQUEST_METHOD'] ) && in_array( $_SERVER['REQUEST_METHOD'], array( 'POST', 'PUT', 'DELETE' ), true ) ) {
	wp_cache_debug( 'Caching disabled for non GET request.' );
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', 1 );
	}
	return true;
}
```

The debug string says *"non GET request"*, which is what the check is clearly meant to do — but
the code says "not one of these three." `PATCH` already passes it, and so does any method
invented later.

The same shape repeats on the write path at `wp-cache-phase2.php:2096-2103`, as three
`elseif` branches for `POST`, `PUT` and `DELETE`.

### Why it matters now

The IETF published **[RFC 10008](https://www.rfc-editor.org/rfc/rfc10008.html)**, defining the
`QUERY` method: a safe, idempotent, **cacheable** read that carries a request body — for
searches too large or too structured for a query string. It is the first genuinely new
general-purpose method in a long time, and it is the awkward case for a denylist:

- It is a **read**, so nothing upstream rejects it as a write
- It is **cacheable** by spec, so a cache is right to want to store it
- Its parameters are in the **body**, so a URI-keyed cache cannot tell two requests apart

RFC 10008 §2.7 is explicit that the cache key *"MUST incorporate the request content."* WPSC's
key does not — `get_wp_cache_key()` (`wp-cache-phase2.php:34-53`) is built from host, port,
URI, gzip encoding, cookies and the `Accept` header. So two different `QUERY` bodies against
one URI would collide, and collide with the plain `GET` entry too.

### Why this is not urgent

**WPSC does not currently have this bug**, and that is worth saying plainly. A REST request
sets `$wp_super_cache_query['is_rest']` (`wp-cache-phase2.php:2006`) and caching is refused at
`wp-cache-phase2.php:2145` — *"REST API detected. Caching disabled."* Since `QUERY` in
WordPress will arrive through the REST API, the REST exclusion catches it.

But it is caught by a check about *where the request went*, not *what method it used*. If the
REST exclusion is ever narrowed — it is a reasonable thing to want to narrow, since plenty of
REST responses are public and cacheable — the method gap is uncovered underneath it.

### Suggested fix

Invert to an allowlist, matching what the debug message already claims:

```php
if ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
```

This is what Batcache and LiteSpeed Cache both do. Batcache additionally puts the method in
the cache key, which is the belt-and-braces version.

Two things worth deciding along the way, both maintainers' calls:

1. Whether `HEAD` belongs in the list here (it is currently cached, since it is not denied)
2. Whether the write-path branches at `wp-cache-phase2.php:2096-2103` should collapse into the
   same allowlist rather than staying enumerated

### Context

Found while surveying what actually happens to a `QUERY` request across edge and page caches:
[experiments/cache-survey](https://github.com/moonmeister/wp-http-query/tree/main/experiments/cache-survey).

The finding for the survey was the opposite of alarming — **no shipping edge cache stores a
`QUERY` response**: Varnish returns `501`, nginx's cache-method bitmask cannot even express it,
and `mod_cache`, Squid, Fastly and Cloudflare all allowlist. RFC 9111 §3 independently forbids
storing a response whose method the cache has not *understood*.

Which is why this is filed as a structural note rather than a vulnerability report. WordPress
core has not shipped `QUERY` support and may never; this is worth fixing on its own merits,
whether or not that happens.

## Notes for Alex, not for the issue

- Tone check: WPSC is *not* broken today. Leading with the REST exclusion rather than burying
  it is the honest framing and also the one likely to get a fix merged
- The equivalent W3TC report is the one with a live vector — see
  [`w3-total-cache-method-denylist.md`](w3-total-cache-method-denylist.md). Filing both the
  same day and cross-linking them makes the "denylists age badly" point better than either
  does alone
- No AI-use disclosure convention exists on either tracker; core's PR-template rule does not
  reach here. Your call
