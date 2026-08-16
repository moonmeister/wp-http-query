# wp-http-query

Making WordPress ready for the HTTP `QUERY` method ([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008.html)).

This is the **hub** repo: design docs, decision records, the compatibility test matrix, and
the feature plugin. Code changes to WordPress core and to the Requests library live in forks
of their upstream repos — see [Repos](#repos).

---

## What this project is

A **readiness** project. `QUERY` is a published IETF Proposed Standard, but browsers, web
servers, CDNs, and hosts are all mid-rollout. The goal is that WordPress is not the blocker
when they finish — not to wait for them, and not to claim they are ready.

Concretely: a site owner whose stack supports `QUERY` should be able to use it with WordPress
without patching core, and a site owner whose stack does not should be unaffected.

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
| **wp-http-query** (this) | Docs, ADRs, matrix, plugin | `main` |
| [wordpress-develop](https://github.com/WordPress/wordpress-develop) fork | Core patches | `feat-http-query` |
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

| Capability | Venue | Milestone |
|---|---|---|
| [#1 Method constants & route matching](../../issues/1) — gap 2, the most contested call | core | Core patch v1 |
| [#2 Request body parsing](../../issues/2) — gap 3, one line | core | Core patch v1 |
| [#3 CORS preflight](../../issues/3) — gap 1, independently landable; prior art is **more favorable than it looked** — see [Trac#46992](https://core.trac.wordpress.org/ticket/46992) and [#43428](https://core.trac.wordpress.org/ticket/43428) | core | Core patch v1 |
| [#4 Cache safety & `Accept-Query`](../../issues/4) — security-relevant | core | Core patch v1 |
| [#5 Feature plugin demo](../../issues/5) — the strongest artifact for Trac | this repo | Core patch v1 |
| [#6 PHP client — `WP_Http` / Requests](../../issues/6) — review, not authorship | WpOrg/Requests | Ecosystem |
| [#7 JS clients](../../issues/7) — `api-fetch` and browser `fetch()` | this repo | Ecosystem |

Plus four standalone: [#31](../../issues/31) re-file the WebKit standards position,
[#32](../../issues/32) document the hardening-allowlist 403, [#33](../../issues/33) matrix Axis A
leftovers, [#34](../../issues/34) `/wp/v2/search` as first adopter.

**Milestones.** *Core patch v1* must be done before posting anything to Trac#65616. *Ecosystem*
runs in parallel and gates nothing. *Deferred* is named so it is not rediscovered as a gap.

~~[#8](../../issues/8) — reading Trac#65616 in a browser — is the project's only hard blocker.~~
**Closed 2026-08-16.** The project has no external blockers left; what remains is
[ADRs 0001–0004](docs/decisions/) and the patch.

### Explicitly not doing

Full reasoning in [scope.md](docs/scope.md) §3 and §6.

| | Why |
|---|---|
| Matrix Axis B — CDNs, WAFs, managed hosts | Outside the WordPress boundary; Axis A suffices for a readiness claim |
| Designing a query language | ADR 0001 option C, deferred |
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
| [Trac#43428](https://core.trac.wordpress.org/ticket/43428) | **Open**, Awaiting Review, unowned, stalled since 2018 | Asks to control CORS headers "from only one place, with one hook" — our gap-1 ask, already filed. **Best venue for [#16](../../issues/16); reviving beats opening a new ticket.** |
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
plugin/               Feature plugin — proves the path end-to-end on stock core
```

---

## Status

| Workstream | State |
|---|---|
| Scope defined | Done — [docs/scope.md](docs/scope.md) |
| Trunk gap analysis | Done — three sites identified, verified against `7.2-alpha-63166` |
| Prior-art search (Trac) | **Done 2026-08-16** — [Trac#65616](https://core.trac.wordpress.org/ticket/65616) read in full (new, unowned, no comments, `2nd-opinion`), plus five CORS tickets. The `ALLMETHODS` rationale is **not on Trac and probably was never written down**; ADR 0002 proceeds without it. Scope Q3 answered |
| Test matrix — Axis A (SAPI) | **Run 2026-08-16. `QUERY` + body reach PHP intact on nginx, Apache (both SAPIs) and Caddy**, up to 64 KiB. `php -S` 501s; `GET POST` hardening allowlists 403. [Results](matrix/results/MATRIX.md) |
| Test matrix — Axis B (CDN/WAF) | **Out of scope** — outside the WordPress boundary, and Axis A is sufficient for a readiness claim. See [scope.md](docs/scope.md) §6 |
| Test matrix — Axis C (clients) | Partly outstanding; `WP_Http` cases belong with the feature plugin |
| ADR 0002 blast radius | **Run 2026-08-16** against core's REST suite: options A and B change nothing, option D fails 20. **All three numbers are misleading and the ADR says why** — core uses `ALLMETHODS` once, so the suite cannot measure option A, and 14 of D's 20 are a pre-existing core bug. [Experiment](experiments/blast-radius/) · [ADR 0002](docs/decisions/0002-allmethods-vs-queryable.md) |
| Core bug found (unrelated to `QUERY`) | `register_rest_route()` does not comma-split `methods` given in array form, so `array( READABLE, EDITABLE )` registers the bogus key `'POST, PUT, PATCH'` and `POST` 404s. Confirmed on unmodified trunk, [failing test written](experiments/blast-radius/rest-array-methods-probe.php). Latent — core's one array-form route uses single-method constants. Not yet reported |
| Requests upstream PR | **In flight upstream, not ours** — [#1075](https://github.com/WordPress/Requests/pull/1075) open, milestone 2.1.0, CI-blocked on [test-server#13](https://github.com/RequestsPHP/test-server/pull/13) |
| Feature plugin | Scaffolded |
| Core patch | Blocked on ADRs 0001, 0002 — both now decidable; ADR 0002's external blocker cleared 2026-08-16 |
| Trac ticket | Not filed |

## Quick start

```sh
cd matrix && ./run.sh
```

Requires Docker and `jq`. Results land in `matrix/results/`. See
[matrix/README.md](matrix/README.md).

---

## Principles

1. **Evidence before patches.** Arrive at Trac with matrix data and a working plugin, not an
   argument.
2. **Publish negative results.** A stack that drops `QUERY` is the most useful thing this
   project can document.
3. **Date everything time-sensitive.** The ecosystem is moving weekly. Undated claims rot
   silently.
4. **Additive only.** No existing route changes behavior. See ADR 0002.
