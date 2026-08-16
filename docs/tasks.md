# Tasks: what needs contributing, and where

**Last updated: 2026-08-16.**

This project's work lands in **six different places**, only one of which we control. This file
is the map. [scope.md](scope.md) says what the project is; this says who does what, where, and
in what order.

Ticket state lives in the [README](../README.md#tracked-tickets). Design rationale lives in
[decisions/](decisions/). Neither is duplicated here — tasks link out.

**Status:** ⬜ not started · 🟡 in progress · ✅ done · ⛔ blocked · 🚫 won't do

---

## The critical path

Everything else is parallelisable. This is the chain that gates a core patch:

```
T1 Read Trac#65616 ──┐
                     ├──> T3 Decide ADR 0002 ──┐
T2 ADR 0002 experiment ┘                        ├──> T6 Write the core patch ──> T8 Attach to Trac
                                                │
T4 Decide ADR 0001 ─────────────────────────────┘
```

**T1 is the only task nothing else can start without, and it needs a human with a browser.**

`QUERY` reaching PHP was the project's gating unknown and it is now answered (see
[scope.md](scope.md) Q2), so **no remaining blocker is empirical**. What is left is one
unreadable ticket, four decisions, and the patch.

---

## Venue 1 — This repo (`wp-http-query`)

Ours. No external review, no waiting.

| ID | Task | Status | Blocked by |
|---|---|---|---|
| T2 | ADR 0002 blast-radius experiment | ⬜ | — |
| T3 | Decide [ADR 0002](decisions/0002-allmethods-vs-queryable.md) — `ALLMETHODS` vs `QUERYABLE` | ⬜ | T1, T2 |
| T4 | Decide [ADR 0001](decisions/0001-query-body-media-type.md) — body media type | ⬜ | — |
| T5 | Decide [ADR 0003](decisions/0003-cache-safety-default.md) — cache-safety default | ⬜ | — |
| T5b | Decide [ADR 0004](decisions/0004-location-indirection.md) — `Location` indirection | ⬜ | — |
| T9 | Run the feature plugin against a real WordPress install | ⬜ | — |
| T10 | Axis C — client testing | ⬜ | — |
| T11 | Axis A leftovers — HTTP/2, HTTP/3, TLS, OpenLiteSpeed | ⬜ | — |

### T2 — ADR 0002 blast-radius experiment

**The cheapest unblock in the project.** Core's test suite hard-codes method sets in exact-match
`Allow` assertions (`rest-posts-controller.php:256,266`, `rest-attachments-controller.php:374,383`,
`rest-server.php:397,437,476,536`). They are a tripwire: implement each ADR 0002 option on a
scratch branch, run the suite, and the failure count *is* the blast radius.

Expected: option B (new `QUERYABLE`) breaks nothing; option A (`ALLMETHODS`) breaks the
`'GET, POST, PUT, PATCH, DELETE'` assertion; option D (`READABLE`) breaks every `'GET'`
assertion across all three files.

Converts "this might break things" into a number, which is the posture the whole project runs
on. Note the limit: it measures **core only**. Plugin breakage has to be argued, not counted.

*Scratch branches only — they must not reach the core fork's `feat-http-query` branch.*

### T4 — Decide ADR 0001

Closest to decidable. The matrix confirmed its premise: PHP never populates `$_POST` for
`QUERY`, and `parse_body_params()` parses the raw body precisely for "request methods that
aren't supported natively by PHP." Option B is genuinely one line.

### T5 — Decide ADR 0003

Narrowed by the Axis B descope. With no evidence any downstream cache is body-aware, core must
assume none is; option C is now indefensible. What remains is A vs B and whether D rides along.

### T9 — Feature plugin against a real install

`plugin/wp-http-query.php` is **written but has never been executed.** It works around all three
gaps in userland, so it should demonstrate the end-to-end path on stock core with no patch —
which is the single strongest artifact to bring to Trac. Until it has actually run, that is a
claim, not a demonstration.

