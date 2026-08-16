# Ecosystem support status

**Last verified: 2026-08-16.**

> ⚠️ Everything in this file is time-sensitive. Most of the underlying activity is from the
> ~10 weeks preceding the verification date and is moving weekly. **Re-verify before citing
> any of it publicly.** Undated claims about ecosystem support rot silently and will be
> checked in a Trac discussion.

This is tracked, **not gating**. See [scope.md](scope.md) §3 — the project deliberately does
not wait on any of these.

---

## Spec (settled — not time-sensitive)

| Item | Status |
|---|---|
| RFC 10008, "The HTTP QUERY Method" | Published June 2026, IETF Standards Track (**Proposed Standard**), from draft -14 |
| IANA HTTP Method Registry | `QUERY,yes,yes,"[RFC10008, Section 2]"` — safe, idempotent |
| IANA HTTP Field Name Registry | `Accept-Query` — status `permanent`, Structured Type `List` |
| Errata | 9013, 9016 — both cosmetic, appendix examples only. No normative change. |

RFC 10008 is immutable and its IANA registrations are settled. This section is durable;
everything below is not.

---

## Web servers

### nginx — no native method identifier

No `NGX_HTTP_QUERY` identifier in master as of 2026-08-10. Support exists only as unmerged
community patches: [PR #1488](https://github.com/nginx/nginx/pull/1488) (open since
2026-06-22) and PR #1511 (closed unmerged).

**This is a configuration limitation, not a request rejection.** The HTTP/1.x, /2 and /3
parsers all accept any `[A-Z_-]` token, map it to `NGX_HTTP_UNKNOWN`, and pass
`REQUEST_METHOD` verbatim to PHP-FPM.

Practical consequences:

- `limit_except QUERY` is a **config-parse error**
- `proxy_cache_methods QUERY` is **unacceptable**
- The common hardening block `limit_except GET POST { deny all; }` **will 403 `QUERY`**, because
  the `UNKNOWN` bit is retained
- nginx's static handler 405s non-GET/HEAD/POST — **irrelevant for `/wp-json/*`**, which routes
  through `try_files → /index.php → fastcgi_pass`

> Do not let this get restated as "nginx blocks QUERY." That would be wrong, and the
> distinction matters for the project's premise. The hardening-block 403 is covered by a
> dedicated matrix stack.

### Apache, LiteSpeed, Caddy

**Unverified.** Nothing survived research verification about Apache (`mod_php` vs
`mod_proxy_fcgi`, `<Limit>`/`<LimitExcept>` semantics for unknown verbs, ModSecurity default
rulesets), LiteSpeed/OpenLiteSpeed, or Caddy. Covered by the matrix.

---

## Browsers

