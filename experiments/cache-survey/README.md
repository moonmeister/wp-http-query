# Cache survey — what actually happens to a `QUERY` request?

**Run 2026-08-19.** Settles the threat model in
[ADR 0003](../../docs/decisions/0003-cache-safety-default.md).

## The question

RFC 10008 §2.7 requires a cache to key `QUERY` responses on the request content:

> The cache key for a QUERY request MUST incorporate the request content and related metadata.

No cache implements that today. ADR 0003 inferred from this that caches are "body-blind" and
would therefore key `QUERY` by URI alone, colliding distinct requests and serving one user's
result set to another.

**That inference was wrong**, and it was never checked. Body-blind is not the same as
will-cache-it-anyway. This survey checks.

## Method

Documentary, not empirical. Matrix Axis B (standing up real CDNs and WAFs) stays descoped per
[scope.md](../../docs/scope.md) §6 — but reading default configurations and cache source is
cheap, and it is evidence rather than assumption.

Every row below is the current default configuration or the current source, read directly.
**Caveat, stated once and applying to every row:** these are *defaults* on *current* versions.
Custom VCL, a changed `proxy_cache_methods`, or an old release can all differ. This bounds the
out-of-the-box behavior, not the entire deployed fleet.

## Result — edge and proxy caches

**No shipping edge cache will cache a `QUERY` response. Not one.**

| System | Behavior | Source |
| --- | --- | --- |
| **Varnish** | `synth(501)` + `Connection: close`. **The request never reaches the origin** | `bin/varnishd/builtin.vcl`, `vcl_req_method` |
| **nginx** | `proxy_cache_methods` defaults to `GET HEAD`, and the accepting bitmask contains only `GET`, `HEAD`, `POST` — so `QUERY` **cannot be made cacheable even deliberately** | `ngx_http_upstream_cache_method_mask`, `src/http/ngx_http_upstream.c` |
| **Apache `mod_cache`** | `default:` → *"Method '%s' not cacheable by mod_cache, ignoring"* → `DECLINED`. Only `M_GET` proceeds; `PUT`/`POST`/`DELETE` invalidate | `modules/cache/mod_cache.c`, `cache_quick_handler` and `cache_handler` |
| **Squid** | `default: return false` — *"RFC 2616 defines all unregistered or unspecified methods as non-cacheable"* | `src/http/RequestMethod.cc`, `respMaybeCacheable()` |
| **Fastly** | GET / HEAD / PURGE trigger `lookup`; *"all other methods will cause a `pass`"* | boilerplate VCL, `vcl_recv` reference |
| **Cloudflare** | *"Cloudflare does not cache the resource when… The HTTP request method is anything other than a `GET`"* | Default Cache Behavior docs |

And the spec agrees. **RFC 9111 §3** — a cache MUST NOT store a response unless *"the request
method is understood by the cache"*, where understood means *"it recognizes it and implements
all specified caching-related behavior."* A cache that recognized `QUERY` but skipped §2.7's
body keying has, by definition, **not** understood it — so the standard already forbids exactly
the failure mode ADR 0003 was built around.

Note the two distinct safety mechanisms, because they fail differently:

- **Allowlist** (`nginx`, `mod_cache`, Squid, Fastly, Cloudflare) — a new method is
  non-cacheable by default. Safe as methods are added.
- **Rejection** (Varnish) — a new method is not merely uncached, it is **refused with a 501**.
  Safe for caching, but an *availability* problem: `QUERY` cannot traverse a stock Varnish at
  all.

## Result — WordPress page caches

This is where the risk actually lives. These run in PHP, inside or ahead of WordPress, and
**none of them reads `Cache-Control`** on the write path.

| Plugin | Method check | Shape | REST excluded? |
| --- | --- | --- | --- |
| **Batcache** | `in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ) )` | allowlist | n/a — and the method is **in the cache key**, so safe twice over |
| **LiteSpeed Cache** | `'GET' !== $method && 'HEAD' !== $method` → `_no_cache_for()` | allowlist | safe |
| **WP Super Cache** | `in_array( …, array( 'POST', 'PUT', 'DELETE' ) )` — **`QUERY` passes** | **denylist** | ✅ yes — `REST_REQUEST` sets `is_rest`, then *"REST API detected. Caching disabled."* Saved by the REST check, not the method check. **The cache key does not include the method** |
| **W3 Total Cache** | `in_array( …, array( 'DELETE','PUT','OPTIONS','TRACE','CONNECT','POST' ) )` — **`QUERY` passes** | **denylist** | ⚠️ **only while `pgcache.rest !== 'cache'`.** Pro users who enable REST caching get a dedicated `rest` cache group, and `QUERY` clears the method gate |