Verify: a native `QUERY` route round-trips; the `X-HTTP-Method-Override` fallback works;
`Accept-Query` is emitted in valid Structured Fields syntax; the CORS preflight passes; the
`private, no-cache` default lands.

### T10 — Axis C, client testing

| Client | Question |
|---|---|
| `WP_Http` / Requests — cURL transport | Does `wp_remote_request( $url, [ 'method' => 'QUERY', 'body' => … ] )` work end to end? |
| `WP_Http` / Requests — fsockopen | Same |
| `@wordpress/api-fetch` | Q7 — **genuinely unknown.** Research claims were refuted or split; must be read against source |
| Browser `fetch()` | Chrome / Firefox / Safari, including the lowercase-`'query'` normalization trap |

The `WP_Http` cases need a WordPress install, so they belong with T9 rather than the matrix
harness.

### T11 — Axis A leftovers

Low value. The verb and body are opaque to the framing layer and both SAPIs already agree, so
HTTP/2, HTTP/3 and TLS are unlikely to change the answer. OpenLiteSpeed needs a build step.
Do these only if a reviewer asks.

---

## Venue 2 — WordPress core (Trac + `wordpress-develop`)

The destination. **The fork stays pristine** — see [README](../README.md#repos).

| ID | Task | Status | Blocked by |
|---|---|---|---|
| T1 | Read [Trac#65616](https://core.trac.wordpress.org/ticket/65616), fill in the README row, finish Q3 | ⛔ | **needs a human** |
| T6 | Write the core patch — the three gaps | ⬜ | T3, T4 |
| T7 | Update the affected core tests | ⬜ | T6 |
| T8 | Engage Trac#65616 with patch + plugin + matrix | ⬜ | T6, T7, T9 |

### T1 — Read Trac#65616 ⛔ **HUMAN REQUIRED**

**No tool on this project can fetch WordPress Trac.** It returns HTTP 403 with a "Checking your
browser…" challenge to `curl` and to automated fetchers alike. Confirmed twice, two different
ways.

Only the ticket *number* is known. Its title, status, component, author and contents are all
**unverified**, and the README says so explicitly. This matters beyond bookkeeping: a project
whose pitch is *arrive with verified evidence* citing a ticket with invented details is exactly
the failure that would discredit it in the thread.

Open it in a browser and record: title, status, component, milestone, reporter, and — most
importantly — **whether it contains the original rationale for the `ALLMETHODS` constant set**,
which is Q3 and gates T3.

Then update the README row and [scope.md](scope.md) Q3.

### T6 — The core patch

Three gaps, verified against `7.2-alpha-63166-src` @ `e7739d5414`. Line numbers **will drift** —
re-verify before filing.

| Gap | Site | Change | Gated by |
|---|---|---|---|
| 1 | `rest-api.php:814` | `Access-Control-Allow-Methods` is hardcoded with no filter; every cross-origin `QUERY` preflight fails | — |
| 2 | `class-wp-rest-server.php:24-56` | No method constant includes `QUERY` | ADR 0002 (T3) |
| 3 | `class-wp-rest-request.php:377-380` | `$accepts_body_data` excludes `QUERY`; form-encoded bodies are parsed then silently dropped | ADR 0001 (T4) |

Gap 1 is independently landable and has no ADR behind it — **it may be worth proposing on its
own.** A hardcoded header list with no filter is a defect regardless of `QUERY`, and a small
self-contained patch is far easier to land than a feature.

Also in scope for the patch: `Accept-Query` advertisement and the ADR 0003 cache-safety default.

### T7 — Core tests

New coverage for whatever T6 does, plus updating the exact-match `Allow` assertions T2 will
have already mapped. Anything touching `ALLMETHODS` or `READABLE` needs BC tests proving
existing routes are unaffected.

### T8 — Engage the ticket

Arrive with the patch, the working plugin (T9), and the matrix results. Not an argument.

Two things to state explicitly in the thread, because they will otherwise be raised as
objections: that [option D is ruled out](decisions/0002-allmethods-vs-queryable.md) and why, and
the [security reasoning behind the cache default](decisions/0003-cache-safety-default.md) — a
security-relevant default that merely looks conservative will get "simplified" by a later
contributor.

---

## Venue 3 — `WpOrg/Requests`

**Not our authorship.** [PR #1075](https://github.com/WordPress/Requests/pull/1075) by `dingo-d`
is open with a maintainer triaging it and a 2.1.0 milestone. Earlier scope drafts called this
the longest-lead item and said to start it first; that is obsolete.

| ID | Task | Status |
|---|---|---|
| T12 | Review #1075 — verify it covers what WordPress needs | ⬜ |
| T13 | Confirm `WP_Http`'s docblock contract gets updated downstream | ⬜ |

`QUERY` already transits both transports today — `data_format` defaults to `'body'` for it,
cURL falls through to `CURLOPT_CUSTOMREQUEST`, and fsockopen interpolates the verb verbatim. The
gap is **contract, not capability**: no `Requests::QUERY` constant, and `class-wp-http.php:115-118`
documents a method list that excludes it.

> **Core does not accept direct patches to vendored dependencies.** Anything in
> `src/wp-includes/Requests/` must land upstream first and arrive via a version bump. This is
> why the Requests track has to run ahead of the core track, even though it is not ours.

---

## Venue 4 — `RequestsPHP/test-server`

| ID | Task | Status |
|---|---|---|
| T14 | Help unblock [test-server#13](https://github.com/RequestsPHP/test-server/pull/13) | ⬜ |

#13 is gating CI on Requests#1075, and is the most likely thing to stall the 2.1.0 milestone.
**This is the single most useful place outside help can go**, because it is a concrete blocker
on someone else's already-moving work rather than a proposal needing consensus.

---

## Venue 5 — Browser standards positions

Not WordPress work, and nothing here gates the project. Listed because the project tracks
ecosystem readiness and these are the few levers that exist.

| ID | Task | Status |
|---|---|---|
| T15 | Re-file the WebKit position request using the correct template | ⬜ |

[WebKit#692](https://github.com/WebKit/standards-positions/issues/692) was closed `invalid` for
using custom formatting instead of the issue template, with an explicit invitation to re-file —
and never was. **WebKit has no position on `QUERY`; it has not been asked in a form its tooling
accepts.** Re-filing costs minutes. See [ecosystem.md](ecosystem.md#standards-positions).

Mozilla [#1430](https://github.com/mozilla/standards-positions/issues/1430) needs nothing from
us — it is informally sequenced behind Fetch and HTML forms, which is reasonable.

---

## Venue 6 — User-facing documentation

| ID | Task | Status |
|---|---|---|
| T16 | Document the hardening-allowlist failure mode | ⬜ |

The matrix showed that `limit_except GET POST` (nginx) and `<LimitExcept GET POST>` (Apache)
both **403** `QUERY`. This is the first wall a real site owner will hit, it is site
configuration rather than a WordPress defect, and no patch can fix it. It needs a documented
answer before anyone is told the feature works — otherwise the first bug reports will be
against WordPress.

Also worth documenting: `php -S` returns 501, which will confuse contributors and CI.

---

## Explicitly not doing

Recorded so they are not rediscovered as gaps. Full reasoning in [scope.md](scope.md) §3.

| | Why |
|---|---|
| 🚫 Matrix Axis B — CDNs, WAFs, managed hosts | Outside the WordPress boundary; Axis A is sufficient for a readiness claim. [scope.md](scope.md) §6 |
| 🚫 Designing a query language | [ADR 0001](decisions/0001-query-body-media-type.md) option C, deferred |
| 🚫 Migrating existing core endpoints | Capability, not adoption. `/wp/v2/search` is named-but-deferred first-adopter work |
| 🚫 Lobbying nginx, CDNs, or hosts | Tracked, not gated on |
| 🚫 Authoring the Requests PR | #1075 already exists and is being triaged |
