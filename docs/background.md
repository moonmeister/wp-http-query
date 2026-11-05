# Background

Project history and context: why the project exists, how it got to a plugin, what was researched
and decided along the way, and what it deliberately leaves alone. You don't need any of this to
use the plugin or contribute to it. For that, start at the [README](../README.md).

Much of this is dated and some of it will go stale. Current plans live on the
[project board](https://github.com/users/moonmeister/projects/1) and in the
[milestones](https://github.com/moonmeister/wp-http-query/milestones), not here. The design
reasoning is in [scope.md](scope.md), the ecosystem's state in [ecosystem.md](ecosystem.md), the
contested calls in [decisions/](decisions/), and the project's principles in
[CONTRIBUTING.md](../CONTRIBUTING.md#principles).

## What this project is

A **readiness** project ([scope.md §1](scope.md#1-purpose)): `QUERY` is a Proposed Standard, but
browsers, servers, CDNs and hosts are mid-rollout, and WordPress shouldn't be the blocker when they
finish. Concretely: a site owner whose stack supports `QUERY` should be able to use it with WordPress
without patching core, and a site owner whose stack does not should be unaffected.

Why `QUERY` at all is in [scope.md §1](scope.md#why-query): it is a safe, idempotent method with a
body, for reads that today become `POST` requests.

### Plugin first (decided 2026-11-04)

The plugin is the vehicle, not a demo for a core patch. All three core gaps can be worked around
from userland ([`experiments/plugin-spike/`](../experiments/plugin-spike/), 6/6 on unmodified
trunk), so a plugin could ship the whole capability. It shipped as
[v0.1.0](https://github.com/moonmeister/wp-http-query/releases/tag/v0.1.0).

Two core defects found along the way are fixed upstream for 7.2:
[Trac#65905](https://core.trac.wordpress.org/ticket/65905) (r63756, array-form `methods` never
comma-split, [#36](https://github.com/moonmeister/wp-http-query/issues/36)) and
[Trac#46992](https://core.trac.wordpress.org/ticket/46992) (r64730, the CORS header clobber,
[#16](https://github.com/moonmeister/wp-http-query/issues/16)). They differ on disclosure,
deliberately. #16 was framed with no reference to `QUERY` at all. Trac#65905 **does** disclose it,
because there the overlap is the strongest argument available: if `READABLE` gains a second method
the bug produces 14 failures in core's own suite, so core is one multi-method constant away from
breaking its own routes.

### Route to core (decided 2026-11-05)

Not an official feature plugin: we'll likely skip that step and propose a core patch on
[Trac#65616](https://core.trac.wordpress.org/ticket/65616) directly, arguing from what the plugin
has proven. Whether that's one patch or several is undecided until the exact scope of the core
changes is known. Tracked in [#60](https://github.com/moonmeister/wp-http-query/issues/60).

## Research status

A log of the research behind the plugin, as of 2026-11-05. Each row links to its evidence.

| Workstream | State |
|---|---|
| Scope defined | Done: [scope.md](scope.md) |
| Trunk gap analysis | Done: three sites identified, verified against `7.2-alpha-63166` |
| Prior-art search (Trac) | **Done 2026-08-16.** [Trac#65616](https://core.trac.wordpress.org/ticket/65616) read in full (new, unowned, no comments, `2nd-opinion`), plus five CORS tickets. The `ALLMETHODS` rationale is **not on Trac and probably was never written down**; ADR 0002 proceeds without it. [Scope](scope.md) Q3 answered |
| Test matrix: Axis A (SAPI) | **Run 2026-08-16. `QUERY` + body reach PHP intact on nginx, Apache (both SAPIs) and Caddy**, up to 64 KiB. `php -S` 501s; `GET POST` hardening allowlists 403. [Results](../matrix/results/MATRIX.md) |
| Test matrix: Axis B (CDN/WAF) | **Out of scope**: outside the WordPress boundary, and Axis A is sufficient for a readiness claim. See [scope.md](scope.md) §6. **Note the failure mode this created**: the descope was read as licence to assume the worst about caches, which is the mistake ADR 0003 reversed. Documentary evidence (default configs and vendor source) is neither Axis B nor an assumption |
| Test matrix: Axis C (clients) | Partly outstanding; `WP_Http` cases belong with the plugin |
| ADR 0002 blast radius | **Run 2026-08-16** against core's REST suite: options A and B change nothing, option D fails 20. **All three numbers are misleading and the ADR says why**: core uses `ALLMETHODS` once, so the suite cannot measure option A, and 14 of D's 20 are a pre-existing core bug. [Experiment](../experiments/blast-radius/) · [ADR 0002](decisions/0002-allmethods-vs-queryable.md) |
| ADR 0001 | **Decided 2026-08-19: option B**, JSON + form-encoded. One line: `'QUERY'` into `$accepts_body_data`. Option D (pluggable per-route formats) declined as unneeded, and free to defer because routes can already parse their own bodies with no core support. [ADR 0001](decisions/0001-query-body-media-type.md) |
| ADR 0002 | **Decided 2026-09-15: option B**, additive `QUERYABLE = 'QUERY'`; `READABLE` and `ALLMETHODS` untouched, nothing migrates on upgrade. The C → D reversal of 2026-08-19 did not survive [#38](https://github.com/moonmeister/wp-http-query/issues/38): it rested on "handlers do not break," which was true of the mechanism it had refuted (parameter population) and false of one nobody had looked for. **C was dropped too**: its only distinguishing feature is a promise to fold `QUERY` into `ALLMETHODS` later, and `ALLMETHODS` has the same failure mode at lower volume. Reach moved to [ADR 0005](decisions/0005-reach-for-existing-read-routes.md). [ADR 0002](decisions/0002-allmethods-vs-queryable.md) · [#10](https://github.com/moonmeister/wp-http-query/issues/10) |
| ADR 0003 | **Decided 2026-08-19: option C, core emits nothing method-specific.** The threat model was falsified: **no shipping cache stores a `QUERY` response.** Varnish `synth(501)`s it before the origin sees it (8.0 – 9.0.x; 9.1.0+ `pass`es it), nginx's cache-method bitmask holds only `GET`/`HEAD`/`POST`, `mod_cache`/Squid/Fastly/Cloudflare allowlist, and RFC 9111 §3 forbids storing a response whose method the cache has not *understood*. Residual vector: W3TC Pro with REST caching on: **confusion, not disclosure** ([#39](https://github.com/moonmeister/wp-http-query/issues/39), closed as not planned; the plugin README documents the caveat). [Survey](../experiments/cache-survey/) · [ADR 0003](decisions/0003-cache-safety-default.md) |
| ADR 0005 | **Decided and built 2026-11-04: reach is opt-in.** Off by default; routes opt in with `QUERYABLE`, sites with the `wp_http_query_routes` filter or the `core-collections` preset. [ADR 0005](decisions/0005-reach-for-existing-read-routes.md) · [usage](../plugin/README.md#choosing-which-routes-accept-query) |
| Method-branching risk | **Measured 2026-09-15, and it named a break.** 170 plugins / 16.9M installs branch on the request method; **28–65 plugins / 0.82M–11.96M installs** also use `READABLE`, and the range cannot be narrowed without following each registration to its callback; both bounds were falsified by reading their largest member. Verified case: **Jetpack (3M)** registers three routes `READABLE` whose callbacks are `if ( 'GET' === get_method() ) { read } else { write }`, so a `QUERY` activates modules. Authorization fails *closed*; it is a safe-method violation, not an escalation. This ended the option D lean. [Sweep](../experiments/plugin-spike/README.md#method-branching-sweep) · [#38](https://github.com/moonmeister/wp-http-query/issues/38) |
| Gap 3 behavior measured | **Done 2026-08-16**, unmodified trunk. A `QUERY` with a **JSON** body already populates and schema-validates params correctly: dispatched at the real posts controller it filters the collection and `400`s a bad `per_page`. Only **form-encoded** bodies are lost, and they return the full unfiltered collection with a `200`. Gap 3 is the only gap whose failure is a silent wrong answer, so it is first in the core patch order. [Probes](../experiments/blast-radius/) |
| Core bug found (unrelated to `QUERY`) | `register_rest_route()` did not comma-split `methods` given in array form, so `array( READABLE, EDITABLE )` registered the bogus key `'POST, PUT, PATCH'` and `POST` 404'd. Confirmed on unmodified trunk, [failing test written](../experiments/blast-radius/rest-array-methods-probe.php). Measured in the plugin directory: 3 plugins / 2,000 installs hit it, but 27 plugins / 1.19M installs would break if `READABLE` ever gained a second method. Filed as [Trac#65905](https://core.trac.wordpress.org/ticket/65905), patched in [wordpress-develop#13136](https://github.com/WordPress/wordpress-develop/pull/13136), **fixed for 7.2** in r63756 |
| CORS clobber ([#16](https://github.com/moonmeister/wp-http-query/issues/16)) | **Fixed for 7.2** in [r64730](https://core.trac.wordpress.org/changeset/64730) (2026-10-26, adamsilverstein), reworked from [wordpress-develop#13151](https://github.com/WordPress/wordpress-develop/pull/13151). Adds `rest_allowed_cors_methods`, which the plugin uses |
| Requests upstream PR | **In flight upstream, not ours**. See [Upstream tickets](#upstream-tickets) |
| Plugin | **Shipped as [v0.1.0](https://github.com/moonmeister/wp-http-query/releases/tag/v0.1.0)** (prerelease). All three gaps worked around in userland, with `Accept-Query`, opt-in reach, a settings page and a self-test. Tested on WordPress 7.0, 7.1 and nightly. From v0.2.0 it needs WordPress 7.2+ ([#59](https://github.com/moonmeister/wp-http-query/issues/59)); 7.0 and 7.1 stay on v0.1.0 |
| Core patch (`QUERY`) | ADRs 0001–0003 are decided, and exploratory patches for gap 3 and `QUERYABLE` exist on a `wordpress-develop` branch, `feat-http-query` (see [Repos](#repos)). Whether core gets one patch or several is undecided until the scope is known; see [#60](https://github.com/moonmeister/wp-http-query/issues/60) |

## Explicitly not doing

Full reasoning in [scope.md](scope.md) §3 and §6.

| | Why |
|---|---|
| Matrix Axis B: CDNs, WAFs, managed hosts | Outside the WordPress boundary; Axis A suffices for a readiness claim |
| Designing a query language | ADR 0001 option C, deferred. A pluggable per-route format API (option D) is declined too: routes can already parse their own bodies, so it is additive whenever it is wanted |
| Migrating existing core endpoints | Capability, not adoption |
| Lobbying nginx, CDNs, or hosts | Tracked, not gated on |
| Authoring the Requests PR | [#1075](https://github.com/WordPress/Requests/pull/1075) already exists and is being triaged |

## Upstream tickets

Re-check state before citing; [ecosystem.md](ecosystem.md#re-verification-protocol) has the
protocol. Ecosystem tickets (nginx, Fetch, browser standards positions, `<form method="query">`,
client libraries) move the readiness date but don't gate the project; they are in
[ecosystem.md](ecosystem.md), with dates. Trac serves automated fetchers a proof-of-work challenge (a 403), so re-verifying a Trac
ticket needs a person at a browser. The rows below were last read that way on 2026-08-16.

### Driven or watched

| Ticket | State (2026-08-16) | Detail |
|---|---|---|
| [Trac#65616](https://core.trac.wordpress.org/ticket/65616): "Support the HTTP `QUERY` method for read/search REST endpoints" | New, unowned, Awaiting Review, no comments | [scope.md](scope.md) Q3. The route to core in [#60](https://github.com/moonmeister/wp-http-query/issues/60) works through it |
| [Requests#1074](https://github.com/WordPress/Requests/issues/1074): QUERY support | Open, milestone 2.1.0 | [scope.md §5.1](scope.md#51-the-requests-library--already-works-undocumented) |
| [Requests#1075](https://github.com/WordPress/Requests/pull/1075): adds the constant and a helper | Open PR, triaged by jrfnl, milestone 2.1.0 | [scope.md §5.1](scope.md#51-the-requests-library--already-works-undocumented) |
| [test-server#13](https://github.com/RequestsPHP/test-server/pull/13): companion to #1075 | Open | Blocks CI for #1075 |

What follows from them:

- **The project doesn't open a fresh proposal anywhere.** Trac#65616 frames the core-side ask;
  Requests#1074/#1075 cover the client side. The job is to supply evidence and patches to the
  existing threads.
- **The Requests work is in flight, and not by this project.** Our contribution there is review,
  testing and unblocking, not authorship.
- **#1075 is CI-blocked on test-server#13.** That is where outside help is most useful.

### CORS prior art (cited, not driven)

Gap 1's supporting record: Trac#43428, #46992, #38546, #57752 and #38060, each read in full. The
summary and the reasoning are in [scope.md §2](scope.md#2-current-state-of-trunk), under "Gap 1 has
prior art".

## Repos

| Repo | Purpose | Branch |
|---|---|---|
| **wp-http-query** (this) | The plugin, plus docs, ADRs and the matrix | `main` |
| [WordPress/wordpress-develop](https://github.com/WordPress/wordpress-develop) | Core patch work, per [#60](https://github.com/moonmeister/wp-http-query/issues/60). Exploratory patches exist on a `feat-http-query` branch (predates 7.2, needs a rebase) | — |
| [WordPress/Requests](https://github.com/WordPress/Requests) | `Requests::QUERY` constant + tests, upstream in [Requests#1075](https://github.com/WordPress/Requests/pull/1075) | — |

Core and Requests changes are made in those projects, not here; see
[CONTRIBUTING.md](../CONTRIBUTING.md#conventions).
