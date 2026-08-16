# QUERY compatibility matrix

Answers the project's make-or-break question:

> **Does an HTTP `QUERY` request — and its body — survive the path from client to PHP?**

No documentation source answered this during research. It needs empirical testing, and
negative results are as valuable as positive ones.

## Running it

```sh
./run.sh            # bring stacks up, run, leave running
./run.sh --down     # tear down afterwards
./run.sh --no-up    # stacks already running
```

Requires Docker, `jq`, and `curl`. Results:

- **`results/results.json`** — canonical, machine-readable, timestamped
- **`results/MATRIX.md`** — generated view. Do not edit.

JSON is canonical because this matrix gets re-run over months as the ecosystem shifts.
Diffing runs is the point — it is how we notice the day a stack starts passing.

---

## Axis A — SAPI passthrough *(automated here)*

The probe (`probe/index.php`) has **no WordPress dependency**. Whether a verb and body reach
PHP is a property of the web server and SAPI, not of WordPress. Keeping it standalone makes it
fast across many stacks and citable outside the WordPress world.

It reads `php://input` **before anything else**, since some SAPIs permit only one read and
PHP's own post-data machinery may have consumed it already.

### Stacks

| Stack | Port | Notes |
|---|---|---|
| `nginx-fpm` | 8081 | Baseline. Mirrors a normal `/wp-json/*` front-controller path. |
| `nginx-hardened` | 8181 | `limit_except GET POST { deny all; }` — **expected to 403** |
| `apache-modphp` | 8082 | Baseline |
| `apache-modphp-hardened` | 8182 | `<LimitExcept GET POST>` |
| `apache-fcgi` | 8083 | Apache + `mod_proxy_fcgi` — different SAPI from mod_php |
| `caddy-fpm` | 8084 | Independent HTTP parser |
| `php-builtin` | 8085 | `php -S`. Affects local dev and CI, not production. |

nginx, `apache-fcgi` and `caddy` share **one** `php-fpm` backend on purpose — it isolates the
web server as the only variable. `apache-modphp` and `php-builtin` necessarily bring their own
PHP, since the SAPI is what is under test.

> **`limit_except QUERY` cannot be tested here.** It is a config-parse error on any nginx
> without a `QUERY` method identifier, so the container would fail to start. That *is* the
> finding — see [../docs/ecosystem.md](../docs/ecosystem.md).

> **OpenLiteSpeed is not yet included.** Its config is not usefully mountable as a single file
> and needs a build step. **Do not read its absence as a pass or a fail.**

### Cases

| Case | Method | Body | Asks |
|---|---|---|---|
| `get-control` | GET | — | Is the stack up and sane? |
| `post-control` | POST | JSON | Does a *known* body-bearing verb work here? |
| `query-json` | QUERY | JSON | **The main event.** |
| `query-form` | QUERY | form-encoded | Does PHP's `$_POST` machinery interfere? |
| `query-empty` | QUERY | — | Is the verb alone accepted? |
| `query-large` | QUERY | 64 KiB | Truncation a small body would hide. |
| `query-root` | QUERY | JSON | Directory-index handler instead of front controller. |
| `harden-sentinel` | DELETE | — | *Hardened stacks only.* Proves the config is in force. |

`post-control` matters: if it fails too, the stack is misconfigured rather than
`QUERY`-hostile. Never report a `query-*` failure without checking its control.

### Two traps this harness fell into, both now guarded

Recording these because both produced *confident, wrong* results, and both are the kind of
error that would be embarrassing to carry into a Trac discussion.

**1. Requesting the bare root.** The first run had every case hit `/`, and nginx returned 405
for `QUERY`. Read naively that is "nginx rejects QUERY" — the exact claim
[../docs/ecosystem.md](../docs/ecosystem.md) warns against. In fact nginx's index module
answers `/` and 405s non-GET/HEAD/POST *before* `try_files` can reach the front controller.
`QUERY /index.php` and `QUERY /wp-json/wp/v2/search` both return 200 with an intact body on
the same server. Cases now target `/index.php`; `query-root` keeps the root behavior visible
instead of discarding it.

**2. A hardening block that wasn't in force.** `apache-modphp-hardened` initially passed every
`QUERY` case, which looked like a real finding — Apache tolerating an unknown verb where nginx
did not. It was a config bug: a `Require all granted` sat in the same context as the
`<LimitExcept GET POST>` `Require all denied`, and Apache OR's `Require` directives via an
implicit `<RequireAny>`, so access was granted. Both directives are now method-scoped, and
`harden-sentinel` sends a **known** verb (DELETE) that must be denied. A 200 there fails the
stack loudly rather than inflating the pass count.

The general lesson: **a negative control is worth as much as the test.** A hardened stack that
denies nothing, and a permissive stack whose 405 comes from an unrelated handler, both report
cleanly and both are wrong.

### Verdicts

`pass` requires all of: HTTP 200; `REQUEST_METHOD` arrived exactly as sent; `php://input` was
readable; and the body's SHA-256 matches what the client asserted via `X-Probe-Expect-Sha256`.

Anything else is `fail` (reached the stack, wrong answer) or `error` (never reached it).

