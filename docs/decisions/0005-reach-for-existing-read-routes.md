# ADR 0005 — Should existing read routes gain QUERY, and by what mechanism?

**Status:** Proposed, partly settled. **Settled 2026-11-04 for the feature plugin: no reach by default, opt-in per route, per site, or by preset — implemented in [#1](https://github.com/moonmeister/wp-http-query/issues/1)** (see [What the plugin implements](#what-the-plugin-implements)). Still open: what core should do.
**Date:** 2026-09-15
**Split from:** [ADR 0002](0002-allmethods-vs-queryable.md), which closed on option B
(`QUERYABLE`, additive) on 2026-09-15. B settles what the constants *mean*; it does not answer
whether existing routes should become queryable, and that question had accumulated its own
options and its own evidence.
**Blocked:** the feature plugin's `rest_endpoints` filter. Unblocked 2026-11-04; see below.
**Related:** [ADR 0003](0003-cache-safety-default.md) (reappears if reach is granted),
[experiments/plugin-spike/](../../experiments/plugin-spike/README.md) (the evidence)

---

## Context

ADR 0002 closed on B. Nothing existing changes on upgrade; route authors opt into `QUERY` by
naming `QUERYABLE`.

**That leaves `QUERY` with a real adoption problem, and it is the same one Trac#65616 opens with:
what does the method buy anyone if no endpoint implements it?** Under B, `QUERY` support arrives
route by route, which for most sites means never. Core ships the capability and nothing uses it.

Every attempt to fix that by making existing read routes answer `QUERY` — ADR 0002's options A, D
and E — was measured to carry the same BC cost, and it is not a hypothetical one.

## The hazard, in one shape

Plugin code that used `'GET'` as a synonym for *"this request is safe"* stops guarding the moment
a second safe method exists.

The sharpest form is a callback shared between a read registration and a write registration, told
apart by an `if` — because on the read route the `else` branch is **dead code, and granting reach
makes it live**:

```
route A   methods => READABLE   callback => $x->process    ← else was unreachable
route B   methods => EDITABLE   callback => $x->process    ← else is the write path
```

Verified instance, Jetpack (3M installs), `_inc/lib/class.core-rest-api-endpoints.php`:

| Route | `methods` | `else` branch |
|---|---|---|
| `/jetpack/v4/module/all` (`:248`) | `READABLE` | `activate_modules( $request )` |
| `/jetpack/v4/module/(?P<slug>…)` (`:287`) | `READABLE` | `update_data( $request )` |
| `/jetpack/v4/settings/` (`:403`) | `READABLE` | `update_data( $request )` |

```php
public function process( $request ) {
    if ( 'GET' === $request->get_method() ) {
        return $this->get_modules();
    } else {
        return static::activate_modules( $request );
    }
}
```

Grant reach and `QUERY /jetpack/v4/module/all` activates modules. A method RFC 10008 defines as
safe and idempotent performs a state change.

**Authorization fails closed, and not by luck.** `can_request()` branches identically and lands on
the stricter `jetpack_manage_modules` rather than `jetpack_admin_page` — the same callback-sharing
that creates the hazard also duplicates the guard. This is a safe-method violation, not a privilege
escalation. It still means an intermediary or a retrying client can double-apply a write.

**Scale:** between **28 plugins / 820K installs** and **65 plugins / 11.96M installs** both branch
on the request method and use `READABLE`. Both bounds were falsified by reading their largest
member, so quote the range. Method, regexes and search IDs:
[`experiments/plugin-spike/README.md`](../../experiments/plugin-spike/README.md#method-branching-sweep).

**Core itself is immune, structurally.** `WP_REST_Controller` registers one callback per method —
`get_items`/`get_item` read, `create_item`/`update_item`/`delete_item` write — with no sharing
across the line. Verified 2026-09-15 across `src/wp-includes/rest-api/endpoints/`. This is a second,
independent reason the core suite cannot see this, alongside "no core test sends a `QUERY` with a
body." **Do not cite a green core suite as evidence for any option here.**

## Options

### 1. No reach

B and nothing else. No route gains `QUERY` without its author's action.

- **+** Zero BC risk. Nothing to argue.
- **−** `QUERY` is shipped and unused. Concedes the ticket's opening question.

### 2. Core adopts `QUERYABLE` on its own read collections

**Core is an author.** This ADR's first draft folded this into option 1 — "existing routes never
gain `QUERY` without their author's action" — which quietly assumed authors are third parties.
Core owns ~35 `get_items` registrations and can add `QUERYABLE` to them explicitly, route by route,
reviewed individually.

- **+** Delivers the surface anyone actually wants a query method for: `QUERY /wp/v2/posts`,
  `/wp/v2/users`, `/wp/v2/media`. That is most of the practical value of options 3–5 without any of
  the mechanism.
- **+** **Safe for a structural reason, not a hopeful one.** `WP_REST_Controller` registers one
  callback per method — `get_items`/`get_item` read, `create_item`/`update_item`/`delete_item`
  write — with no sharing across the line, so the Jetpack shape cannot occur in core's own
  controllers. Verified 2026-09-15 across `src/wp-includes/rest-api/endpoints/`.
- **+** Incremental and reversible. Each controller is a separate reviewable change; a bad one is
  revertable without touching a constant.
- **+** Scopes the safety guarantee correctly: **default where core can vouch for the code, opt-in
  where it cannot.**
- **−** Does nothing for plugin routes, which is most of the REST surface on a real site.
- **−** Needs gap 3 first, same as everything else here.
- **−** **The residual risk is not zero, it is different in kind.** Plugin *filters* hooked onto
  core routes — `rest_prepare_post`, `rest_post_query`, `rest_request_after_callbacks` — begin
  seeing `QUERY` on routes they already touch. Much lower risk than plugin route registrations,
  since filters rarely branch on the method, and **unmeasured**: the sweep looked at registrations,
  not filter callbacks.

### 3. Reach by constant — `READABLE = 'GET, QUERY'`

ADR 0002's option D, reproposed as a reach mechanism rather than a constants question.

- **+** Semantically the tightest fit; `QUERY` genuinely is a read.
- **+** Whole read surface, zero work for route authors.
- **−** The Jetpack class, on upgrade, for everyone. Against WordPress's BC bar this is close to a
  veto.
- **−** Sequencing: needs gap 3 first. (Trac#65905 was the other prerequisite; fixed in
  [r63756](https://core.trac.wordpress.org/changeset/63756), 7.2.)

### 4. Reach by dispatch — `QUERY → GET` fallback

ADR 0002's option E. Core already does this for `HEAD → GET`
(`class-wp-rest-server.php:1190-1196`).

- **+** Follows a pattern core ships and reviewers accept.
- **+** Can be made per-route opt-in, which option 3 cannot.
- **−** **Not the safe alternative it reads as.** Core's `HEAD` fallback preserves
  `get_method()`, so the handler still sees `QUERY` and Jetpack breaks identically. Options 3 and 4
  are the same test.

### 5. Reach with a hazard check

Grant reach, but refuse `QUERY` where the shared-callback shape is present: build callback →
methods across all registered routes, and skip any read handler whose callback also appears under a
write method.

- **+** Targets the measured mechanism rather than guessing. Catches Jetpack exactly.
- **+** Prototypable in the feature plugin from `rest_endpoints`, which sees the whole endpoint map
  — so it can be *demonstrated* before it is proposed.
- **−** Catches only the shared-callback subset. A single-registration handler that branches
  internally is invisible, and 100 of the 107 at-risk lines are unclassified bare `if`s.
- **−** Costs a post-registration pass, which is architecturally awkward in core — and it cannot be
  expressed as a constant, so it is a new dispatch mechanism rather than a refinement of option 3.
- **−** Callback identity comparison is fiddly: strings, `array( $obj, 'method' )`, closures.

### 6. ~~Reach without the method~~

❌ **Rejected 2026-09-15** — ADR 0002 option F. Dispatch `QUERY` but have `get_method()` report
`'GET'`. Makes the entire hazard vanish without touching a line of plugin code, and is rejected
because it makes `QUERY` a lie at the application layer: a handler that cannot tell it received
`QUERY` cannot negotiate a query format, cannot `415`, and cannot vary its representation. See ADR
0002 option F for the full argument.

## What the plugin implements

Decided and built 2026-11-04 ([#1](https://github.com/moonmeister/wp-http-query/issues/1)). **Off by default.** No existing route gains
`QUERY` unless one of three opt-ins names it. This supersedes the earlier position that the plugin
should implement option 3 as an instrument: reproducing the Jetpack break on real sites was judged
too high a price for the evidence.

| Opt-in | Who | How |
|---|---|---|
| Route registers it | Route author | `'methods' => WP_REST_Server::READABLE . ', ' . \WPHttpQuery\QUERYABLE`. Works on stock core; `QUERYABLE` is the userland stand-in for core's future `WP_REST_Server::QUERYABLE` ([ADR 0002](0002-allmethods-vs-queryable.md) option B) |
| Route list | Site, in code | `wp_http_query_routes` filter: registered route keys, matched **exactly** (`/wp/v2/posts`, or the regex key verbatim, e.g. `/wp/v2/posts/(?P<id>[\d]+)`). No patterns in v1 |
| Preset | Site, in Settings → HTTP QUERY or code | One stored option (`wp_http_query_presets`, default empty), then the `wp_http_query_presets` filter so code can override it. One preset in v1, `core-collections`: this ADR's option 2, done by the plugin |

On every opted-in route, only handlers that accept `GET` gain `QUERY`; write handlers on the same
route never do. The plugin writes `methods` back as a string, never an array (Trac#65905).

**Where the setting lives** (revised 2026-11-04, in review of #43). The checkbox first went on
Settings → General, on the grounds that one checkbox didn't justify a menu item and Permalinks, the
closer fit, saves its own form outside `options.php`. It was too hard to find, so it moved to its
own **Settings → HTTP QUERY** page, linked from the plugin's row on the Plugins screen. The page
also lists every route that accepts `QUERY` and which opt-in put it there, read from the same code
that decides reach. The option name and sanitizer are unchanged.

### The `core-collections` preset

`/wp/v2/posts`, `/wp/v2/pages`, `/wp/v2/media`, `/wp/v2/comments`, `/wp/v2/categories`,
`/wp/v2/tags`, `/wp/v2/users`, `/wp/v2/search`. Collections only; single-item routes are not in it.

**Method-branching check, 2026-11-04**, against the installed core in each test site (7.0.8,
7.1.4, nightly 7.2-beta4-65250):

- Each route's `GET` handler, read from `rest_get_server()->get_routes()`, is its controller's own
  `get_items()` (`WP_REST_Posts_Controller` for posts and pages, `WP_REST_Attachments_Controller`,
  `WP_REST_Comments_Controller`, `WP_REST_Terms_Controller` for categories and tags,
  `WP_REST_Users_Controller`, `WP_REST_Search_Controller`). The write handler on each route is a
  different method (`create_item`).
- `grep -nE 'is_method\(|get_method|REQUEST_METHOD'` over those controllers,
  `class-wp-rest-controller.php` and `rest-api/search/*.php` finds only `is_method( 'HEAD' )`
  (posts `:414`, `:1893`/`:1894`; comments, terms, users, search likewise). `QUERY` is not `HEAD`,
  so it gets the full `GET` response. No `'GET'` comparisons.
- Across all of `rest-api/endpoints/`, the only method branches outside `HEAD` are the abilities
  run controller (`in_array( get_method(), array( 'GET', 'DELETE' ) )`) and font families
  (schema args by method). Neither is in the preset.

Not checked: plugin *filters* on these routes (`rest_post_query`, `rest_prepare_post`, ...), which
now see `QUERY` on sites that enable the preset. Option 2's residual risk, above, still applies.

Tests: `tests/adr-0005-reach.test.ts` (default off, each opt-in, write handlers, and a
form-encoded `QUERY /wp/v2/posts` search returning only the matching post) and
`tests/lifecycle/adr-0005-preset-setting.test.ts` (the stored setting and the filter override)
and `tests/lifecycle/settings-page.test.ts` (the settings page, saved through its real form).

Still not built: the shared-callback detector (option 5) and a `readme.txt` warning for sites
that opt in plugin routes.

## Open

- **Is there an unauthenticated variant?** Jetpack fails closed because the permission callback is
  shared alongside the handler. A route registered `READABLE` + `__return_true` whose callback is
  shared with a write registration behind a strict check would be an unauthenticated write. That
  shape exists in the wild — WooCommerce's GraphQL endpoint is `'permission_callback' =>
  '__return_true'` with the auth done per-operation — and is only saved there by registering
  literal `'GET'` rather than `READABLE`. **Whether the two coincide anywhere is unmeasured, and it
  is the difference between "wrong behavior" and "vulnerability."** Harder to search than the last
  sweep; it needs registration pairs, not lines.
- **The unclassified tail.** 100 of the 107 at-risk lines are bare `if`s whose bodies were never
  read. The top entries were fetched and read; the `easy-facebook-likebox` family (12 files) and
  the `beyondseo` cluster were not.
- **Does ADR 0003 survive reach?** It was decided assuming `QUERY` is a per-route concern, which is
  what ADR 0002's option B makes it. Options 3–5 turn body-varying responses on across the read
  surface at once. Re-read ADR 0003 against whichever option is chosen before deciding.
- **Is reach core's job at all?** The plugin delivers it to sites that opt in (since 2026-11-04). If that is
  sufficient, options 1–2 cost nothing further and this ADR closes. Argue this before arguing the
  mechanisms — it may dissolve the question rather than answer it. Note that option 2 is not a
  no-op version of option 1: it ships `QUERY /wp/v2/posts` by default, which is most of what
  "reach" was ever wanted for, and it does so without a new mechanism.
