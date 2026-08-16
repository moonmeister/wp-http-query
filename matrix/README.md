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

`post-control` matters: if it fails too, the stack is misconfigured rather than
`QUERY`-hostile. Never report a `query-*` failure without checking its control.

### Verdicts

`pass` requires all of: HTTP 200; `REQUEST_METHOD` arrived exactly as sent; `php://input` was
readable; and the body's SHA-256 matches what the client asserted via `X-Probe-Expect-Sha256`.

Anything else is `fail` (reached the stack, wrong answer) or `error` (never reached it).

---

## Axis B — intermediaries *(manual)*

**Cannot be containerized.** Needs real deployments.

| Layer | Question | Status |
|---|---|---|
| Cloudflare | Pass through, 405, or strip body? Cached? | Not started |
| Fastly | Same | Not started |
| Varnish (builtin VCL) | Same | Not started |
| ModSecurity / OWASP CRS | Does the default ruleset reject an unknown verb? | Not started |
| WP Engine | Platform-level verb filtering? | Not started |
| Kinsta | Same | Not started |
| Pantheon | Same | Not started |

Varnish is the one testable locally — worth adding to `compose.yaml` as a front-end tier once
Axis A is green.

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
