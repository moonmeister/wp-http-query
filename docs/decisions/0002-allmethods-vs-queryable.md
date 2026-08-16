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

**Option D should be explicitly ruled out in the ticket** — someone will propose it because the
semantics look right. Rule it out on the silent-body-discard argument across all 77 `READABLE`
routes, **not** on its measured failure count; the count is 20 but decomposes into one unrelated
core bug and six fixture updates, and anyone who checks will say so.

## Measured blast radius

✅ **Run 2026-08-16.** Each option was patched into `WP_REST_Server` one at a time and the REST
suite run against it. Isolation: a detached `git worktree` at baseline `e7739d5414`, its own
MySQL schema (`wordpress_develop_tests_exp`), `phpunit --group restapi`, 3550 tests. Runner and
raw output are kept outside this repo in the scratch worktree (`run-blast-radius.sh`,
`blast-radius/`).

| Variant | Patch | Result | New failures vs baseline |
|---|---|---|---|
| baseline | none | Errors 1, Warnings 4, Skipped 6 | — |
| **A** | `ALLMETHODS` += `QUERY` | identical to baseline | **0** |
| **B** | new `QUERYABLE = 'QUERY'` | identical to baseline | **0** |
| **D** | `READABLE` = `'GET, QUERY'` | Failures 20 | **20** |

### The experiment's premise was wrong, and that is the most useful thing it produced

This ADR previously predicted that "option A breaks the `ALLMETHODS` assertion immediately."
**It does not — option A breaks nothing at all.** Two reasons:

1. **Core uses `ALLMETHODS` exactly once.** Constant usage across `src/`: `READABLE` 77,
   `CREATABLE` 35, `EDITABLE` 28, `DELETABLE` 13, **`ALLMETHODS` 1** — the Abilities API run
   controller (`class-wp-rest-abilities-v1-run-controller.php:64`), which registers `ALLMETHODS`
   only because routes are registered before plugins register abilities.
2. **That one route self-defends.** Its `permission_callback` calls `validate_request_method()`
   (`:153`), which computes the expected method from the ability's annotations and rejects
   anything else with `rest_ability_invalid_method`. A `QUERY` request reaches it and is turned
   away before the callback runs. (This also retires an earlier worry of ours that option A would
   expose `destructive` abilities over `QUERY`. It would not.)
3. The `'GET, POST, PUT, PATCH, DELETE'` assertions the tripwire was built on do not come from
   `ALLMETHODS` at all. They come from `READABLE` + `EDITABLE` + `DELETABLE` **composing** on
   `/wp/v2/posts/{id}`.

**Therefore: the core suite cannot measure option A.** A "0 failures" result for A is not
evidence that A is safe — it is evidence that core barely uses the constant whose ecosystem-wide
use is the entire objection. Reporting `A: 0` without this paragraph would manufacture false
confidence, and a reviewer who checks will find it in one grep. **Do not cite the A and B numbers
as a safety argument.** The honest statement is: *core does not exercise `ALLMETHODS`; the BC risk
for A lives entirely in plugins and is unmeasured.*

### Option D's 20 failures are also not what they look like

Decomposed:

- **14** — `REST_Block_Renderer_Controller_Test`, all `404 is identical to 200`. Not fixture
  churn; the route stopped existing. Cause is a **latent core bug**, below.
- **6** — assertion text only, and all six are core *correctly* advertising the new method:
  `Allow: GET, QUERY` on two OPTIONS tests, `QUERY` added to `targetHints`, to the block-directory
  schema, and to the site-health route's method map. Every one is a fixture that needs updating,
  not a behavior that broke.

So option D's real score is **one core bug plus six fixture updates — zero genuine behavioral
breakage.** That is much weaker evidence against D than `20` suggests, and the case against D has
to rest on semantics rather than the count (see below).

### The latent core bug this uncovered

`register_rest_route()` normalizes `methods` at `class-wp-rest-server.php:1008-1020`:

```php
if ( is_string( $handler['methods'] ) ) {
    $methods = explode( ',', $handler['methods'] );   // split
} elseif ( is_array( $handler['methods'] ) ) {
    $methods = $handler['methods'];                    // NOT split
}
foreach ( $methods as $method ) {
    $handler['methods'][ strtoupper( trim( $method ) ) ] = true;
}
```

The array branch never splits on commas, so any array element containing a comma becomes one
bogus method key that can never match. `array( READABLE, EDITABLE )` registers the literal key
`'POST, PUT, PATCH'`, and `POST` to that route 404s.

**Confirmed on unmodified trunk** with a three-case probe (single-method constants in an array:
passes; string form with `EDITABLE`: passes; array form with `EDITABLE`: `404`, and the
registered keys are exactly `['GET', 'POST, PUT, PATCH']`). This is independent of `QUERY`.

Core is not broken today: it has exactly one array-form registration with constants — the block
renderer's `array( READABLE, CREATABLE )` — and both are single-method. It is a landmine, not a
live fire. Option D steps on it by making `READABLE` multi-method, which is the whole of those 14
failures.

This is separately reportable, has a failing test, and does not depend on any `QUERY` decision —
the same shape of independently-landable artifact as the CORS clobber (#16).

### What the experiment does and does not settle

- It **does not discriminate between A, B and C.** All three are 0 in core. The decision must be
  argued on ecosystem BC and on what the constants are supposed to *mean*, not on failure counts.
- It **does not vindicate option D either.** The strongest objection to D was never the count: it
  is that D makes all 77 `READABLE` routes advertise and accept `QUERY`, dispatching to handlers
  that read `$request['param']` from the query string and will never look at the body. That is
  the option E silent-discard failure, applied to the entire read surface by default. **No test in
  core sends a `QUERY` with a body, so the suite cannot see this.** The recommendation to rule out
  D stands, on those grounds.
- Plugin breakage remains unmeasurable this way and has to be argued rather than counted. Say this
  before someone else says it.

Minor finding for core, noted in passing: `rest-attachments-controller.php:374` and
`rest-posts-controller.php:256` write `assertSame( $headers['Allow'], 'GET' )` — arguments
reversed, so PHPUnit reports actual as expected. Harmless, but it makes those two diffs read
backwards.

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
  the BC objection to A considerably. **Still open, and now the decisive question**, since the
  measurement did not discriminate between A, B and C. Note core's own single `ALLMETHODS` route
  cannot answer it: it rejects non-matching methods in its `permission_callback`. This needs a
  synthetic route registered in a test, not a core endpoint.
- Does `EDITABLE`/`CREATABLE` need any statement, or is silence correct?
- Report the array-form normalization bug to core? It is real, confirmed on trunk, and has a
  failing test — but it was found while measuring `QUERY`, and filing it needs the same
  "framed without reference to `QUERY`" treatment as #16.
