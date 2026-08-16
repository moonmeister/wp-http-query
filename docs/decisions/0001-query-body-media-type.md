# ADR 0001 — What query format does a WordPress QUERY endpoint accept?

**Status:** Proposed — undecided
**Date:** 2026-08-16
**Blocks:** core patch (gap 3), `Accept-Query` advertisement, feature plugin design

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

### B. `application/json` + form-encoded

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

### D. Pluggable — core parses nothing, routes declare their own

Core threads the method through and lets each route declare its accepted media types and parse
its own body.

- **+** Maximally additive; no core opinion to defend.
- **+** Lets the ecosystem discover the right format before core blesses one.
- **−** No interoperability. Every plugin invents its own thing.
- **−** Still requires deciding what core's *own* endpoints would do, if any ever adopt `QUERY`.

## Recommendation (not yet decided)

Lean **B for the patch, D as the extension model**, with C explicitly deferred. B fixes a real
bug and keeps the diff minimal; D matches WordPress's usual posture of providing plumbing
rather than policy. Revisit C only if the feature plugin surfaces genuine demand.

## Open

- Does `Accept-Query` need a filter so routes can advertise their own types under D?
- If core advertises `application/json`, are we implicitly blessing it as *the* WordPress query
  format? Probably yes — treat that as the real decision.
- Should a `QUERY` with an unrecognized `Content-Type` 415, or fall through silently as today?
