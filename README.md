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

Start with **[docs/scope.md](docs/scope.md)**.

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

## Tracked tickets

Upstream tickets this project drives or is blocked on. **Re-check state before citing** —
see [docs/ecosystem.md](docs/ecosystem.md) for the re-verification protocol.

| Ticket | Where | Title | State | Notes |
|---|---|---|---|---|
| [Trac#65616](https://core.trac.wordpress.org/ticket/65616) | Core, component: REST API | "Support the HTTP `QUERY` method for read/search REST endpoints (RFC 10008)" | Open | **This is the core-side prior art** — see [scope.md](docs/scope.md) Q3. Read before drafting any proposal. |
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

### Ecosystem tickets (watched, not gating)

Tracked because they move the readiness date, not because the project waits on them
([scope.md](docs/scope.md) §3). Detail and dates in [docs/ecosystem.md](docs/ecosystem.md).

| Ticket | Relevance | State |
|---|---|---|
| [nginx#1488](https://github.com/nginx/nginx/pull/1488) | `NGX_HTTP_QUERY` method identifier | Open |
| [whatwg/fetch#1938](https://github.com/whatwg/fetch/issues/1938) | Fetch integration: normalization, CORS, caching | Open — "needs implementer interest" |
| [mozilla/standards-positions#1430](https://github.com/mozilla/standards-positions/issues/1430) | Mozilla position | Deferred |
| [whatwg/html#12594](https://github.com/whatwg/html/issues/12594) | `<form method="query">` | Unresolved |
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
| Prior-art search (Trac) | **Partially answered** — [Trac#65616](https://core.trac.wordpress.org/ticket/65616) exists and is the core-side ask. Still need the `ALLMETHODS` rationale, see scope Q3 |
| Test matrix | Scaffolded, not yet run |
| Requests upstream PR | **In flight upstream, not ours** — [#1075](https://github.com/WordPress/Requests/pull/1075) open, milestone 2.1.0, CI-blocked on [test-server#13](https://github.com/RequestsPHP/test-server/pull/13) |
| Feature plugin | Scaffolded |
| Core patch | Blocked on ADRs 0001, 0002 |
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
