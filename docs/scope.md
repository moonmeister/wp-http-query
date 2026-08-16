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
  preflight, `Allow`/`Accept-Query` advertisement, cache-safety headers.
- **Outbound (client):** `WP_Http` / `wp_remote_request()` and the bundled Requests library
  sending `QUERY` as a first-class, documented method.
- **JS client:** `@wordpress/api-fetch` emitting `QUERY` with a body through its full
  middleware chain.
- **Cache safety:** ensuring WordPress does not enable cache poisoning by body-blind
  intermediaries (§4).
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
| An existing core endpoint, e.g. `QUERY /wp-json/wp/v2/posts`         | **404 `rest_no_route`** — "No route was found matching the URL and request method." Not a 405. Unchanged by this project. |
| A route registered with `ALLMETHODS`                                 | **Decided by [ADR 0002](decisions/0002-allmethods-vs-queryable.md).**                                                     |

That third row is why this scope line and ADR 0002 are the same decision viewed from two
angles. Folding `QUERY` into `ALLMETHODS` would make every route using that constant — across
core _and every plugin_ — silently begin answering `QUERY`, with handlers never written for it
and a body they will not have parsed. That is migration, performed implicitly across the
ecosystem. Declining to migrate anything is what makes opt-in the leaning.

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

Two real costs, neither of them BC:

- **Core's test suite hard-codes method sets.** Exact-match assertions exist in
  `rest-posts-controller.php:256,266`, `rest-attachments-controller.php:374,383`, and
  `rest-server.php:397,437,476,536`. A fixture update, not an API break — but see the note in
  ADR 0002, where this doubles as a free blast-radius measurement.
- **Caching stakes rise sharply.** A _core_ endpoint answering `QUERY` behind a body-blind
  cache is [ADR 0003](decisions/0003-cache-safety-default.md)'s poisoning scenario on exactly
  the kind of route a CDN is configured to cache. **This, not versioning, is the reason to
  defer first-adopter work to its own ticket.**

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
Cloudflare, Fastly, nginx, and host page caches own that. As of today **none appear to
implement body-inclusive cache keys**, so in practice nothing will cache `QUERY` for a while.

### 4.1 Cache-poisoning defense — ours, and load-bearing

The one that matters. A cache that does not understand `QUERY` may fall back to keying by URI
alone. Two different request bodies to the same URI then collide, and one user's result set is
served to another. **No `Vary` mechanism can protect against this** — `Vary` operates on
request headers, and there is no `Vary: body`.

So this cannot be delegated. Current behavior is not safe by default:
`rest_send_nocache_headers` defaults to `is_user_logged_in()`
(`class-wp-rest-server.php:487`), so **anonymous REST responses carry no explicit
`Cache-Control` at all**, leaving intermediaries to apply heuristic caching. Tolerable for
URI-keyed `GET`. Not tolerable for body-keyed `QUERY`.

See [ADR 0003](decisions/0003-cache-safety-default.md).

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

This does not weaken §4. The cache-safety default assumes **no** intermediary implements
RFC 10008 §2.7 body-inclusive cache keys, which is the conservative direction. Having no Axis B
data makes that assumption the only defensible one rather than an open question — see
[ADR 0003](decisions/0003-cache-safety-default.md).

What a site owner will actually hit first is a `GET POST`-only hardening allowlist, which
403s `QUERY` on both nginx and Apache. That is measured, it is site configuration rather than a
defect, and it belongs in user documentation.

---

## 7. Open questions

**Q1 — What query format does a WordPress `QUERY` endpoint accept?**
RFC 10008 anticipates real query-format media types (`application/jsonpath`, `application/sql`)
advertised via `Accept-Query`. WordPress has no parsing story for any of them, and its existing
JSON body path only works if we settle on an `application/json`-shaped filter payload. **Must
precede any patch** — gap 3 cannot be fixed correctly without answering it.
→ [ADR 0001](decisions/0001-query-body-media-type.md)

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

**Q3 — What is the actual prior art in WordPress?**
**Partially answered.** Two upstream tickets exist and are tracked in the
[README](../README.md#tracked-tickets):

- [Trac#65616](https://core.trac.wordpress.org/ticket/65616) — ⚠️ **UNVERIFIED.** Trac serves a
  bot challenge (HTTP 403) to automated fetches, so this ticket's title, status, component, and
  contents have **not** been confirmed by anyone on this project. The ticket number came from
  the user; everything else previously written here was reconstruction and has been removed.
  **Open it in a browser and record what it actually says before citing it anywhere.**
  Presumed to be the core-side ask, which is why sequencing step 1 is to read it.
- [Requests#1074](https://github.com/WordPress/Requests/issues/1074) — "Support fror QUERY HTTP
  method" _[sic]_, opened 2026-08-10, milestone 2.1.0, links to Trac#65616.
- [Requests#1075](https://github.com/WordPress/Requests/pull/1075) — open PR implementing
  #1074 (constant + helper method), triaged into milestone 2.1.0.

Both were opened after the research pass that produced this document, which is why the earlier
draft recorded prior art as absent. **The general caution still stands: absence of evidence was
not evidence of absence, and it took the user surfacing these to correct it.**

Still outstanding: the original rationale for the current `ALLMETHODS` constant set, and any
earlier custom-verb attempts (`SEARCH`, `GET`-with-body). If methods were deliberately
restricted, engage that argument rather than rediscover it — this is what
[ADR 0002](decisions/0002-allmethods-vs-queryable.md) is blocked on.

**Q4 — Should `QUERY` join `ALLMETHODS`, or be opt-in only?**
Adding it changes behavior for every existing `ALLMETHODS` route in core and in plugins — those
routes would begin answering `QUERY` with handlers never written for it.
→ [ADR 0002](decisions/0002-allmethods-vs-queryable.md)

**Q5 — Does the cache-safety default belong in core or in the route?** §4.1.
→ [ADR 0003](decisions/0003-cache-safety-default.md)

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

1. **Read [Trac#65616](https://core.trac.wordpress.org/ticket/65616) and finish Q3.** The
   core-side ticket already exists, so this is engagement with an open thread, not a fresh
   proposal. Nothing else can start without it, and no tool on this project can read it.
2. **Settle [ADRs 0001–0004](decisions/).** The only remaining blockers are decisions.
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