| Item | Status |
|---|---|
| Can `fetch()` send `QUERY`? | **Yes.** Forbidden methods are only CONNECT, TRACE, TRACK; the `Request` constructor rejects bodies only for `GET`/`HEAD`. |
| Method normalization | **`QUERY` is absent from Fetch's "normalize a method" list** (which uppercases only DELETE, GET, HEAD, OPTIONS, POST, PUT). A lowercase `'query'` is sent verbatim as lowercase. |
| CORS safelist | **Not safelisted.** Cross-origin `QUERY` always preflights. RFC 10008 §4 expects this; no safelist change is being sought. |
| `mode: 'no-cors'` | Restricts methods to GET/HEAD/POST — `QUERY` unusable there. |
| Browser caching of `QUERY` | Neither Chrome nor Firefox caches a repeated identical `QUERY`. ⚠️ *Weak evidence: one contributor's unrebutted test in a GitHub issue, not a vendor statement.* |
| `<form method="query">` | Unresolved ([whatwg/html#12594](https://github.com/whatwg/html/issues/12594)); currently falls back to `GET`, dropping the body. |

**Implication for WordPress JS clients: always pass the uppercase literal `'QUERY'`.** Fetch
editor Anne van Kesteren declined to add it to the normalization list — *"No. Method names are
case-sensitive."*

Note that wp-admin `apiFetch` calls are normally same-origin, so the preflight constraint
mostly affects third-party consumers rather than the block editor.

### Standards positions

| Body | Position |
|---|---|
| WHATWG Fetch | [Issue #1938](https://github.com/whatwg/fetch/issues/1938) — **open**, labeled "needs implementer interest" |
| Mozilla | [#1430](https://github.com/mozilla/standards-positions/issues/1430) — **deferred** pending fetch/HTML-forms resolution |
| WebKit | Issue closed |

---

## Client libraries

| Library | Status |
|---|---|
| **axios** | Shipped `QUERY` in **v1.16.0** (2026-05-02). Verified at code level. ⚠️ The referenced PR #10802 404s; merge commit `f39203dcb` is the real evidence. |
| **undici** | Shipped a body-aware `QUERY` cache key ([#5459](https://github.com/nodejs/undici/pull/5459)) |
| **curl** | Native support; `-X QUERY` works regardless |
| **WP Requests** | Transits both transports today, but no `Requests::QUERY` constant and `WP_Http`'s contract excludes it. **[PR #1075](https://github.com/WordPress/Requests/pull/1075) open** (constant + helper, milestone 2.1.0, CI-blocked on [test-server#13](https://github.com/RequestsPHP/test-server/pull/13)) — see [scope.md](scope.md) §5.1 |
| **@wordpress/api-fetch** | **Unestablished.** Research claims were refuted or split. Must be tested against source. |
| Guzzle | Not covered |

---

## CDNs and intermediaries

**Entirely unverified.** No verified evidence surfaced for Cloudflare, Fastly, Varnish,
ModSecurity/OWASP CRS defaults, WP Engine, Kinsta, or Pantheon.

What is known indirectly: **no CDN appears to implement RFC 10008 §2.7 body-inclusive cache
keys**, so in practice nothing caches `QUERY` today. This is the central argument for the
`Location` indirection — see [ADR 0004](decisions/0004-location-indirection.md).

Covered by matrix Axis B, which requires real deployments rather than local containers.

---

## Frameworks

Largely uncovered — and worth filling in, because framework precedent is a persuasive asset in
a Trac discussion.

| Framework | Status |
|---|---|
| **Ruby on Rails** | A core proposal thread exists, with a note that Action Pack currently rejects `QUERY` before controllers see it. ⚠️ Mentioned incidentally during research; **not verified**. |
| Symfony | Not covered. [PR #61173](https://github.com/symfony/symfony/pull/61173) collected but unverified. |
| Laravel | Not covered. [Discussion #60564](https://github.com/laravel/framework/discussions/60564) collected but unverified. |
| ASP.NET Core | Not covered. [Issue #61089](https://github.com/dotnet/aspnetcore/issues/61089) collected but unverified. |
| Spring | Not covered. [PR #34993](https://github.com/spring-projects/spring-framework/pull/34993) collected but unverified. |
| Django | Not covered. |

---

## Re-verification protocol

Re-run before any public claim, and at minimum monthly:

1. **nginx** — `gh api repos/nginx/nginx/pulls/1488 --jq .state`, and grep master
   `src/http/ngx_http_request.h` for a `NGX_HTTP_QUERY` identifier.
2. **Fetch** — check [whatwg/fetch#1938](https://github.com/whatwg/fetch/issues/1938) state and
   labels; check whether `QUERY` entered the normalize-a-method list in `fetch.bs`.
3. **Standards positions** — Mozilla #1430, WebKit.
4. **Browsers** — re-test caching empirically. The current datapoint is weak.
5. **Frameworks** — resolve the five collected-but-unverified links above.
6. **Update the date at the top of this file**, even if nothing changed. A stale date is more
   useful than an absent one.

The matrix harness automates part of this — see [../matrix/README.md](../matrix/README.md).
Wiring it to a scheduled CI run would catch, for example, the day nginx merges #1488.
