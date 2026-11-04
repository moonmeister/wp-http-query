# wp-http-query

Making WordPress ready for the HTTP `QUERY` method ([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008.html)).

**The deliverable is a feature plugin**, [`plugin/`](plugin/), that adds `QUERY` support to the
REST API on stock WordPress, with no core patch. This repo also holds the design docs, decision
records and compatibility test matrix behind it. Core patches come later, informed by what the
plugin proves in the field. See [Repos](#repos).

---

## What this project is

A **readiness** project. `QUERY` is a published IETF Proposed Standard, but browsers, web
servers, CDNs, and hosts are all mid-rollout. The goal is that WordPress is not the blocker
when they finish — not to wait for them, and not to claim they are ready.

Concretely: a site owner whose stack supports `QUERY` should be able to use it with WordPress
without patching core, and a site owner whose stack does not should be unaffected.

### Plugin first (decided 2026-11-04)

The plugin is the vehicle, not a demo for a core patch. All three core gaps can be worked
around from userland ([`experiments/plugin-spike/`](experiments/plugin-spike/), 6/6 on unmodified
trunk), so a plugin can ship the whole capability now. That is the usual WordPress feature-plugin
path: real sites, real feedback, then a core proposal backed by usage rather than argument.
Two core defects found along the way are already fixed upstream for 7.2:
[Trac#65905](https://core.trac.wordpress.org/ticket/65905) (r63756) and
[Trac#46992](https://core.trac.wordpress.org/ticket/46992) (r64730).

Start with **[docs/scope.md](docs/scope.md)**. To pick up work, see
**[the issues](https://github.com/moonmeister/wp-http-query/issues)** — the contributions this
needs land in six different repos, and [Where the work lands](#where-the-work-lands) maps them.

## Why `QUERY`

It is a safe, idempotent method that carries a request body. It exists to solve the problem
WordPress currently solves badly: read operations whose parameters are too large, too
structured, or too sensitive for a URL query string. Today those become `POST` requests, which
throws away everything the method system exists to communicate.

---

## Repos

| Repo | Purpose | Branch |
|---|---|---|
| **wp-http-query** (this) | **The feature plugin**, plus docs, ADRs, matrix | `main` |
| [wordpress-develop](https://github.com/WordPress/wordpress-develop) fork | Core patches, parked until the plugin has shipped | `feat-http-query` (stale since 2026-09-15) |
| [WpOrg/Requests](https://github.com/WpOrg/Requests) fork | `Requests::QUERY` constant + tests | `add-query-method` *(not yet created)* |

**The forks stay pristine.** No project documentation, no scratch files, nothing that isn't a
change intended for upstream. Everything else belongs here. A stray file in a core checkout
pollutes the diff and has to be cleaned up before a Trac patch.

Expected local layout — the matrix harness and scripts assume siblings:

```
~/code/moonmeister/
├── wp-http-query/        # this repo
├── wordpress-develop/    # core fork
└── Requests/             # Requests fork
```

Deliberately **not** submodules: the two forks track upstream on upstream's schedule and get
rebased independently.

---

## Where the work lands

Tracked entirely in **[issues](https://github.com/moonmeister/wp-http-query/issues)**. They are
organized by **capability delivered**, not by venue — venue is a label, because a single
capability usually needs research here, code in a fork, and a writeup on someone else's tracker.
Each parent carries research → code → report sub-issues: on this project *reporting is a
deliverable*, since the pitch is "arrive with verified evidence" and a patch nobody writes up is
worth about as much as no patch.

**[#37](../../issues/37) is the tracking issue** — the single entry point, carrying the suggested
working order and the reason for it. The table below is the same tree without the ordering.

| Capability | Venue | Milestone |
|---|---|---|
| [#5 Feature plugin](../../issues/5) — **the deliverable.** Run it on a real install ([#22](../../issues/22)), then publish ([#23](../../issues/23)) | this repo | Plugin v1 |
| [#1 Method constants & route matching](../../issues/1) — gap 2. **Reach is opt-in** (decided and built 2026-11-04): off by default; routes opt in with `QUERYABLE`, sites with the `wp_http_query_routes` filter or the `core-collections` preset ([ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md), [usage](plugin/README.md#choosing-which-routes-accept-query)) | this repo | Plugin v1 |
| ~~[#2 Request body parsing](../../issues/2)~~ — gap 3. **In the plugin** via `rest_request_parameter_order` | this repo | ✅ |
| ~~[#3 CORS preflight](../../issues/3)~~ — gap 1. **In the plugin**, using core's `rest_allowed_cors_methods` on 7.2+ ([r64730](https://core.trac.wordpress.org/changeset/64730)) and a replacement handler before that | this repo | ✅ |
| ~~[#4 `Accept-Query`](../../issues/4)~~ — **in the plugin.** Cache safety needs nothing ([ADR 0003](docs/decisions/0003-cache-safety-default.md) option C) | this repo | ✅ |
| Core patches — [#11](../../issues/11), [#14](../../issues/14), [#35](../../issues/35), [#20](../../issues/20) and the Trac write-ups [#12](../../issues/12), [#15](../../issues/15), [#21](../../issues/21). **Parked until the plugin ships** | core | Core patch v1 |
| [#6 PHP client — `WP_Http` / Requests](../../issues/6) — review, not authorship | WpOrg/Requests | Ecosystem |
| [#7 JS clients](../../issues/7) — `api-fetch` and browser `fetch()` | this repo | Ecosystem |

Plus six standalone: [#31](../../issues/31) re-file the WebKit standards position,
[#32](../../issues/32) document the hardening-allowlist 403 **and the Varnish 8.0 – 9.0.x `501`** (fixed upstream in 9.1.0),
[#33](../../issues/33) matrix Axis A
leftovers, [#34](../../issues/34) `/wp/v2/search` as first adopter, [#36](../../issues/36) file the
array-form `methods` normalization bug — **filed as
[Trac#65905](https://core.trac.wordpress.org/ticket/65905), fixed for 7.2 in
[r63756](https://core.trac.wordpress.org/changeset/63756)** —
and [#39](../../issues/39) report two page-cache method denylists upstream — **written and
parked** in [docs/submissions/](docs/submissions/), filed when the feature plugin publishes or
`QUERY` ships in a core release.

**Two of these landed regardless of `QUERY`**: [#16](../../issues/16) (the CORS header clobber) and
[#36](../../issues/36) (array-form `methods` never comma-split). Both are real core defects, and
**both are fixed for 7.2**: [r64730](https://core.trac.wordpress.org/changeset/64730) and
[r63756](https://core.trac.wordpress.org/changeset/63756).

They differ on disclosure, deliberately. #16 is framed with no reference to `QUERY` at all.
Trac#65905 **does** disclose it, because there the overlap is the strongest argument available: if
`READABLE` gains a second method the bug produces 14 failures in core's own suite, so core is one
multi-method constant away from breaking its own routes.

**Milestones.** *Plugin v1* is the current focus. *Core patch v1* follows it, and nothing goes to
Trac#65616 before the plugin has shipped. *Ecosystem* runs in parallel and gates nothing.
*Deferred* is named so it is not rediscovered as a gap.

The project has no external blockers. Opt-in reach is built
([ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md)); what remains is shipping it.

### Explicitly not doing

Full reasoning in [scope.md](docs/scope.md) §3 and §6.

| | Why |
|---|---|
| Matrix Axis B — CDNs, WAFs, managed hosts | Outside the WordPress boundary; Axis A suffices for a readiness claim |
| Designing a query language | ADR 0001 option C, deferred. A pluggable per-route format API (option D) is declined too — routes can already parse their own bodies, so it is additive whenever it is wanted |
| Migrating existing core endpoints | Capability, not adoption |
| Lobbying nginx, CDNs, or hosts | Tracked, not gated on |
| Authoring the Requests PR | [#1075](https://github.com/WordPress/Requests/pull/1075) already exists and is being triaged |

---

## Tracked tickets

Upstream tickets this project drives or is blocked on. **Re-check state before citing** —
see [docs/ecosystem.md](docs/ecosystem.md) for the re-verification protocol.

> **Verification note.** All rows below were fetched and confirmed directly. **Trac 403s
> automated fetchers** — it serves a proof-of-work challenge — but a human-driven browser clears
> it once and the `_hcc` cookie makes the rest of the session ordinary browsing. Trac rows were
> read that way on **2026-08-16**, comment threads included. Re-verification needs a person at a
> browser, not a fetch tool.

| Ticket | Where | Title | State | Notes |
|---|---|---|---|---|
| [Trac#65616](https://core.trac.wordpress.org/ticket/65616) | Core — REST API | "Support the HTTP `QUERY` method for read/search REST endpoints (RFC 10008)" | **New**, unowned — opened 2026-07-13 by **khokansardar** | ✅ Verified 2026-08-16. Enhancement, milestone **Awaiting Review**, focuses `rest-api` + `performance`, keywords **`early`**, **`2nd-opinion`**. **Zero comments** — no committer response, no patch, nothing rejected. Gap analysis independently matches ours. Proposes `QUERYABLE`, a `QUERY → GET` fallback ([ADR 0002](docs/decisions/0002-allmethods-vs-queryable.md) option E), and route-derived CORS advertising. See [scope.md](docs/scope.md) Q3. |
| [Requests#1074](https://github.com/WordPress/Requests/issues/1074) | WpOrg/Requests | "Support fror QUERY HTTP method" *[sic]* | **Open** — opened 2026-08-10 | Milestone **2.1.0**. Component: Core, Type: enhancement. Links to Trac#65616. |
| [Requests#1075](https://github.com/WordPress/Requests/pull/1075) | WpOrg/Requests | "Add QUERY HTTP method to the list of allowed methods" | **Open PR** — opened 2026-08-10 | Implements #1074: adds the constant + a helper method. Author **dingo-d**, branch `feature/add-query-http-method`. Triaged by **jrfnl** (Status: triage, milestone 2.1.0), who asked for canonical RFC links — addressed in `9483ea8`. |
| [test-server#13](https://github.com/RequestsPHP/test-server/pull/13) | RequestsPHP/test-server | Companion to #1075 | Open | Blocks CI verification of #1075; author reports local testing issues pending this. |

Three consequences worth carrying:

- **We are not opening a fresh proposal anywhere.** Trac#65616 frames the core-side ask;
  Requests#1074/#1075 cover the client side. The project's job is to supply evidence and
  patches, and to engage the existing threads rather than duplicate them.
- **The Requests work is already in flight, and not by us.** [scope.md](docs/scope.md) §5.1
  called the Requests dependency the longest lead-time item and said to start it first. That
  is **no longer true** — #1075 is open with a maintainer triaging it and a 2.1.0 milestone.
  Our contribution there is review, testing, and unblocking, not authorship. Sequencing
  updated accordingly.
- **#1075 is CI-blocked on test-server#13.** That is the concrete place outside help is useful,
  and the most likely thing to stall the 2.1.0 milestone.

> `WordPress/Requests` and `WpOrg/Requests` both resolve; GitHub redirects between them.
> Docs use `WpOrg/Requests` as canonical.

### CORS prior art (cited, not driven)

Gap 1's supporting record, all read in full on 2026-08-16. Reasoning in
[scope.md](docs/scope.md) §2.

| Ticket | State | Why it matters |
|---|---|---|
| [Trac#43428](https://core.trac.wordpress.org/ticket/43428) | **Open**, Awaiting Review, unowned, stalled since 2018 | CORS spec-correctness and preflight performance: preflight headers only on `OPTIONS`, drop `OPTIONS` from the method list, add `Access-Control-Max-Age`. **Not our ask** — it does not concern overriding the value. Read in full 2026-08-17; an earlier reading of its "one place, with one hook" phrase as our gap-1 ask was wrong. **Not the venue for [#16](../../issues/16)** — that went to the reopened #46992. Compatible, not competing |
| [Trac#46992](https://core.trac.wordpress.org/ticket/46992) | Closed `invalid`, 2019 | Closed citing `rest_post_dispatch` — but **TimothyBlynJacobs raised our exact objection in comments 5 and 7 and it was never rebutted.** The close answers a different question than the ticket asked. Cite him, don't re-derive it. |
| [Trac#38546](https://core.trac.wordpress.org/ticket/38546) | **Fixed**, 4.7, [39042] | PATCH added to the CORS list because "editable resources accept PATCH but the CORS headers don't mention it." Our argument, verb swapped. Landed in days, uncontested. |
| [Trac#57752](https://core.trac.wordpress.org/ticket/57752) | **Fixed**, 6.3, [56096] | Improved the two *sibling* CORS filters in 2023 and left `Access-Control-Allow-Methods` out again. Best evidence the omission is oversight, not policy. |
| [Trac#38060](https://core.trac.wordpress.org/ticket/38060) | **Fixed**, 4.7, [38806] | Source of the lone `$replace = false` on `Vary: Origin`. A `Vary`-specific correctness need — **do not cite it as deliberate intent about header replacement.** |

**Nobody has reported the clobber.** Four searches turned up nothing describing the ordering
problem, so [#16](../../issues/16) is a new finding — but the weaker claim it rests on
("`rest_post_dispatch` doesn't reach CORS headers") is 2019 committer testimony and should be
quoted as such.

### Ecosystem tickets (watched, not gating)

Tracked because they move the readiness date, not because the project waits on them
([scope.md](docs/scope.md) §3). Detail and dates in [docs/ecosystem.md](docs/ecosystem.md).

| Ticket | Relevance | State |
|---|---|---|
| [nginx#1488](https://github.com/nginx/nginx/pull/1488) | `NGX_HTTP_QUERY` method identifier | Open |
| [whatwg/fetch#1938](https://github.com/whatwg/fetch/issues/1938) | Fetch integration: normalization, CORS, caching | Open — "needs implementer interest" |
| [mozilla/standards-positions#1430](https://github.com/mozilla/standards-positions/issues/1430) | Mozilla position | Open, **no position label**; informally sequenced behind Fetch/HTML |
| [WebKit/standards-positions#709](https://github.com/WebKit/standards-positions/issues/709) | WebKit position | **Open, no position label yet.** Re-filed by this project 2026-08-16, superseding [#692](https://github.com/WebKit/standards-positions/issues/692) (closed `invalid` — wrong issue template, never re-filed) |
| [whatwg/html#12594](https://github.com/whatwg/html/issues/12594) | `<form method="query">` | Open — `needs implementer interest` |
| [undici#5459](https://github.com/nodejs/undici/pull/5459) | Body-aware cache key | Shipped |

---

## Layout

```
docs/
  scope.md            Project scope, current state of trunk, open questions
  ecosystem.md        Ecosystem support status — time-sensitive, dated, re-verified
  decisions/          ADRs for contested design calls
matrix/
  probe/              Bare SAPI probe (no WordPress) — the make-or-break test
  stacks/             Per-web-server configs
  compose.yaml        The stack matrix
  run.sh              Runs everything, emits results/results.json
  results/            Machine-readable results + generated MATRIX.md
plugin/               The feature plugin: QUERY on the REST API, stock core, no patch
tests/                Decision tests (vitest, HTTP) run against both wp-env sites
scripts/env.sh        Drives the two wp-env environments; wrapped by the Makefile
```

---

## Status

| Workstream | State |
|---|---|
| Scope defined | Done — [docs/scope.md](docs/scope.md) |
| Trunk gap analysis | Done — three sites identified, verified against `7.2-alpha-63166` |
| Prior-art search (Trac) | **Done 2026-08-16** — [Trac#65616](https://core.trac.wordpress.org/ticket/65616) read in full (new, unowned, no comments, `2nd-opinion`), plus five CORS tickets. The `ALLMETHODS` rationale is **not on Trac and probably was never written down**; ADR 0002 proceeds without it. Scope Q3 answered |
| Test matrix — Axis A (SAPI) | **Run 2026-08-16. `QUERY` + body reach PHP intact on nginx, Apache (both SAPIs) and Caddy**, up to 64 KiB. `php -S` 501s; `GET POST` hardening allowlists 403. [Results](matrix/results/MATRIX.md) |
| Test matrix — Axis B (CDN/WAF) | **Out of scope** — outside the WordPress boundary, and Axis A is sufficient for a readiness claim. See [scope.md](docs/scope.md) §6. **Note the failure mode this created**: the descope was read as licence to assume the worst about caches, which is the mistake ADR 0003 reversed. Documentary evidence — default configs and vendor source — is neither Axis B nor an assumption |
| Test matrix — Axis C (clients) | Partly outstanding; `WP_Http` cases belong with the feature plugin |
| ADR 0002 blast radius | **Run 2026-08-16** against core's REST suite: options A and B change nothing, option D fails 20. **All three numbers are misleading and the ADR says why** — core uses `ALLMETHODS` once, so the suite cannot measure option A, and 14 of D's 20 are a pre-existing core bug. [Experiment](experiments/blast-radius/) · [ADR 0002](docs/decisions/0002-allmethods-vs-queryable.md) |
| ADR 0001 | **Decided 2026-08-19 — option B**, JSON + form-encoded. One line: `'QUERY'` into `$accepts_body_data`. Option D (pluggable per-route formats) declined as unneeded, and free to defer because routes can already parse their own bodies with no core support. **Gap 3 ([#14](../../issues/14)) is unblocked.** [ADR 0001](docs/decisions/0001-query-body-media-type.md) |
| ADR 0002 | **Decided 2026-09-15 — option B**, additive `QUERYABLE = 'QUERY'`; `READABLE` and `ALLMETHODS` untouched, nothing migrates on upgrade. The C → D reversal of 2026-08-19 did not survive [#38](../../issues/38): it rested on "handlers do not break," which was true of the mechanism it had refuted (parameter population) and false of one nobody had looked for. **C was dropped too** — its only distinguishing feature is a promise to fold `QUERY` into `ALLMETHODS` later, and `ALLMETHODS` has the same failure mode at lower volume. **Reach is not settled** and moved to [ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md). [ADR 0002](docs/decisions/0002-allmethods-vs-queryable.md) · [#10](../../issues/10) |
| ADR 0003 | **Decided 2026-08-19 — option C, core emits nothing method-specific.** The threat model was falsified: **no shipping cache stores a `QUERY` response.** Varnish `synth(501)`s it before the origin sees it (8.0 – 9.0.x; 9.1.0+ `pass`es it), nginx's cache-method bitmask holds only `GET`/`HEAD`/`POST`, `mod_cache`/Squid/Fastly/Cloudflare allowlist, and RFC 9111 §3 forbids storing a response whose method the cache has not *understood*. Residual vector: W3TC Pro with REST caching on — **confusion, not disclosure**. Upstream reports written and parked until something actually sends `QUERY` ([#39](../../issues/39), [drafts](docs/submissions/)). This discharged a prerequisite for ADR 0002 option D instead of adding one — moot since 2026-09-15, when 0002 closed on B; the question returns under [ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md) if reach is ever granted. [Survey](experiments/cache-survey/) · [ADR 0003](docs/decisions/0003-cache-safety-default.md) |
| Method-branching risk | **Measured 2026-09-15, and it named a break.** 170 plugins / 16.9M installs branch on the request method; **28–65 plugins / 0.82M–11.96M installs** also use `READABLE`, and the range cannot be narrowed without following each registration to its callback — both bounds were falsified by reading their largest member. Verified case: **Jetpack (3M)** registers three routes `READABLE` whose callbacks are `if ( 'GET' === get_method() ) { read } else { write }`, so a `QUERY` activates modules. Authorization fails *closed*; it is a safe-method violation, not an escalation. This ended the option D lean. [Sweep](experiments/plugin-spike/README.md#method-branching-sweep) · [#38](../../issues/38) |
| Gap 3 behavior measured | **Done 2026-08-16**, unmodified trunk. A `QUERY` with a **JSON** body already populates and schema-validates params correctly — dispatched at the real posts controller it filters the collection and `400`s a bad `per_page`. Only **form-encoded** bodies are lost, and they return the full unfiltered collection with a `200`. Gap 3 is the only gap whose failure is a silent wrong answer, so it should be patched first. [Probes](experiments/blast-radius/) |
| Core bug found (unrelated to `QUERY`) | `register_rest_route()` does not comma-split `methods` given in array form, so `array( READABLE, EDITABLE )` registers the bogus key `'POST, PUT, PATCH'` and `POST` 404s. Confirmed on unmodified trunk, [failing test written](experiments/blast-radius/rest-array-methods-probe.php). Latent in core — its one array-form route uses single-method constants. Measured in the plugin directory: 3 plugins / 2,000 installs hit it today, but 27 plugins / 1.19M installs would break if `READABLE` ever gained a second method. Filed as [Trac#65905](https://core.trac.wordpress.org/ticket/65905), patched in [wordpress-develop#13136](https://github.com/WordPress/wordpress-develop/pull/13136) |
| Requests upstream PR | **In flight upstream, not ours** — [#1075](https://github.com/WordPress/Requests/pull/1075) open, milestone 2.1.0, CI-blocked on [test-server#13](https://github.com/RequestsPHP/test-server/pull/13) |
| Feature plugin | **The deliverable.** All three gaps worked around in userland, with `Accept-Query` and a demo route. Passed 6/6 in a phpunit harness on unmodified trunk (2026-09-15, [plugin-spike](experiments/plugin-spike/)). **Never run on a real install** ([#22](../../issues/22)); no packaging yet ([#23](../../issues/23)); opt-in reach built 2026-11-04 ([ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md), [usage](plugin/README.md#choosing-which-routes-accept-query)) |
| CORS clobber ([#16](../../issues/16)) | **Fixed for 7.2** in [r64730](https://core.trac.wordpress.org/changeset/64730) (2026-10-26, adamsilverstein), reworked from [wordpress-develop#13151](https://github.com/WordPress/wordpress-develop/pull/13151). Adds `rest_allowed_cors_methods`, which the plugin uses |
| Core patch (`QUERY`) | **Parked behind the plugin.** ADRs 0001–0003 are decided and both patches (gap 3, `QUERYABLE`) are written on `feat-http-query`, but nothing goes upstream until the plugin has shipped. The branch predates 7.2 and needs a rebase |
| Trac tickets | **Both filed tickets fixed for 7.2:** [#46992](https://core.trac.wordpress.org/ticket/46992) (r64730) and [#65905](https://core.trac.wordpress.org/ticket/65905) (r63756). Checked 2026-11-04. Trac#65616 (the `QUERY` proposal) is untouched and waits on the plugin |

## Quick start

### Plugin tests

```sh
make check      # start the WordPress test sites, then run the decision tests
```

`make up` starts three [wp-env](https://www.npmjs.com/package/@wordpress/env) sites with the
plugin active: **7.0.8** on `:8870` and **7.1.4** on `:8871` (the plugin's pre-7.2 CORS fallback),
and **nightly** on `:8872` (core's `rest_allowed_cors_methods`, r64730). `make test` runs the
vitest suite in [`tests/`](tests/) over real HTTP against all three. `make help` lists the rest. Requires Docker and
Node 20+. **New here? [CONTRIBUTING.md](CONTRIBUTING.md)** walks through setup, the commands and
troubleshooting.

**Each test file encodes a decision**, and its header names the ADR and issue. When a decision
changes, the test changes with it, on purpose. Tests for decisions the plugin doesn't meet yet
are marked `it.fails` (none today), so the suite stays green while keeping the gap visible. Tests
that change site state (deactivating the plugin, writing options) run afterwards, one at a time,
from [`tests/lifecycle/`](tests/lifecycle/).

### Compatibility matrix

```sh
cd matrix && ./run.sh
```

Requires Docker and `jq`. Results land in `matrix/results/`. See
[matrix/README.md](matrix/README.md).

---

## Principles

1. **Plugin before patches.** Ship the capability as a plugin, then arrive at Trac with usage,
   matrix data and a working plugin, not an argument.
2. **Publish negative results.** A stack that drops `QUERY` is the most useful thing this
   project can document.
3. **Date everything time-sensitive.** The ecosystem is moving weekly. Undated claims rot
   silently.
4. **Additive only.** No existing route changes behavior. See ADR 0002. This applies to the plugin
   too: reach is opt-in, never all routes by default
   ([ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md)).
