# ADR 0002 — Should QUERY join ALLMETHODS, or be opt-in only?

**Status:** Proposed — undecided, but **no longer blocked**
**Date:** 2026-08-16
**Blocks:** core patch (gap 2)
**Related:** [scope.md](../scope.md) Q3 — answered 2026-08-16; see "Open" below

---

## Context

`WP_REST_Server` defines five method constants (`class-wp-rest-server.php:24-56`):

```php
const READABLE   = 'GET';
const CREATABLE  = 'POST';
const EDITABLE   = 'POST, PUT, PATCH';
const DELETABLE  = 'DELETE';
const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
```

None include `QUERY`. A route registered with `ALLMETHODS` will not answer a `QUERY` request.

`ALLMETHODS` is widely used in the wild — it is the obvious choice for a route author who
wants "any method" and does not want to enumerate. **Adding `QUERY` to it would silently change
the behavior of every such route in core and in every plugin**, causing handlers written with
no awareness of `QUERY` to begin receiving `QUERY` requests.

This is a backward-compatibility question with a genuine argument on both sides, and it is the
single most likely thing to sink the patch if we have not thought it through publicly.

## Decision drivers

- WordPress's backward-compatibility bar is very high. "Existing code changes behavior on
  upgrade" is close to a veto.
- `ALLMETHODS` means, literally, all methods. Permanently excluding a registered IANA method
  makes the constant a lie and pushes the problem to every future method too.
- `QUERY` is **safe and idempotent**. A handler that was willing to serve `GET` is *usually*
  safe to serve `QUERY` — but "usually" is not a BC guarantee, and the handler will not have
  parsed the body.
- A route that opted into `ALLMETHODS` and receives an unexpected `QUERY` would run its
  callback with parameters it never populated. Failure mode is probably an empty result set
  rather than a crash — but that is a guess and should be tested.
- Whatever we choose sets precedent for the next method.

## Options

### A. Add `QUERY` to `ALLMETHODS`

```php
const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE, QUERY';
```

- **+** The constant stays honest.
- **+** Zero work for route authors; `QUERY` "just works" everywhere.
- **−** Behavior change on upgrade for every `ALLMETHODS` route in the ecosystem.
- **−** Handlers receive a method they were not written for, with a body they will not parse.
- **−** Hardest version to defend in review.

### B. New `QUERYABLE` constant, leave `ALLMETHODS` alone

```php
const QUERYABLE = 'QUERY';
```

- **+** Strictly additive. No existing route changes behavior. Easiest to land.
- **+** Route authors opt in explicitly, which matches the fact that they must also handle the
  body.
- **−** `ALLMETHODS` becomes formally incorrect.
- **−** Two things to remember: authors must know `QUERYABLE` exists.

### C. `QUERYABLE` now, `ALLMETHODS` in a later major

B, plus a documented intent to fold `QUERY` into `ALLMETHODS` once adoption is real, with a
deprecation-style runway.

- **+** Gets the safe change in now and keeps the honest one on the table.
- **+** Gives the ecosystem time to adapt.
- **−** Requires committing future maintainers to a follow-through that may never happen.

### D. `READABLE` gains `QUERY`

Treat `QUERY` as a read method alongside `GET`, since both are safe and idempotent.

- **+** Semantically the tightest fit — `QUERY` genuinely is a read.
- **+** Read handlers are the ones most likely to be safe under `QUERY`.
- **−** Same BC problem as A, and `READABLE` is used far more than `ALLMETHODS`. Strictly worse
  on blast radius.
- **−** A `READABLE` handler reads `$request['param']` from the query string; it will not see a
  body. Silent empty results across a huge surface.

### E. `QUERYABLE` plus an optional `QUERY → GET` route fallback