Sentinel cases invert this: `expect: "deny"` in `results.json` means a non-2xx is the pass.

---

## Axis A results — first run, 2026-08-16

Canonical data in [`results/results.json`](results/results.json); generated view in
[`results/MATRIX.md`](results/MATRIX.md).

**The make-or-break question is answered: yes.** On every stack that does not deliberately
block the verb, `QUERY` reaches PHP with `REQUEST_METHOD` exactly as sent and the body byte-
identical, verified by SHA-256 — **including a 64 KiB body, with no truncation**, across both
`fpm-fcgi` and `apache2handler`.

| Stack | `QUERY` reaches PHP | Body intact | Note |
|---|---|---|---|
| `nginx-fpm` | ✅ | ✅ | Passes on the front-controller path. `QUERY /` 405s — index module, not FastCGI. |
| `apache-modphp` | ✅ | ✅ | `apache2handler`. Root path works too. |
| `apache-fcgi` | ✅ | ✅ | `mod_proxy_fcgi` |
| `caddy-fpm` | ✅ | ✅ | |
| `nginx-hardened` | ❌ 403 | — | `limit_except GET POST`. Sentinel confirms in force. |
| `apache-modphp-hardened` | ❌ 403 | — | `<LimitExcept GET POST>`. Sentinel confirms in force. |
| `php-builtin` | ❌ 501 | — | `php -S` rejects unknown methods outright. |

Three findings worth carrying into the ticket:

1. **The SAPI layer is not the blocker.** nginx, Apache (both SAPIs) and Caddy all pass `QUERY`
   and its body through untouched, on stock configuration. This removes the most common
   objection to the whole premise — that `QUERY` cannot reach WordPress in the first place.

2. **Hardening allowlists block `QUERY`, on Apache as well as nginx.** The common
   `GET POST`-only block denies it with a 403 on both servers. This is a **site-configuration**
   issue, not a WordPress or web-server defect, and it is what a site owner will actually hit
   first. Worth documenting for users; nothing WordPress can fix.

3. **PHP never populates `$_POST` for `QUERY`** — not even with
   `Content-Type: application/x-www-form-urlencoded`. Controlled: the identical body sent as
   `POST` populates `$_POST` with `filter` and `per_page`; sent as `QUERY` it yields an empty
   `$_POST` and a fully intact `php://input`. This is PHP behavior, not a server difference —
   it held on every passing stack. See [ADR 0001](../docs/decisions/0001-query-body-media-type.md),
   whose premise it confirms.

Not yet covered: HTTP/2 and HTTP/3 (all cases ran over HTTP/1.1), TLS, OpenLiteSpeed, and
Axis C. Axis B is deliberately out of scope — see below.

---

## Axis B — intermediaries *(out of scope)*

**Descoped 2026-08-16.** Axis A answered the question this project actually needed answered:
the layers WordPress runs on — PHP, the FPM/mod_php SAPIs, and the four major web servers —
do not block `QUERY`. That is sufficient for a readiness claim.

CDNs, WAFs and managed-host verb filtering sit *outside* the WordPress boundary. They are
per-deployment configuration that a site owner controls or buys, they change on the vendor's
schedule, and no core patch can affect them. Testing them would produce a snapshot that rots
quickly and answers a question nobody in a Trac review is entitled to ask us.

| Layer | Why not tested |
|---|---|
| Cloudflare, Fastly | Needs real deployments; vendor-schedule, not ours |
| Varnish, ModSecurity / OWASP CRS | Containerizable, but same reasoning — deployment config |
| WP Engine, Kinsta, Pantheon | Platform policy, outside the project boundary |

**What we already know indirectly still stands:** no intermediary appears to implement RFC 10008
§2.7 body-inclusive cache keys, so in practice nothing caches `QUERY` today. That is the
*assumption* the cache-safety default is built on, and descoping Axis B does not weaken it —
it makes the conservative default the only defensible option, since we will have no evidence
that any given cache is body-aware. See
[ADR 0003](../docs/decisions/0003-cache-safety-default.md).

If someone later wants this data, `run.sh` takes a port and a path; adding a Varnish tier to
`compose.yaml` is a small job. It is just not on the critical path.

## Axis C — clients *(partly manual)*

| Client | Question | Status |
|---|---|---|
| curl | Baseline — used by this harness | Covered |
| `WP_Http` / Requests (cURL transport) | Sends `QUERY` with body? | Not started |
| `WP_Http` / Requests (fsockopen) | Same | Not started |
| Browser `fetch()` | Chrome / Firefox / Safari | Not started |
| `@wordpress/api-fetch` | Full middleware chain + `data` shorthand | Not started — **unestablished**, see scope §5.2 |
| axios ≥ 1.16.0 | Confirmed upstream; verify against WP | Not started |
| undici / Guzzle | | Not started |

The `WP_Http` cases need a WordPress install, so they belong with the feature plugin rather
than this harness.

---

## Reporting

When Axis A has real results, they go in `results/` and get summarized in
[../docs/ecosystem.md](../docs/ecosystem.md). **Publish failures as prominently as passes** —
a documented negative is the most useful output this project has.

Wiring `run.sh` to scheduled CI would catch ecosystem movement automatically, which for a
readiness project is close to the whole point.
