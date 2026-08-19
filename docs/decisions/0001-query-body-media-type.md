# ADR 0001 — What query format does a WordPress QUERY endpoint accept?

**Status:** **Accepted — option B** (`application/json` + form-encoded), 2026-08-19
**Date:** 2026-08-16, decided 2026-08-19
**Blocks:** ~~core patch (gap 3)~~ **unblocked** —
[#14](https://github.com/moonmeister/wp-http-query/issues/14) can proceed. `Accept-Query`
advertisement ([#20](https://github.com/moonmeister/wp-http-query/issues/20)) and feature plugin
design still depend on the two open items below.

---

## Context

RFC 10008 does not define a query language. It defines a method that carries a body and a
response header (`Accept-Query`) for advertising which **query-format media types** a resource
accepts. The RFC's own examples are `application/jsonpath` and `application/sql;charset="UTF-8"`.

WordPress has no parsing story for any real query format.

What WordPress *does* have is a JSON body path that already works. Verified in trunk:
`parse_json_params()` runs unconditionally and prepends `JSON` to the parameter order whenever
`is_json_content_type()` is true, **independent of method**
(`class-wp-rest-request.php:364-368`). So a `QUERY` with `Content-Type: application/json`
already populates `WP_REST_Request` params correctly on stock core, with no patch.

The form-encoded path does *not* work: `$accepts_body_data = array( 'POST', 'PUT', 'PATCH',
'DELETE' )` (`class-wp-rest-request.php:377-380`) excludes `QUERY`, so a form-encoded body is
parsed by `parse_body_params()` into `$this->params['POST']` and then never added to the lookup
order. Silent data loss.

### Measured: PHP will not do this for us (matrix, 2026-08-16)

The matrix settles a question this ADR previously assumed. **PHP never populates `$_POST` for
a `QUERY` request, even with `Content-Type: application/x-www-form-urlencoded.`** Controlled on
`nginx-fpm`: an identical `filter[post_type]=post&per_page=10` body sent as `POST` populates
`$_POST` with `filter` and `per_page`; sent as `QUERY` it yields an empty `$_POST` and a
byte-intact `php://input`. This held on every passing stack and across both SAPIs, so it is PHP
behavior rather than a server difference.

That is exactly the situation `parse_body_params()` was written for — its docblock reads
*"Parses out URL-encoded bodies for request methods that aren't supported natively by PHP"*
(`class-wp-rest-request.php:746-747`) — and it uses `parse_str( $this->get_body(), $params )`
on the raw body rather than reading `$_POST`.

**Two consequences, both favoring option B:**

- Adding `'QUERY'` to `$accepts_body_data` really is a one-line change. Core already does its
  own parsing and does not depend on `$_POST`, so nothing else has to move.
- `QUERY` is precisely the class of method that method was added to serve. Option B is not
  special-casing `QUERY`; it is stopping `QUERY` from being the special case.

It also raises the cost of option A. Under A, a form-encoded `QUERY` body is not rejected — it
is parsed, stored, and then silently dropped from the lookup order, and the caller gets an
empty result set with a 200.

**This decision gates the gap-3 fix.** Adding `'QUERY'` to `$accepts_body_data` is a one-line
change, but it is only the right change if form-encoded is a format we intend to support.

## Decision drivers

- WordPress's REST API is JSON-native end to end. Introducing a second body grammar has a cost
  that lands on every consumer.
- RFC 10008's intent is genuinely *query formats*, not "a JSON blob of filter arguments."
  Choosing JSON is defensible but is arguably using the method for its ergonomics rather than
  its semantics — expect this to be raised in review.
- `Accept-Query` requires us to name a media type. We cannot advertise nothing.
- Whatever we choose becomes a compatibility surface immediately.

## Options

### A. `application/json` only

Advertise `Accept-Query: "application/json"`. Leave `$accepts_body_data` alone; the JSON path
already works.

- **+** Zero new parsing. Works on stock core today. Consistent with the rest of the REST API.
- **+** Smallest possible core diff — arguably *no* diff for body handling.
- **−** Not a query format in the RFC's sense. A reviewer may object that this is `POST`
  ergonomics wearing a `QUERY` hat.
- **−** Leaves gap 3 unfixed, so form-encoded `QUERY` remains silently broken rather than
  cleanly rejected.

### B. `application/json` + form-encoded ← **accepted**

Option A plus adding `'QUERY'` to `$accepts_body_data`.

- **+** Fixes the silent-data-loss bug regardless of what anyone sends.
- **+** Consistent with how `PUT`/`PATCH`/`DELETE` are treated — `QUERY` stops being a special
  case.
- **−** Form-encoded is a poor fit for structured queries; supporting it may invite bad usage.

### C. A WordPress query media type

Define e.g. `application/vnd.wp.query+json` with a documented filter grammar.

- **+** Honest to the RFC's intent. Advertisable and versionable.
- **+** Gives WordPress a real answer for structured filtering, which it currently lacks.
- **−** Substantially larger scope — this is designing a query language, explicitly out of
  scope per [scope.md](../scope.md) §3.
- **−** Would almost certainly not land in a first patch.

### D. Pluggable — core parses nothing, routes declare their own ← **declined, revisitable**

Core threads the method through and lets each route declare its accepted media types and parse
its own body.

- **+** Maximally additive; no core opinion to defend.
- **+** Lets the ecosystem discover the right format before core blesses one.
- **−** No interoperability. Every plugin invents its own thing.
- **−** Still requires deciding what core's *own* endpoints would do, if any ever adopt `QUERY`.
- **−** **Solves a problem nobody has yet, and the capability already exists** — a route can
  parse its own body today via `get_body()` with no core support. See the decision below.

## Decision — option B, 2026-08-19

**Core accepts `application/json` and `application/x-www-form-urlencoded`. Nothing else.**
Concretely: add `'QUERY'` to `$accepts_body_data` at `class-wp-rest-request.php:377`. That is
the whole production change.

C stays deferred — designing a query language is out of scope per [scope.md](../scope.md) §3.

### D is dropped, not adopted as the extension model

The earlier lean paired B with D ("core parses nothing, routes declare their own"). **D is now
declined for the first patch.** The reasoning is that it is a good idea without a demonstrated
need, and needs can be met later at no cost — but the specific reason it costs nothing to defer
is worth writing down, because it is the thing that makes deferring safe rather than merely
optimistic:

**D requires no core plumbing at all.** A route that wants `application/jsonpath` can already
read `$request->get_body()` in its own callback, or hook `rest_request_before_callbacks`, parse
whatever it likes, and `set_param()` the result. Nothing in core prevents this today and nothing
in option B changes that. So "pluggable" is not a feature core would have to build — it is the
status quo. Adopting D as a stated extension model would have meant writing documentation and a
filter for a capability that already exists, and then defending that surface in review.

Deferring it is therefore free in the strict sense: **if demand appears, adding a declared-media-type
API later is purely additive and breaks nothing shipped under B.** That is not true of C, and it
is not true of the `Accept-Query` value, which is a compatibility surface from the moment it is
emitted.

### The consequence to carry forward

B fixes the form-encoded silent-data-loss case. It does **not** fix the general one — see the
first open item. Do not describe B as closing gap 3 completely, because a reviewer who reads
`parse_body_params()` will see that it does not.

## Open

Two items, both now downstream of this decision rather than blocking it.

### 1. Unrecognized `Content-Type` still fails silently — and B does not fix it

`parse_body_params()` returns early on any content type that is not
`application/x-www-form-urlencoded` (`class-wp-rest-request.php:762-766`), with no error. So
after B lands, a `QUERY` carrying `Content-Type: application/jsonpath` and a perfectly good body
is parsed by nobody, falls through to URL parameters, and returns **a 200 with an unfiltered
collection** — the exact failure shape this project has been treating as the reason gap 3 comes
first.

Two things make this harder than it looks:

- **It is pre-existing and method-general.** `PUT`, `PATCH` and `DELETE` behave identically
  today. A `QUERY`-only 415 is inconsistent; a general one is a BC break in core.
- **But `QUERY` has a stronger case than the others.** A `PUT` with an unparsed body may still
  be a meaningful request via URL parameters. A `QUERY` whose body was discarded is a request
  whose entire meaning was discarded — the body *is* the query.

That asymmetry is probably the argument for a `QUERY`-specific 415, but it is a new opinion in
core and it should be argued deliberately rather than slipped into the gap-3 patch. **Keep it
out of [#14](https://github.com/moonmeister/wp-http-query/issues/14)** so that patch stays a
one-line bug fix, and settle it before `Accept-Query` is emitted — advertising the two types we
accept while silently accepting a third is incoherent.

### 2. Does advertising `application/json` bless it as *the* WordPress query format?

Probably yes, and that is the real weight of this decision. Worth separating two things that B
runs together:

| | |
|---|---|
| What core **parses** | JSON and form-encoded — both, because the alternative is silent loss |
| What core **advertises** in `Accept-Query` | Open. Not necessarily both |

Form-encoded is a poor query format and advertising it invites `filter[meta_query][0][key]=…`
in a header we have to live with. Accepting it is a bug fix; advertising it is an endorsement.
It is defensible to parse both and advertise only `application/json`. That call belongs to
[#20](https://github.com/moonmeister/wp-http-query/issues/20).