From [Trac#65616](https://core.trac.wordpress.org/ticket/65616) itself, which we had not
considered. Core already has a precedent for one method falling back to another: an unmatched
`HEAD` falls back to the `GET` handler (`class-wp-rest-server.php:1190-1196`). The ticket
proposes mirroring that — an unmatched `QUERY` may fall back to a registered `GET` handler.

- **+** Answers "what does this buy anyone when no endpoint implements `QUERY`?" without
  migrating a single route. Every existing read endpoint becomes reachable by `QUERY`.
- **+** Follows a pattern core already ships and reviewers already accept.
- **+** Orthogonal to the constants question — it composes with B or C rather than replacing
  them.
- **−** **It is not the same as `HEAD → GET`, and the difference is the whole problem.** `HEAD`
  is `GET` minus the body, so the fallback is lossless. `QUERY` is `GET` *plus* a request body,
  and the `GET` handler will not read it. Falling back silently discards the query.
- **−** That failure is silent and returns a plausible-looking unfiltered result set — arguably
  worse than the `404` we have today, and on read endpoints an unfiltered result set can be a
  disclosure rather than merely a wrong answer.
- **−** Only defensible if gap 3 is fixed first so body params populate, and even then the
  handler's `get_collection_params()` never declared them.

**Assessment:** the ergonomics are attractive and the safety argument is bad. If it is pursued
at all it must be **opt-in per route** and it must not be conflated with the constants
decision — which is why it is recorded here as a separate axis rather than a fifth alternative.
Expect the ticket reporter to advocate for it; engage on the silent-discard point specifically,
because the `HEAD → GET` analogy is superficially very persuasive.

## Recommendation (not yet decided)

Lean **C**: ship `QUERYABLE`, leave `ALLMETHODS` and `READABLE` untouched, and record the
intent to revisit. This is the version most likely to survive review, and the project's whole
posture is additive-only ([README](../../README.md) principle 4).

**Option D should be explicitly ruled out in the ticket** — someone will propose it because
the semantics look right, and its blast radius is the worst of any option.

## A cheap experiment that turns this argument into a number

Core's test suite hard-codes the current method sets in exact-match assertions:

- `rest-posts-controller.php:256` — `assertSame( $headers['Allow'], 'GET' )`
- `rest-posts-controller.php:266` — `assertSame( $headers['Allow'], 'GET, POST, PUT, PATCH, DELETE' )`
- `rest-attachments-controller.php:374, 383` — same pattern
- `rest-server.php:397, 437, 476, 536`

These are a **tripwire**. Adding `QUERY` to one endpoint breaks none of them; option A breaks
the `ALLMETHODS` assertion immediately; option D breaks every `'GET'` assertion across all
three files.

So: implement each option on a scratch branch and run the suite. The failure count is a direct,
citable measure of blast radius, and it converts "this might break things" into evidence —
which is the posture the whole project is built on. **Do this before the ADR is decided.**

Worth noting the tripwire only catches core. Plugin breakage is unmeasurable this way and has
to be argued rather than counted.

## Open

- ~~**Blocked on scope.md Q3.**~~ **Unblocked 2026-08-16.** Trac#65616 was read in full: it does
  **not** contain the `ALLMETHODS` rationale, and no Trac ticket discussing the original constant
  set or an earlier custom-verb attempt could be found. The constants pre-date core, so any
  recorded reasoning would live in `WP-API/WP-API` history, not Trac. **Proceed on the merits.**
  Do not assert the restriction was deliberate — that claim is unsupported, and a reviewer who
  knows the history will say so.
- **The ticket itself proposes `QUERY`/`QUERYABLE`** (its phase 1), which is options B/C. Our
  lean and the reporter's proposal already agree, so the ADR's job is now to *defend* that choice
  against option A rather than to pick between them.
- What actually happens today when an `ALLMETHODS` route receives a `QUERY` with a JSON body?
  Given that JSON parsing is method-independent, params may populate fine — which would weaken
  the BC objection to A considerably. **Testable now; worth doing before deciding.**
- Does `EDITABLE`/`CREATABLE` need any statement, or is silence correct?
