# ADR 0003 — What cache headers should a QUERY response emit by default?

**Status:** Proposed — undecided
**Date:** 2026-08-16
**Security-relevant:** yes — cache poisoning
**Related:** [ADR 0004](0004-location-indirection.md)

---

## Context

RFC 10008 §2.7 requires a cache to key `QUERY` responses on the **request content**, not just
the URI:

> The cache key for a QUERY request MUST incorporate the request content and related metadata.

WordPress is an origin server; it does not construct cache keys. But that requirement binds
only caches that *know about `QUERY`*. A cache that does not may fall back to keying by URI
alone — and then two different request bodies sent to the same URI collide, and **one user's
result set is served to another**.

**There is no `Vary` mechanism that protects against this.** `Vary` operates on request
headers; there is no `Vary: body`. So WordPress cannot delegate the defense.

Current trunk behavior is not safe by default. `rest_send_nocache_headers` defaults to
`is_user_logged_in()` (`class-wp-rest-server.php:487`), so **anonymous REST responses carry no
explicit `Cache-Control` at all**, leaving intermediaries to apply heuristic caching. That is
tolerable for URI-keyed `GET`. It is not tolerable for body-keyed `QUERY`.

As of 2026-08-16, no CDN appears to implement body-inclusive cache keys, so essentially every
cache in front of a WordPress site today is body-blind.

**We will not be measuring this.** Matrix Axis B (CDNs, WAFs, managed hosts) was descoped on
2026-08-16 — see [scope.md](../scope.md) §6. That is the right call for the project's boundary,
and it settles this ADR's threat model rather than leaving it open: **core must assume every
downstream cache is body-blind, because it will never have evidence otherwise.** Option C was
already close to disqualified; with no Axis B data it is indefensible. The remaining question is
only *how* conservative the default is and how an operator opts out — A vs B, and whether D
rides along.

## Decision drivers

- Shipping a cache-poisoning vector to anyone behind a body-blind cache is unacceptable, and
  WordPress's security posture will not tolerate "the operator should configure it correctly."
- But `no-store` by default forecloses the main benefit of `QUERY` — cacheable reads — until
  operators opt in. A feature that is safe and useless is a weak proposal.
- Only the operator knows whether their cache is body-aware. Core cannot detect it.
- Whatever core does must be overridable, and the override must be hard to do by accident.

## Options

### A. `Cache-Control: no-store` by default, opt-in per route

- **+** Unambiguously safe. No poisoning vector ships.
- **+** Opting in is an explicit assertion by someone who knows their cache.
- **−** Forecloses the benefit by default. Every `QUERY` user must do extra work to get the
  thing they came for.
- **−** `no-store` is stronger than needed — it also prevents private/browser caching, which is
  not the hazard.

### B. `Cache-Control: private, no-cache` by default, opt-in per route

- **+** Blocks shared (poisonable) caches while permitting private ones.
- **+** More precise about the actual threat model.
- **−** `private` semantics rely on intermediaries honoring it — a cache confused enough to
  key `QUERY` by URI may also mishandle this.
- **−** Slightly subtler to explain.

### C. Match existing `GET` behavior — emit nothing for anonymous responses

- **+** Consistent with the rest of the REST API. No special case.
- **−** **Ships the poisoning vector.** Almost certainly disqualifying.

### D. Safe default + capability signal

A or B, plus a documented way for the operator to declare their edge is body-aware
(constant, filter, or site-level setting), which flips the default for all `QUERY` routes.

- **+** Safe out of the box, and one switch for operators who have done the work — rather than
  per-route annotation.
- **+** Matches the reality that this is an *infrastructure* property, not a route property.
- **−** A footgun if someone sets it without understanding. Needs loud documentation.
- **−** More surface to design and test.

## Recommendation (not yet decided)

Lean **B + D**: `private, no-cache` by default, with a site-level capability declaration that
routes can further refine. The key insight is that body-aware-ness is a property of the
*deployment*, not of any individual route, so a per-route-only opt-in asks the wrong person.

Whatever is chosen, the reasoning must be in the Trac ticket explicitly — a security-relevant
default that looks merely conservative will otherwise get "simplified" by a later contributor.

## Open

- Does the existing `rest_send_nocache_headers` filter suffice as the mechanism, or does
  `QUERY` need its own so operators can distinguish the two cases?
- Interaction with the `X-HTTP-Method-Override` tunnel: those responses are `POST` on the wire
  and already non-cacheable, so this may not apply. Confirm.
- Interaction with [ADR 0004](0004-location-indirection.md): if the `Location` indirection is
  adopted, the `QUERY` response itself barely needs caching — the GET-able resource carries it.
  **That may make this whole decision much less consequential**, which is an argument for
  settling 0004 first.
- Should core emit a `Warning`-style diagnostic, or log, when a `QUERY` route is cacheable but
  no capability signal is set? Probably too noisy.
