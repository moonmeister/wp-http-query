# ADR 0004 — Should WordPress implement the RFC 10008 §2.4 Location indirection?

**Status:** Proposed — undecided
**Date:** 2026-08-16
**Related:** [ADR 0003](0003-cache-safety-default.md) — settling this first may make 0003 much less consequential

---

## Context

RFC 10008 defines **two** response mechanisms that are easy to conflate:

- **§2.3 `Content-Location`** — identifies a resource corresponding to the *results of the
  operation just performed*. A snapshot. "The indicated resource might be temporary."
- **§2.4 `Location`** — identifies an **equivalent resource** (§2.2): a repeatable query the
  client can `GET` without resending the body. Appendix A.4.2 shows a later `GET` on the
  `Location` URI returning *different* results, because the underlying data changed.

`Location` is the escape hatch from the body-keyed caching problem. RFC co-author Julian
Reschke, on [mozilla/standards-positions#1430](https://github.com/mozilla/standards-positions/issues/1430)
(2026-07-16):

> [it] would make caching completely trivial — it maps QUERY (with payload) to a server
> determined resource to which you can just send GET requests.

Since no CDN implements body-inclusive cache keys today, and none may for years, this
indirection is arguably **the only path to actually cacheable queries on real WordPress
infrastructure**. It converts an uncacheable `QUERY` into an ordinary, universally-understood
cacheable `GET`.

It is also unambiguously origin-server work — which means it is ours, and it may be larger
than the rest of the project combined.

## Decision drivers

- This is the difference between `QUERY` support that is technically correct and `QUERY`
  support that delivers the benefit operators actually want.
- Minting a URI implies storage, a lifetime, and garbage collection. That is real
  infrastructure inside WordPress.
- Scope discipline: [scope.md](../scope.md) §8 sequences the first patch as small and
  additive. This is not small.
- If deferred, nothing is lost architecturally — `Location` can be added later without
  breaking anything, since clients that ignore it still work.

## Options

### A. Do not implement — first patch is method support only

- **+** Keeps the first patch small and landable.
- **+** No storage, lifetime, or GC design required.
- **−** Ships `QUERY` support whose headline benefit (cacheability) is unreachable in practice.
- **−** "Why bother" becomes a harder question to answer in review.

### B. Route-level responsibility, core provides nothing

Routes that want it mint their own URI and set `Location` themselves.

- **+** Zero core surface. Additive and unopinionated.
- **+** Lets the feature plugin prototype the pattern before core commits.
- **−** Every implementer solves storage and GC independently, mostly badly.
- **−** No interoperability or shared convention.

### C. Core helper, opt-in per route

Core provides something like a query-resource registry: a route hands over a normalized query,
gets back a canonical GET-able URI, and core handles storage, lifetime, and cleanup.

- **+** Solves it once, correctly, for everyone.
- **+** Makes `QUERY` genuinely cacheable on stock infrastructure — the strongest possible
  demonstration of the feature's value.
- **−** Significant new subsystem: storage (posts? options? a table? transients?), URI scheme,
  expiry, GC via cron, cache invalidation when underlying data changes.
- **−** Almost certainly cannot land in a first patch.
- **−** Failure modes are nasty — a stale or over-shared query resource is its own information
  leak.

### D. Core helper, but as a feature plugin first

C, developed and proven in the feature plugin, proposed to core separately once the design has
real usage behind it.

- **+** Matches the project's evidence-before-patches principle.
- **+** Decouples it from the method-support patch entirely — two tickets, two timelines.
- **−** Longer path to the full benefit.

## Recommendation (not yet decided)

Lean **A for the core patch, D as the follow-on**: keep `Location` out of the first ticket
entirely, and build the indirection in the feature plugin where it can be iterated on without
a core BC commitment. Note it in the ticket as known future work so reviewers see the full
arc rather than assuming cacheability was overlooked.

If it works well in the plugin, it becomes a strong second proposal. If it turns out to need
per-site tuning, it stays a plugin, which is also a fine outcome.

## Open

- **What identifies a query resource?** A hash of the normalized body is the obvious answer,
  which immediately raises: what is "normalized"? RFC 10008 §2.7 permits normalizing
  semantically insignificant differences for cache keys, and **mis-normalization is itself a
  cache-poisoning hazard**.
- **Where does it live?** Transients are the low-friction answer and the wrong one at scale.
- **Lifetime and GC.** Who deletes these, and when? What happens on a `GET` to an expired one —
  404, or re-run the query?
- **Authorization.** If a query resource is GET-able by URI, is the URI itself the capability?
  For anything permission-gated that is an information-leak vector and needs the original
  permission callback re-run on `GET`.
- Does the indirection interact badly with the `X-HTTP-Method-Override` tunnel? Probably not —
  but the tunnel's response is a `POST` response, so `Location` semantics may read oddly.
