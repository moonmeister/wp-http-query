# ADR 0002 — Should QUERY join ALLMETHODS, or be opt-in only?

**Status:** Proposed — undecided
**Date:** 2026-08-16
**Blocks:** core patch (gap 2)
**Related:** [scope.md](../scope.md) Q3 — the original rationale for the current constant set is unknown

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

## Recommendation (not yet decided)

Lean **C**: ship `QUERYABLE`, leave `ALLMETHODS` and `READABLE` untouched, and record the
intent to revisit. This is the version most likely to survive review, and the project's whole
posture is additive-only ([README](../../README.md) principle 4).

**Option D should be explicitly ruled out in the ticket** — someone will propose it because
the semantics look right, and its blast radius is the worst of any option.

## Open

- **Blocked on scope.md Q3.** If there was a deliberate historical decision to restrict the
  constant set, that reasoning should drive this ADR rather than being rediscovered.
- What actually happens today when an `ALLMETHODS` route receives a `QUERY` with a JSON body?
  Given that JSON parsing is method-independent, params may populate fine — which would weaken
  the BC objection to A considerably. **Testable now; worth doing before deciding.**
- Does `EDITABLE`/`CREATABLE` need any statement, or is silence correct?