**Exact locators**, re-verified 2026-08-19 against the wordpress.org downloads, for the two
denylists — these are what the upstream reports in
[`docs/submissions/`](../../docs/submissions/) cite:

| Claim | File | Version |
| --- | --- | --- |
| WPSC read-path denylist | `wp-cache-phase1.php:151` | WPSC 3.1.1 |
| WPSC write-path branches | `wp-cache-phase2.php:2096-2103` | WPSC 3.1.1 |
| WPSC REST exclusion | `wp-cache-phase2.php:2006` (sets `is_rest`), `:2145` (refuses) | WPSC 3.1.1 |
| WPSC cache key — host, port, URI, gzip, cookies, `Accept`; **no method, no body** | `get_wp_cache_key()`, `wp-cache-phase2.php:34-53` | WPSC 3.1.1 |
| W3TC denylist | `PgCache_ContentGrabber.php:662`, in `_can_read_cache()` | W3TC 2.10.5 |
| W3TC REST exclusion, skipped when `pgcache.rest === 'cache'` | `PgCache_ContentGrabber.php:769-775` | W3TC 2.10.5 |
| W3TC `rest` cache group, Pro only | `get_cache_group_by_uri()`, `:1467-1473` | W3TC 2.10.5 |
| W3TC key extension — `useragent`, `referrer`, `cookie`, `encryption`; **no method, no body** | `_get_page_key()`, `:1672`, `:1710-1716` | W3TC 2.10.5 |
| W3TC honors `DONOTCACHEPAGE` | `:762-767` | W3TC 2.10.5 |

### The one residual vector

**W3 Total Cache Pro with REST caching explicitly enabled.** There, a `QUERY` clears the method
denylist, reaches the `rest` cache group, and is keyed without the body — so distinct `QUERY`
requests collapse onto one URI-keyed entry, and onto the plain `GET` entry with them.

**Severity: cache confusion, not disclosure.** Every parameter expressible in a `QUERY` body is
equally expressible in a `GET` query string; the route, the handler, and the permission callback
are identical; and W3TC does not cache requests carrying logged-in cookies. So an attacker can
poison a URI-keyed entry with a response **they could already have fetched anonymously** —
other anonymous users get wrong public data. Real, and worth fixing. Not a privilege boundary.

### The structural problem is the denylist, not `QUERY`

WPSC and W3TC both **default-allow any method they have not heard of**. `QUERY` is simply the
first new method in a long time; the next one inherits the same hole. That makes the fix
worth filing upstream on its own merits, independent of whether `QUERY` ever ships.

## `DONOTCACHEPAGE` — the switch core does not use

All four plugins honor the `DONOTCACHEPAGE` constant. It is the de facto WordPress convention
for "do not page-cache this response," and it is the only mechanism that reaches the layer
where the residual vector lives — `Cache-Control` does not.

**WordPress core references `DONOTCACHEPAGE` zero times.** Verified by grep across `src/`.

So *if* a mitigation were wanted, `DONOTCACHEPAGE` would be the effective one and a
`Cache-Control` header would not. ADR 0003 proposed the header. That is recorded here because
it is the kind of mitigation that looks responsible and does nothing.

## What this settles

- ADR 0003's threat model, as written, **does not describe reality**. Edge caches are safe by
  construction, not by luck.
- The residual hazard is one opt-in configuration of one plugin, and it is confusion rather
  than disclosure.
- Any core-side default — `no-store`, `private, no-cache`, or a capability signal — is
  disproportionate to that, and would be the first method-specific cache behavior in the REST
  API.
- Two upstream plugin issues are worth filing regardless: WPSC and W3TC should switch to
  allowlists. **Drafted** in [`docs/submissions/`](../../docs/submissions/) — not filed;
  see [#39](../../../../issues/39).

→ [ADR 0003](../../docs/decisions/0003-cache-safety-default.md) for the decision this produced.

## Reproducing

Nothing to run. Every claim above is a direct read of a public default config or source file;
the "Source" column names the file and function. Re-check against current releases before
citing on Trac — `builtin.vcl` in particular has changed shape across Varnish majors (older
releases `pipe`d unknown methods rather than returning 501; either way, never cached).
