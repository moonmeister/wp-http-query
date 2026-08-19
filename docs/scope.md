# Scope: HTTP QUERY support in WordPress

**Status:** scoping / pre-patch
**Core baseline:** `7.2-alpha-63166-src` @ `e7739d5414`
**Trunk references verified:** 2026-08-16

Ecosystem support status lives in [ecosystem.md](ecosystem.md) — it is time-sensitive and
tracked separately. Contested design calls live in [decisions/](decisions/).

---

## 1. Purpose

Make WordPress ready to accept, dispatch, and emit HTTP `QUERY` requests as defined by
[RFC 10008](https://www.rfc-editor.org/rfc/rfc10008.html), so that WordPress is not the
blocker when the surrounding ecosystem finishes shipping support.

This is a **readiness** project, not an adoption project. We are explicitly not waiting for
the ecosystem, and explicitly not claiming it is ready.

### Why QUERY

`QUERY` is a safe, idempotent method that carries a request body. It exists to solve the
problem WordPress currently solves badly: read operations whose parameters are too large, too
structured, or too sensitive for a URL query string.

Today those become `POST` requests, which sacrifices everything the method system exists to
communicate — a `POST` is not safe, not idempotent, not automatically retryable, and not
cacheable by default. `QUERY` restores those properties. RFC 10008 §2:

> QUERY requests are safe with regard to the target resource; that is, the client does not
> request or expect any change to the state of the target resource. […] Furthermore, QUERY
> requests are idempotent; they can be retried or repeated when needed, for instance, after
> a connection failure.

### Spec status

Settled. `draft-ietf-httpbis-safe-method-w-body` was published at revision -14 as **RFC 10008,
"The HTTP QUERY Method"** — IETF Standards Track (Proposed Standard), June 2026.

- `QUERY` is in the [IANA HTTP Method Registry](https://www.iana.org/assignments/http-methods/http-methods.xhtml):
  `QUERY,yes,yes,"[RFC10008, Section 2]"` — safe, idempotent.
- `Accept-Query` is in the [IANA HTTP Field Name Registry](https://www.iana.org/assignments/http-fields/http-fields.xhtml),
  status `permanent`, Structured Type `List`.
- Two errata (9013, 9016). Both cosmetic fixes to appendix examples. No normative change.

Cite **RFC 10008**, never the draft name. Write **Proposed Standard**, never "internet
standard" — that distinction will be checked in review.

---

## 2. Current state of trunk

Verified directly against the core fork. Line numbers are accurate as of the baseline commit
and **will drift** — re-verify before filing.

### What already works

WordPress's REST routing layer is method-agnostic to a degree that surprised us. No core patch
is required to register or dispatch a `QUERY` route:

| Component          | File:line                            | Behavior                                                                                                                                                      |
| ------------------ | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Route registration | `class-wp-rest-server.php:1007`      | `explode( ',', $handler['methods'] )` — any comma-separated method string. No allowlist.                                                                      |
| Method setting     | `class-wp-rest-request.php:149-151`  | `set_method()` is `strtoupper()` and nothing else. No validation.                                                                                             |
| Route matching     | `class-wp-rest-server.php:1190-1196` | Plain `empty( $handler['methods'][ $checked_method ] )` array-key lookup. Only special case is the HEAD→GET fallback.                                         |
| Raw body read      | `class-wp-rest-server.php:1966-1977` | `get_raw_data()` calls `file_get_contents( 'php://input' )` unconditionally, no method check.                                                                 |
| JSON body parsing  | `class-wp-rest-request.php:364-368`  | `parse_json_params()` runs unconditionally; `JSON` is prepended to the parameter order whenever `is_json_content_type()` is true — **independent of method**. |
| `Allow` header     | `rest-api.php:883-913`               | `rest_send_allow_header()` derives entirely from registered route methods. Generic, needs no change.                                                          |

**Consequence:** a `QUERY` route with a JSON body already parses parameters correctly on stock
trunk. This makes the feature-plugin path viable — we can demonstrate the feature end-to-end
before asking anyone to change core, which materially lowers the bar for acceptance.

### The three gaps

| #   | File:line                           | Current                                                                                                                                 | Problem                                                                                                                                                                 |
| --- | ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `rest-api.php:814`                  | `header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );`                                                     | Hardcoded, no filter at that point. Every cross-origin `QUERY` preflight fails.                                                                                         |
| 2   | `class-wp-rest-server.php:24-56`    | `READABLE='GET'`, `CREATABLE='POST'`, `EDITABLE='POST, PUT, PATCH'`, `DELETABLE='DELETE'`, `ALLMETHODS='GET, POST, PUT, PATCH, DELETE'` | No constant includes `QUERY`. `ALLMETHODS` routes will not answer it. See [ADR 0002](decisions/0002-allmethods-vs-queryable.md).                                        |
| 3   | `class-wp-rest-request.php:377-380` | `$accepts_body_data = array( 'POST', 'PUT', 'PATCH', 'DELETE' );`                                                                       | `QUERY` excluded, so a form-encoded `QUERY` body is parsed into `$this->params['POST']` by `parse_body_params()` but never added to the lookup order. Silent data loss. |

Gap 3 only affects non-JSON bodies. The fix is one line; the design decision behind it
([ADR 0001](decisions/0001-query-body-media-type.md)) is not.

> ✅ **Both halves of that sentence measured 2026-08-16** against unmodified trunk, no patch.
> `get_parameter_order()` adds the `JSON` source with **no method check**, so a `QUERY` with
> `Content-Type: application/json` already resolves params in the order
> `JSON > GET > URL > defaults` and works correctly end to end — dispatched at the real posts
> controller, a `{"search":"…"}` body filters the collection and a `{"per_page":9999}` body is
> rejected with a `400`. The `$accepts_body_data` allowlist gates only the form-encoded `POST`
> source, so a form-encoded `QUERY` resolves as `GET > URL > defaults` and **returns the full
> unfiltered collection with a `200`.**
>
> That makes gap 3 the only one of the three whose failure mode is a silent wrong answer rather
> than a refusal, which argues for sequencing it **first** in the patch.
> Reproductions: [`experiments/blast-radius/`](../experiments/blast-radius/).

**Gap 1 has prior art, and reading it in full turned it from unfavorable to favorable.** All
tickets below were read in a browser on 2026-08-16 and are verified.

- **[#46992](https://core.trac.wordpress.org/ticket/46992)** — "Add a filter which allows the
  HTTP headers for REST API Endpoints to be changed" (sudar, 2019-04-19), proposing a
  `wp_rest_headers` filter. **Closed `invalid`** 2019-07-08 by adamsilverstein, citing
  `rest_post_dispatch`.

  The summary "core declined to add a filter" is true but misleading, and the comment thread is
  the most useful thing found so far:

  - **comment 3 (adamsilverstein)** — states the goal outright: *"the goal here is to modify the
    CORS headers in responses from WP REST API Endpoints. It doesn't look like this is possible
    using the `rest_post_dispatch` filter."*
  - **comments 5 and 7 (TimothyBlynJacobs)** — raises our exact objection, twice: *"doesn't
    `rest_send_cors_headers()` use a regular header function call, it doesn't go through
    `WP_REST_Server`?"* and *"The headers sent by `rest_send_cors_headers` won't be filterable
    [by] that proposed filter."*
  - **comment 6 (sudar)** — confirms the only workaround is to `remove_filter()`
    `rest_send_cors_headers` from `rest_pre_serve_request` and reimplement it.
  - **comment 8 (aaemnnosttv)** — gives the generic `rest_post_dispatch` answer, which is
    correct for ordinary response headers and does not address the CORS ones at all.
  - **comment 9** — closes as `invalid`, citing comment 8.

  So the recorded resolution answers a different question than the one the ticket was about,
  and **the CORS-specific objection was raised by a core committer and never rebutted.** Nobody
  argued that CORS headers *should not* be overridable; the thread simply lost the thread.
  **This is not a rejected approach we have to work around — it is an unfinished one, with a
  committer already on record agreeing with us.**

- **[#43428](https://core.trac.wordpress.org/ticket/43428)** — "Improve CORS headers sent to
  REST Api requests" (andrei.igna, 2018). **Still open**, milestone Awaiting Review. Asks to
  consolidate CORS header emission into `rest_send_cors_headers` *"making it easier to control
  the CORS headers from only one place, with one hook"*, drop `OPTIONS` from
  `Access-Control-Allow-Methods`, send the preflight headers only on `OPTIONS`, and add
  `Access-Control-Max-Age`. Author reports it tested across ~500 sites. Substantive discussion
  stopped in 2018; everything since is field churn.

  > ⚠️ **Corrected 2026-08-17, after reading the ticket in full.** An earlier draft called this
  > "our gap-1 ask, already filed" and the best venue for
  > [#16](https://github.com/moonmeister/wp-http-query/issues/16). **Both were wrong.** All five
  > of its proposals are about CORS spec-correctness and preflight performance — *which* headers
  > go out, *when*, and *from where*. None concerns overriding the value, which is our ask. The
  > "one place, with one hook" phrase is the rationale for its first item only (consolidating
  > where `Access-Control-Allow-Headers` is emitted from), not a request for overridability.
  >
  > Its five comments contain one substantive exchange — schlessera questioning whether `OPTIONS`
  > belongs in the list, andrei.igna answering — then field churn. No patch in 8 years, no
  > committer engagement on the substance.
  >
  > **Gap 1 went to the reopened [#46992](https://core.trac.wordpress.org/ticket/46992) instead.**
  > The two are compatible: if #43428's third proposal lands, the clobber fix simply applies on
  > the `OPTIONS` path.

- **[#38546](https://core.trac.wordpress.org/ticket/38546)** — "REST API: Add PATCH to CORS
  allowed methods" (jnylen0, 2016). **Fixed in 4.7**, changeset
  [[39042](https://core.trac.wordpress.org/changeset/39042)], committed by pento, props jnylen0.
  Commit message: *"Editable resources in the REST API accept the PATCH method, but the CORS
  headers don't mention it."* That is exactly our argument with the verb swapped. No objection
  was raised to the CORS change itself and it landed in days. **Clean precedent, cleanly
  applicable.**

- **[#57752](https://core.trac.wordpress.org/ticket/57752)** — "Improve
  `rest_(allowed|exposed)_cors_headers` filters" (bor0). **Fixed in 6.3**, changeset
  [[56096](https://core.trac.wordpress.org/changeset/56096)], owner kadamwhite. Passed `$request`
  into both sibling CORS filters — and **again left `Access-Control-Allow-Methods` untouched.**
  A second, recent instance of the same omission, which is the strongest available evidence that
  it is oversight rather than policy.

**Nobody has reported the clobber.** Searches for `rest_send_cors_headers`,
`Access-Control-Allow-Methods`, `rest_pre_serve_request` header replacement, and
`rest_allowed_cors_headers` return the tickets above and nothing describing the ordering
problem. The mechanism in [#16](https://github.com/moonmeister/wp-http-query/issues/16) is a
genuinely new finding. What is *not* new is "`rest_post_dispatch` does not reach the CORS
headers" — TimothyBlynJacobs said that in 2019, and citing him is stronger than asserting it
ourselves.

But the `rest_post_dispatch` advice **does not work**, which is verified against
`7.2-alpha-63166`: `send_header()` calls `header()` with no `$replace` argument
(`class-wp-rest-server.php:1930`), so PHP defaults to replacing. `rest_post_dispatch` fires at
`:464` and its headers go out at `:473-474`; `rest_send_cors_headers()` then runs on
`rest_pre_serve_request` at `:516` and overwrites them. Two lines below the hardcoded header,
`rest-api.php:816` sends `Vary: Origin` with an explicit `$replace = false`.

Do not over-read that asymmetry. It was introduced by
[#38060](https://core.trac.wordpress.org/ticket/38060) (4.7, changeset
[[38806](https://core.trac.wordpress.org/changeset/38806)], jorbin), a ticket about sites behind
Varnish — and `Vary` is a list header that *must* be appended rather than replaced, so
`$replace = false` there is a local correctness requirement, not a considered stance on
overridability. The honest reading is that nobody has ever thought about replacement semantics
in this function, which is still the point.

The honest limit of that claim: a plugin *can* still win by hooking `rest_pre_serve_request` at
priority 11+. So the defensible statement is that **#46992's recorded resolution is wrong and
the mechanism that works is undocumented and order-dependent** — not that overriding is
impossible. Tracked as [#16](https://github.com/moonmeister/wp-http-query/issues/16) (fix the
clobber, independent of `QUERY`) and
[#35](https://github.com/moonmeister/wp-http-query/issues/35) (add `QUERY` to the list, per the
#38546 precedent).

Also relevant: 5.5.0 made the two sibling CORS list headers filterable —
`rest_exposed_cors_headers` (`class-wp-rest-server.php:408`) and `rest_allowed_cors_headers`
(`:434`) — and missed this one because it lives in a different function.
`rest_send_cors_headers()` has **no test coverage at all.**

> ✅ All ticket contents above were read directly in a browser on 2026-08-16, including full
> comment threads. Trac still 403s automated fetches — it serves a proof-of-work challenge, and
> once solved interactively the `_hcc` cookie makes the rest of the session ordinary browsing.
> Re-verification therefore needs a human-driven browser, not a fetch tool.

### The existing tunnel

`serve_request()` already honors `$_GET['_method']`, falling back to
`$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']` (`class-wp-rest-server.php:384-395`). Because
`set_method()` has no allowlist, this works **today on stock infrastructure**:

```http
POST /wp-json/namespace/v1/search HTTP/1.1
X-HTTP-Method-Override: QUERY
Content-Type: application/json

{"filter": {...}}
```

Ship this as a documented fallback. Be unambiguous that it is a **tunnel, not the method**:
every cache, WAF, and proxy on the path sees `POST`, so RFC 10008 §2.7 cacheability and
automatic-retry safety do not apply. It buys ergonomics and nothing else.

Note the interaction at `class-wp-rest-server.php:489-493`: when the override is used and the
response is 4xx, no-cache headers are forced. `QUERY` work must not regress that.

---

## 3. Scope

### In scope

- **Inbound (server):** accepting and dispatching `QUERY` on the REST API — body parsing, CORS
  preflight, `Allow`/`Accept-Query` advertisement. ~~Cache-safety headers~~ — removed
  2026-08-19, [ADR 0003](decisions/0003-cache-safety-default.md) option C.
- **Outbound (client):** `WP_Http` / `wp_remote_request()` and the bundled Requests library
  sending `QUERY` as a first-class, documented method.
- **JS client:** `@wordpress/api-fetch` emitting `QUERY` with a body through its full
  middleware chain.
- **Cache safety:** ~~ensuring WordPress does not enable cache poisoning by body-blind
  intermediaries~~ — **resolved 2026-08-19 with no core work.** No shipping cache stores a
  `QUERY` response; RFC 9111 §3 forbids it for any cache that has not implemented §2.7 keying.
  What remains is *reporting* two WordPress page-cache method denylists upstream
  ([#39](../../issues/39)), which is ecosystem work, not core. See §4.1.
- **Empirical test matrix:** proving the request body reaches PHP across real deployments (§6).

### Out of scope

- Converting any existing core endpoint to `QUERY` — see "Capability, not adoption" below.
- Implementing a query language. Not building `application/jsonpath` or `application/sql`
  support — see [ADR 0001](decisions/0001-query-body-media-type.md).
- Building a body-aware HTTP cache. That is the intermediary's job (§4).
- Lobbying nginx, Cloudflare, or hosts. We track their status; we do not gate on it.
- Browser or Fetch-standard changes.

### Capability, not adoption

This distinction gets misread as "you still could not send a `QUERY` request," so state it
precisely. With this work done:

|                                                                      | Result                                                                                                                    |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| A route registered with `'methods' => 'QUERY'` (yours or a plugin's) | **Works.** Already works on stock core today — see §2.                                                                    |
| An existing core endpoint, e.g. `QUERY /wp-json/wp/v2/posts`         | **Decided by [ADR 0002](decisions/0002-allmethods-vs-queryable.md).** Today: **404 `rest_no_route`** — "No route was found matching the URL and request method." Not a 405. |
| A route registered with `ALLMETHODS`                                 | **Decided by [ADR 0002](decisions/0002-allmethods-vs-queryable.md).**                                                     |

Those last two rows are why this scope line and ADR 0002 are the same decision viewed from two
angles. Folding `QUERY` into `ALLMETHODS` — or into `READABLE` — would make every route using
that constant, across core _and every plugin_, begin answering `QUERY` with handlers never
written for it. That is migration, performed implicitly across the ecosystem.

> ⚠️ **Revised 2026-08-19.** This paragraph used to end "…and a body they will not have parsed,"
> and concluded that declining to migrate anything is what makes opt-in the leaning. **The
> body clause was false.** `parse_body_params()` already runs for `QUERY` on unmodified trunk
> (`class-wp-rest-request.php:373-375`); the parsed body sits in `$this->params['POST']` and is
> only left out of the lookup order at `:377`. Once gap 3 is fixed, an existing handler reads a
> `QUERY` body correctly without changes, because handlers read `$request['x']` rather than
> `$_GET`.
>
> With that gone, the case against migrating is about **route authors' expectations**, not
> broken handlers — and ADR 0002's lean has reversed to **option D**, which migrates every
> `READABLE` route deliberately. The second row above is no longer "unchanged by this project."
> The open question for the community is **on by default versus opt-in**, and that is the
> question to put to them rather than to answer here.

### First adopter — deferred, not rejected

Shipping `QUERY` support that no core endpoint answers invites a fair review question: what
does this buy anyone? Two answers. Plugins get it immediately, which is how most WordPress
capability arrives. And a first-adopter core endpoint is real follow-up work, tracked here as
deferred rather than out of scope forever.

`/wp/v2/search` is the obvious candidate — literally a search endpoint constrained by URL
length, which is the problem `QUERY` exists to solve.

**It would not need a `v3`, and it is not a breaking change.** `register_rest_route()` takes a
list of handlers; the search controller currently registers one
(`class-wp-rest-search-controller.php:91-105`). Adding a second handler with
`'methods' => 'QUERY'`, the same `callback`, `permission_callback` and
`get_collection_params()` is purely additive — `get_items()` reads via `get_param()`, which
pulls from the merged parameter order and does not care whether values arrived as query string
or JSON body. The controller needs no changes. `wp/v2` versioning covers the resource
representation contract, not the method set; a previously-404 request beginning to succeed
does not break a client.

One real cost, and it is not BC:

- **Core's test suite hard-codes method sets.** Exact-match assertions exist in
  `rest-posts-controller.php:256,266`, `rest-attachments-controller.php:374,383`, and
  `rest-server.php:397,437,476,536`. A fixture update, not an API break — but see the note in
  ADR 0002, where this doubles as a free blast-radius measurement.

> ⚠️ **A second cost was listed here and is gone as of 2026-08-19.** It read: *"Caching stakes
> rise sharply — a core endpoint answering `QUERY` behind a body-blind cache is ADR 0003's
> poisoning scenario on exactly the kind of route a CDN is configured to cache. This, not
> versioning, is the reason to defer first-adopter work to its own ticket."* The survey in
> §4.1 shows no CDN will cache a `QUERY` response at all, so a first-adopter core endpoint
> carries no extra caching risk. **First-adopter work stays deferred on effort and reviewer
> scope, not on safety** — [#34](../../issues/34).

### Anticipated objection

Reviewers will ask "why now, when nothing supports it." The answer: the two things we control —
WordPress's REST layer and WordPress's HTTP client — are the only parts of the chain a
WordPress contributor can fix, and they are currently the parts that would still be broken
after everyone else shipped. Readiness is cheap now and expensive later.

---

## 4. Caching: what is ours and what is not

**Cache storage and key construction are external, but WordPress has four responsibilities,
and one of them is a security issue.**

RFC 10008 §2.7 places the hard requirement on the cache, not the origin:

> The response to a QUERY method is cacheable; a cache MAY use it to satisfy subsequent QUERY
> requests. The cache key for a QUERY request MUST incorporate the request content and related
> metadata.

WordPress is an origin server. It does not construct cache keys and should not try to. Varnish,
Cloudflare, Fastly, nginx, and host page caches own that. As of today **none implement
body-inclusive cache keys** — and, as it turns out, none will cache `QUERY` at all.

> ⚠️ **§4.1 below was falsified on 2026-08-19 and is preserved for the record.** The count in
> the heading above — "four responsibilities, one of them a security issue" — is now **three
> responsibilities and no security issue**. [ADR 0003](decisions/0003-cache-safety-default.md)
> is **Accepted, option C: core emits nothing method-specific for `QUERY`.**

### 4.1 Cache-poisoning defense — ~~ours, and load-bearing~~ not ours, and not a defect

**Original reasoning, wrong:** a cache that does not understand `QUERY` may fall back to keying
by URI alone. Two different request bodies to the same URI then collide, and one user's result
set is served to another. No `Vary` mechanism can protect against this — `Vary` operates on
request headers, and there is no `Vary: body`. Current behavior looked unsafe by default:
`rest_send_nocache_headers` defaults to `is_user_logged_in()`
(`class-wp-rest-server.php:487`), so **anonymous REST responses carry no explicit
`Cache-Control` at all**, leaving intermediaries to apply heuristic caching.

**Where it went wrong: body-blind is not the same as will-cache-it-anyway.** The scenario
requires a cache that stores a `QUERY` response. Surveyed, none do —
[`experiments/cache-survey/`](../experiments/cache-survey/):

| Layer | Behavior on `QUERY` |
|---|---|
| Varnish | `synth(501)` + `Connection: close`, before the origin sees it (`builtin.vcl`) |
| nginx | cache-method bitmask holds only `GET`/`HEAD`/`POST` — uncacheable even deliberately |
| Apache `mod_cache` | `default:` → *"not cacheable by mod_cache, ignoring"* → `DECLINED` |
| Squid | `default: return false` (`RequestMethod.cc`) |
| Fastly | *"all other methods will cause a `pass`"* |
| Cloudflare | *"does not cache… anything other than a `GET`"* |

RFC 9111 §3 also forbids the feared failure directly: a cache MUST NOT store unless the request
method is **understood**, defined as recognizing it *and* implementing all specified
caching-related behavior. A cache that recognized `QUERY` but skipped §2.7 keying has not
understood it.

The one live vector is WordPress-side — **W3 Total Cache Pro with REST caching enabled**, whose
method **denylist** default-allows `QUERY`. Severity is **confusion, not disclosure**: every
parameter expressible in a `QUERY` body is expressible in a `GET` query string. Reported
upstream rather than worked around in core.

A `Cache-Control` header — the obvious mitigation, and what this section proposed — would not
have reached that layer at all. It decides in PHP on the write path and honors
`DONOTCACHEPAGE`, which **core references zero times**.

See [ADR 0003](decisions/0003-cache-safety-default.md) for the decision and the reasoning error
behind the original text. The stray availability finding — Varnish's `501` — is a
**deployment** problem, not a caching one; it belongs with the hardening-allowlist 403 in
[#32](../../issues/32).

### 4.2 The `Location` indirection — ours, if adopted

RFC 10008 defines **two** distinct mechanisms that are easy to conflate:

- **§2.3 `Content-Location`** — a snapshot of the results just produced. May be temporary.
- **§2.4 `Location`** — an _equivalent resource_ (§2.2): a repeatable, GET-able query.

`Location` is the escape hatch. RFC co-author Julian Reschke, on
[mozilla/standards-positions#1430](https://github.com/mozilla/standards-positions/issues/1430):

> [it] would make caching completely trivial — it maps QUERY (with payload) to a server
> determined resource to which you can just send GET requests.

If a WordPress `QUERY` endpoint mints a canonical GET-able URI per query and returns it in
`Location`, we sidestep body-keyed caching entirely: clients and caches fall back to ordinary
`GET` caching. This is squarely origin-server work.

See [ADR 0004](decisions/0004-location-indirection.md).

### 4.3 `Accept-Query` advertisement — ours

Advertising which query formats a route accepts is origin-server work and the natural companion
to the existing `Allow` header logic. `Accept-Query` uses Structured Fields **List** syntax and
must not be parsed like the legacy `Accept` field despite the surface similarity.

### 4.4 Internal caching — unchanged

WordPress's object cache and transients for expensive queries are orthogonal. No
`QUERY`-specific work.

---

## 5. Client-side: sending QUERY

The project's framing is inbound, but WordPress is also an HTTP client, and that half is
nearly free.

### 5.1 The Requests library — already works, undocumented

Verified against the bundled copy (Requests 2.0.17, `src/wp-includes/Requests/src/`). `QUERY`
transits both transports correctly today:

- **Data placement** (`Requests.php:698-707`): `data_format` defaults to `'query'` only for
  `HEAD`, `GET`, `DELETE`; everything else — including `QUERY` — defaults to `'body'`.
  **Correct behavior for `QUERY` by accident.**
- **cURL transport** (`Transport/Curl.php:402-423`): the `switch` ends in
  `default: curl_setopt( CURLOPT_CUSTOMREQUEST, $options['type'] )` plus `CURLOPT_POSTFIELDS`
  when data is present. `QUERY` falls through correctly.
- **fsockopen transport** (`Transport/Fsockopen.php:204`): the request line is built with
  `sprintf( "%s %s HTTP/%.1F\r\n", $options['type'], ... )` — verb interpolated verbatim, body
  attached with `Content-Length`.

The gaps are contract and ergonomics, not capability:

1. **No `Requests::QUERY` constant.** `Requests.php:43-93` defines POST, PUT, GET, HEAD,
   DELETE, OPTIONS, TRACE, PATCH. No QUERY.
2. **`WP_Http`'s documented contract excludes it.** `class-wp-http.php:115-118` lists only
   GET/POST/HEAD/PUT/DELETE/TRACE/OPTIONS/PATCH and warns "Some transports technically allow
   others, but should not be assumed." So `QUERY` works but is _unsupported_.
3. **Redirect handling.** `class-wp-http.php:1083-1085` downgrades only `POST` to `GET` on
   redirect. A `QUERY` hitting a 30x is replayed as `QUERY` with its body. Arguably correct
   for an idempotent method, but untested and undocumented.

> **Process constraint:** `src/wp-includes/Requests/` is a vendored copy of the external
> [WpOrg/Requests](https://github.com/WpOrg/Requests) library. WordPress core does **not**
> accept direct patches to vendored dependencies — the `QUERY` constant and any transport
> changes must land upstream first and be pulled in on the next Requests sync.
>
> **Already in flight, and not by us.** [Requests#1075](https://github.com/WordPress/Requests/pull/1075)
> (author dingo-d, opened 2026-08-10) adds the constant and a helper method, implementing
> [#1074](https://github.com/WordPress/Requests/issues/1074). Maintainer jrfnl has triaged it
> into milestone 2.1.0. It is **CI-blocked on** the companion
> [test-server#13](https://github.com/RequestsPHP/test-server/pull/13).
>
> Our role here is review, testing, and unblocking — not authorship. Gaps 1 and 2 below are
> plausibly covered by #1075; **verify what it actually implements before duplicating effort.**
> Gap 3 (redirect handling) is not obviously in its scope.

### 5.2 `@wordpress/api-fetch` — genuinely unknown

Two research claims about `api-fetch` were refuted or split during verification. Treat its
behavior as **unestablished**. Test against package source, not docs, including the full
middleware chain (nonce, root URL, preloading, media upload) and the `data` shorthand.

Established browser constraints are in [ecosystem.md](ecosystem.md).

---

## 6. The test matrix

The gating deliverable. **The single most load-bearing unknown in the project was whether a
request body on a non-POST verb reaches PHP at all.** No documentation source answered it
during research, so it was tested empirically.

`get_raw_data()` reads `php://input` with no method check, so the WordPress layer is fine. The
risk was entirely below us: the SAPI, PHP's `enable_post_data_reading` machinery, and whether
`Content-Length` is honored for an unrecognized verb.

Harness and per-axis detail: [../matrix/README.md](../matrix/README.md).

- **Axis A — SAPI passthrough. ✅ Run 2026-08-16, and it passes.** The verb arrives intact,
  `php://input` is complete and byte-identical to 64 KiB, `Content-Length` is honored, and
  `$_POST` does not interfere (it is simply never populated). nginx, Apache on both SAPIs, and
  Caddy. See Q2.
- **Axis B — intermediaries. ❌ Out of scope**, descoped 2026-08-16.
- **Axis C — clients.** curl, `WP_Http` (both transports), browser `fetch()`, `api-fetch`,
  axios, undici, Guzzle. Partly outstanding; `WP_Http` cases belong with the feature plugin.

**Record failures as prominently as passes.** A negative result is the most useful thing this
project can publish.

### Why Axis B is out of scope

The project's claim is bounded: *WordPress and the stack it runs on are not the blocker.*
Axis A establishes exactly that. CDNs, WAFs and managed-host verb filtering sit outside the
WordPress boundary — they are per-deployment configuration on a vendor's release schedule, and
no core patch can influence them. Measuring them would produce a snapshot that rots fast and
concede a burden of proof the project does not carry.

This does not weaken §4 — but the argument it used to make was too comfortable. It said the
absence of Axis B data made the conservative assumption *"the only defensible one rather than
an open question."* That is how "we cannot measure it" became "therefore assume the worst,"
which is the reasoning [ADR 0003](decisions/0003-cache-safety-default.md) reversed on
2026-08-19.

**Descoping live measurement did not licence skipping documentary evidence.** Default configs
and vendor source are neither Axis B nor an assumption; they sit between the two, they are
inside the WordPress boundary in the sense that matters (a site owner can read them), and they
answered the question outright — [`experiments/cache-survey/`](../experiments/cache-survey/).
Axis B stays descoped; §4.1's conclusion did not survive it.

What a site owner will actually hit first is a `GET POST`-only hardening allowlist, which
403s `QUERY` on both nginx and Apache. That is measured, it is site configuration rather than a
defect, and it belongs in user documentation.

---

## 7. Open questions

**Q1 — What query format does a WordPress `QUERY` endpoint accept? ✅ ANSWERED — JSON and
form-encoded.** Decided 2026-08-19, [ADR 0001](decisions/0001-query-body-media-type.md)
option **B**. Core parses `application/json` (already works on trunk) and
`application/x-www-form-urlencoded` (the one-line `$accepts_body_data` fix). A query language
(option C) stays out of scope, and the pluggable model (option D) is **declined for now** —
a route can already parse its own body via `get_body()`, so core has nothing to build and
adding a declared-media-type API later is purely additive.

**Gap 3 is unblocked.** Two things this does *not* settle, both tracked in the ADR: an
unrecognized `Content-Type` still falls through silently (pre-existing and method-general, not
`QUERY`-specific), and whether `Accept-Query` should advertise form-encoded or only JSON —
parsing a format and endorsing it are different commitments.

**Q2 — Does the body reach PHP? ✅ ANSWERED — yes.** First matrix run, 2026-08-16.

On nginx, Apache (`mod_php` _and_ `mod_proxy_fcgi`) and Caddy, a `QUERY` request arrives at PHP
with `REQUEST_METHOD` exactly as sent and the body byte-identical, SHA-256 verified, **up to
64 KiB with no truncation**. `Content-Length` is honored for the unrecognized verb. Stock
configuration; no patches to any server. Results:
[../matrix/results/results.json](../matrix/results/results.json).

**The SAPI layer is not the blocker, and the project's central premise holds.**

Three qualifications, none fatal:

- **`php -S` returns 501** for `QUERY`. Dev and CI only, but it will bite contributors and
  anything built on the built-in server.
- **`GET POST`-only hardening allowlists 403 it** — on Apache as well as nginx. Site
  configuration, not a WordPress defect, but it is the first wall a real site owner hits.
- **PHP never populates `$_POST` for `QUERY`**, even with a form-encoded `Content-Type`
  (controlled against an identical `POST` body, which does populate it). This confirms
  [ADR 0001](decisions/0001-query-body-media-type.md)'s premise and makes option B's one-line
  fix the change that stops `QUERY` being a special case.

Still open under Axis A: HTTP/2 and HTTP/3 — everything so far ran over HTTP/1.1 — plus TLS and
OpenLiteSpeed. None of these is likely to change the answer: the verb and body are opaque to
the framing layer, and the two SAPIs already agree. Axis C (clients) is outstanding; Axis B is
out of scope.

**With Q2 answered, nothing empirical blocks the project.** What remains is design — the four
ADRs — and the patch itself.

**Q3 — What is the actual prior art in WordPress? ✅ ANSWERED.** Read in a browser 2026-08-16.

**[Trac#65616](https://core.trac.wordpress.org/ticket/65616) — verified.**
"Support the HTTP `QUERY` method for read/search REST endpoints (RFC 10008)". Reported by
**khokansardar**, opened **2026-07-13**. Type enhancement, priority normal, severity normal,
status **new**, no owner, milestone **Awaiting Review**, component **REST API**, focuses
`rest-api` + `performance`, keywords **`early`** and **`2nd-opinion`**.

**It has zero comments.** No core-committer response, no patch, no rejected approach, nothing
to work around. The two keywords are an open invitation: `early` asks for a change landed early
in a cycle, `2nd-opinion` asks for more eyes. **This project is the second opinion.**

Its content is a problem statement (URL-length ceiling vs. `POST` semantics), a findings list,
and a three-phase proposal:

1. Make core `QUERY`-safe — handle `QUERY` in `get_parameter_order()` for both JSON and
   form-encoded bodies, add a `QUERY`/`QUERYABLE` constant, add an **optional `QUERY → GET`
   fallback mirroring `HEAD → GET`**, unit tests, document outbound support.
2. Opt-in advertising plus one pilot endpoint — advertise `QUERY` in
   `Access-Control-Allow-Methods` **only when a route registers it**; pilot `/wp/v2/search`.
3. Client feature detection — teach `@wordpress/api-fetch` to use `QUERY` for oversized reads
   with automatic `GET` fallback (Gutenberg repo).

Three things worth carrying out of it:

- **Its gap analysis and ours agree, independently and exactly** — same three sites, same
  reasoning, including that outbound already works and needs only a docblock. Its line numbers
  are lower than ours (`rest-api.php:801` vs. our `:814`; `class-wp-rest-server.php:1185-1187`
  vs. our `:1190-1196`), so it was written against an earlier trunk. Convergent findings from a
  separate author are worth saying out loud on the ticket.
- **The `QUERY → GET` fallback is a real option we had not considered**, and it is not in
  [ADR 0002](decisions/0002-allmethods-vs-queryable.md). Now added there as option E.
- **Its phase 2 proposes deriving the CORS method list from registered routes**, which is a
  different and better fix than the #38546 precedent of appending to the hardcoded string.
  #65616 is the *only* citation for that idea — an earlier draft also credited
  [#43428](https://core.trac.wordpress.org/ticket/43428), which was a misreading, corrected
  above. Derive-from-routes is also not currently on the table for gap 1: it changes default
  output for anyone with a custom-method route, making it a behavior change rather than a no-op,
  which is a hard sell on a bug ticket.

**Also verified:** [Requests#1074](https://github.com/WordPress/Requests/issues/1074) (opened
2026-08-10, milestone 2.1.0, links to Trac#65616) and
[Requests#1075](https://github.com/WordPress/Requests/pull/1075) (open PR: constant + helper).
Both post-date the research pass that produced this document, which is why an earlier draft
recorded prior art as absent. **Absence of evidence was not evidence of absence, and it took the
user surfacing these to correct it.**

**One sub-question remains open, and it is now believed unanswerable from Trac.** The original
rationale for the current `ALLMETHODS` constant set is **not** in #65616, and searches surface
no ticket discussing it or any earlier custom-verb attempt (`SEARCH`, `GET`-with-body). The
constants date to the WP-API plugin era, so if the reasoning was recorded anywhere it is the
`WP-API/WP-API` GitHub history rather than Trac. **ADR 0002 is unblocked** — it should proceed
on the merits and stop waiting for a rationale that probably was never written down. Treat
"deliberately restricted" as unsupported unless someone produces the citation.

**Q4 — Should `QUERY` be on by default, or opt-in only?**
Restated 2026-08-19. It was previously "should `QUERY` join `ALLMETHODS`?", which framed the
choice too narrowly — ADR 0002's lean is now **option D**, `READABLE` gaining `QUERY`, which is
a broader default than `ALLMETHODS` and a safer one, since `ALLMETHODS` also covers writes.
Either way the substance is the same: does core turn `QUERY` on for existing routes, or does
each route author opt in? **This is the question to put to the community, not to answer
unilaterally here.**
→ [ADR 0002](decisions/0002-allmethods-vs-queryable.md), [#38](../../issues/38) for the
missing number

**Q5 — Does the cache-safety default belong in core or in the route?** §4.1.
**✅ ANSWERED 2026-08-19 — neither. There is no default.** The question presupposed that some
cache would store a `QUERY` response; none does, and RFC 9111 §3 forbids it for any cache that
recognizes the method without implementing §2.7 keying. Core emits nothing method-specific.
The residual work is two upstream plugin reports ([#39](../../issues/39)), and the residual
severity is confusion rather than disclosure.
→ [ADR 0003](decisions/0003-cache-safety-default.md),
[`experiments/cache-survey/`](../experiments/cache-survey/)

**Q6 — If we adopt the `Location` indirection, who mints the URI?** §4.2.
→ [ADR 0004](decisions/0004-location-indirection.md)

**Q7 — Can `@wordpress/api-fetch` emit a `QUERY` with a body end-to-end?** §5.2. Unestablished.

**Q8 — Redirects.** Is replaying a `QUERY` body on a 30x correct, and should `WP_Http` differ
from its current behavior of leaving it alone?

---

## 8. Sequencing

**Task breakdown: [GitHub issues](https://github.com/moonmeister/wp-http-query/issues)**, mapped
in the README under [Where the work lands](../README.md#where-the-work-lands). They track who
does what and where — the work lands in six places, only one of which we control. This section
states the ordering principle; it deliberately does not duplicate the task list.

The critical path is short, and **no part of it is empirical any more**. Q2 — whether `QUERY`
and its body reach PHP — was the gating unknown and is answered. What remains:

1. ~~**Read [Trac#65616](https://core.trac.wordpress.org/ticket/65616) and finish Q3.**~~
   **Done 2026-08-16.** The ticket is real, uncontested, comment-free and tagged `2nd-opinion`.
   Engagement with an open thread, not a fresh proposal.
2. **Settle [ADRs 0001–0004](decisions/).** The only remaining blockers are decisions, and
   **none of them is blocked on anything external any more.**
3. **Write the patch, and prove it with the feature plugin** on stock core.
4. **Bring patch, plugin and matrix results to the existing ticket.**

Deliberate ordering: arrive with evidence and a working demonstration rather than a patch and
an argument. Note that the Requests track (§5.1) runs independently and ahead — core will not
accept patches to vendored dependencies, so anything in `Requests/` must land upstream first.

---

## 9. Provenance and caveats

- All trunk line references verified directly against the core fork at `7.2-alpha-63166-src` /
  `e7739d5414` on 2026-08-16. There are currently **zero** occurrences of `'QUERY'` or
  `Accept-Query` in `src/wp-includes/`.
- Claims tested and **refuted** during research, recorded so they are not reintroduced:
  that IANA's safe+idempotent flags are what make `QUERY` cacheable (`SEARCH` and `PROPFIND`
  are both safe+idempotent but not cacheable by default — cacheability comes from RFC 10008
  §2.7 specifically); that axios v1.16.0 was "headlined" by `QUERY`; and both claims about
  `@wordpress/api-fetch`'s method handling.
