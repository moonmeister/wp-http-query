# ADR 0003 — What cache headers should a QUERY response emit by default?

**Status:** **Accepted — option C, emit nothing.** Decided 2026-08-19 on the evidence in
[experiments/cache-survey/](../../experiments/cache-survey/), which reversed this ADR's threat
model.
**Date:** 2026-08-16, decided 2026-08-19
**Security-relevant:** yes — but **not in the way this ADR originally claimed.** See the
survey before quoting anything below.
**Related:** [ADR 0004](0004-location-indirection.md)

---

> ⚠️ **Read this first.** Everything between here and the Decision section was written on
> 2026-08-16 from an unchecked inference and is **preserved as it was, not corrected in place**,
> because the shape of the error is the useful part. The reasoning was: no cache implements
> RFC 10008 §2.7 body keying, therefore every cache is "body-blind," therefore caches will key
> `QUERY` by URI and collide distinct requests.
>
> The second step does not follow. **Body-blind is not the same as will-cache-it-anyway.**
> Surveyed 2026-08-19: no shipping edge cache will store a `QUERY` response at all — they use
> method allowlists, or refuse the method outright, and RFC 9111 §3 requires exactly that. The
> hazard that does exist is inside WordPress's own page-cache plugins, and the mitigation this
> ADR proposed — a `Cache-Control` header — does not reach that layer.
>
> This is the **fifth** position in this project reversed by checking something previously only
> reasoned about, after the ADR 0002 tripwire premise, the argument against option E, the
> framing of Trac#46992, and the silent-body-discard argument against option D. Every one of
> the five favoured the status quo before it was checked.

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

> ⚠️ **This paragraph is the error.** "We cannot measure it" was allowed to become "therefore
> assume the worst," and the worst case was never checked against anything — not even a default
> config file. Descoping Axis B ruled out *empirical* evidence; it never ruled out reading
> `builtin.vcl`, `mod_cache.c`, or `proxy_cache_methods`. That took under an hour on 2026-08-19
> and reversed the conclusion.
>
> The lesson is narrower than "measure things": **an untestable claim is not thereby a settled
> one.** Documentary evidence sits between measurement and assumption, and this ADR skipped
> straight past it.

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

### C. Match existing `GET` behavior — emit nothing for anonymous responses ← **accepted**

- **+** Consistent with the rest of the REST API. No special case.
- **−** **Ships the poisoning vector.** Almost certainly disqualifying.

> ⚠️ **That con is false, and it was the reason C was dismissed.** Surveyed 2026-08-19: no
> shipping edge cache stores a `QUERY` response, so there is no poisoning vector to ship. See
> the Decision section.

### D. Safe default + capability signal

A or B, plus a documented way for the operator to declare their edge is body-aware
(constant, filter, or site-level setting), which flips the default for all `QUERY` routes.

- **+** Safe out of the box, and one switch for operators who have done the work — rather than
  per-route annotation.
- **+** Matches the reality that this is an *infrastructure* property, not a route property.
- **−** A footgun if someone sets it without understanding. Needs loud documentation.
- **−** More surface to design and test.

## Decision — option C, 2026-08-19

**Core emits nothing method-specific for `QUERY`. A `QUERY` response carries exactly the headers
a `GET` response to the same route would.** No `no-store`, no `private, no-cache`, no capability
signal, no `DONOTCACHEPAGE`.

Option C was called *"almost certainly disqualifying"* above, on a threat model that the survey
falsified. It is now the decision.

### Why

**Every edge cache is safe by construction, not by luck.** Varnish returns `501` before the
request reaches the origin. nginx cannot be configured to cache `QUERY` even deliberately — the
accepting bitmask holds only `GET`, `HEAD`, `POST`. Apache's `mod_cache`, Squid, Fastly and
Cloudflare all use allowlists. And RFC 9111 §3 forbids storing a response whose request method
the cache has not *understood*, where understanding means implementing its caching behavior —
so a cache that recognized `QUERY` but skipped §2.7 keying is already non-conformant if it
stores. Full table and sources in [experiments/cache-survey/](../../experiments/cache-survey/).

**The residual hazard is one opt-in configuration of one plugin.** W3 Total Cache Pro with REST
caching enabled: a `QUERY` clears W3TC's method *denylist*, lands in the `rest` cache group, and
is keyed without the body. Two of the four major WordPress page caches use allowlists and are
safe; the third (WP Super Cache) excludes REST requests outright.

**And that hazard is confusion, not disclosure.** Every parameter expressible in a `QUERY` body
is expressible in a `GET` query string; route, handler and permission callback are identical;
W3TC does not cache requests bearing logged-in cookies. An attacker can poison a URI-keyed entry
with a response **they could already have fetched anonymously**. Other anonymous users get wrong
public data. Worth fixing upstream, not worth a core default.

**A header would not have fixed it anyway.** The layer where the risk lives decides in PHP on
the write path and never reads `Cache-Control`. The mechanism that reaches it is the
`DONOTCACHEPAGE` constant, which core references **zero times**. Options A, B and D would all
have shipped machinery that looks responsible and does nothing about the only demonstrated
vector — the precise failure mode this ADR warned about in its own last line, arrived at from
the other direction.

**Nothing existing regresses either way**, since nobody sends `QUERY` today. So the choice was
never "safe versus fast." It was "invent the REST API's first method-specific cache behavior, or
not," and the evidence does not support inventing it.

### What ships instead of a default

Not silence — **the survey**. Three things, none of them core behavior:

1. **Publish the survey in the Trac ticket.** Someone will ask about caching; RFC 10008 §2.7 is
   right there and reviewers read RFCs. A ten-row table beats an assurance, and beats silence,
   which reads as not-considered. Tracked as
   [#21](https://github.com/moonmeister/wp-http-query/issues/21).
2. **Write the upstream reports for WP Super Cache and W3 Total Cache — and hold them until
   `QUERY` is reachable.** Both use denylists, so both default-allow *every* future method, not
   just `QUERY`. Drafted and locator-verified in [`docs/submissions/`](../submissions/); filed
   when the feature plugin publishes or a core release carries `QUERY` dispatch, tracked as
   [#39](https://github.com/moonmeister/wp-http-query/issues/39).

   **This was decided the other way first, on 2026-08-19, and reversed the same day.** The
   argument for filing immediately was that it converts "plugins can fix compatibility on
   release" from a hope into a citation. True, but it buys that citation with someone else's
   backlog: today nothing sends `QUERY` to a WordPress site, so the hazard is unreachable and a
   maintainer is right to deprioritize it. Writing the reports now captures the findings while
   they are fresh; sending them now would be asking two volunteer teams to act on our schedule
   rather than the risk's.
3. **Document the `QUERY`-behind-Varnish `501`** for site owners. Not a caching problem — an
   availability one, and the same family as the `GET POST` hardening-allowlist 403 in
   [#32](https://github.com/moonmeister/wp-http-query/issues/32).

### What would reopen this

Named in advance, so reopening is a trigger rather than a judgment call:

- Any cache or page-cache plugin ships `QUERY` support **without** RFC 10008 §2.7 body keying.
- A route is found where a `QUERY` body can express something a `GET` query string cannot —
  that would turn confusion into disclosure and change the severity outright.
- WordPress core adopts `DONOTCACHEPAGE`, or any first-party page-cache layer, making a
  mitigation cheap where today it is novel surface.

## Open

- **Resolved by this decision:** whether `rest_send_nocache_headers` suffices as the mechanism
  (no mechanism is needed), and whether to emit a diagnostic when no capability signal is set
  (there is no capability signal).
- Interaction with the `X-HTTP-Method-Override` tunnel: those responses are `POST` on the wire
  and already non-cacheable, so this should not apply. Still unconfirmed, but now inconsequential
  — core does nothing here either way.
- Interaction with [ADR 0004](0004-location-indirection.md): the `Location` indirection would
  have made this decision less consequential. It is now moot in that direction — this ADR is
  already as inconsequential as it gets — which removes one argument for settling 0004 early.

## Consequences elsewhere

- **[ADR 0002](0002-allmethods-vs-queryable.md) option D is no longer gated by this.** The
  reversal to D on 2026-08-19 promoted this ADR to a hard prerequisite, on the grounds that D
  turns `QUERY` on across the whole read surface at once and so makes caching everyone's
  problem. That still follows — but the answer is "no core behavior is warranted," which
  discharges the gate rather than raising it. D's prerequisite chain drops from four items to
  three: gap 3, [Trac#65905](https://core.trac.wordpress.org/ticket/65905), and the
  method-branching measurement in
  [#38](https://github.com/moonmeister/wp-http-query/issues/38).
- **[#20](https://github.com/moonmeister/wp-http-query/issues/20) loses half its scope.** The
  `Accept-Query` half stands; the cache-safety-default half is deleted.
- **[#21](https://github.com/moonmeister/wp-http-query/issues/21) inverts.** It was "defend the
  conservative default so a later contributor does not simplify it away." It is now "publish the
  survey that shows no default is needed." Same instinct, opposite content.
