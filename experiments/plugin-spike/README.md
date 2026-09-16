# Plugin spike — can a userland plugin reach routes it does not own?

Two artifacts, one question each:

- `rest-plugin-spike-probe.php` — **does the plugin work on stock core?** Yes, 6/6.
- [Method-branching sweep](#method-branching-sweep) — **what does it break when it does?**

The second exists because the plugin's `rest_endpoints` filter does the same thing ADR 0002
option D does — add `QUERY` to every route that accepts `GET` — just scoped to sites that chose
to install it. The blast radius is therefore the same shape, and the sweep measures it for both.

## Probe

`rest-plugin-spike-probe.php`, run 2026-09-15 against `wordpress-develop` at `e7739d5414`,
**unmodified** — no core patch of any kind. Copy it and `plugin/wp-http-query.php` into
`tests/phpunit/tests/rest-api/`, then `phpunit --filter Tests_REST_Plugin_Spike_Probe`.

Everything measured before this registered a controller under `'methods' => 'QUERY'` by hand,
which proves dispatch works but not that a plugin can reach core's own routes. Here nothing is
registered: the plugin rewrites core's `/wp/v2/posts` registration and the assertions run against
that.

**6/6 pass.** The result that matters is the form-encoded case, measured three ways, because the
obvious control is the wrong one — with the plugin off this `404`s on gap 2, which says nothing
about gap 3:

| Plugin state | `QUERY /wp/v2/posts` + `search=needle` (form-encoded) |
|---|---|
| off | `404` — route does not match (**gap 2**) |
| gap 2 shim only | `200` with **both** posts, unfiltered (**gap 3**) |
| both shims | `200`, correctly filtered |

The middle row is the project's only silent-wrong-answer failure mode, and the plugin closes it
from userland via `rest_request_parameter_order`. **All three gaps are reachable without a core
patch.** That is what the plugin-first pivot rests on.

Two of the six are guards rather than findings: `test_write_handler_does_not_gain_query` (the
`POST` handler on the same URL must not pick `QUERY` up) and
`test_array_form_route_survives_the_rewrite` (the plugin must emit the string form of `methods`,
never the array form, or it trips Trac#65905 itself — see the comment at
`plugin/wp-http-query.php:233`).

## Method-branching sweep

ADR 0002's last open objection to option D was an assertion rather than a number: *plugin code
doing `'GET' === $request->get_method()` silently stops matching.* Run 2026-09-15 against
[veloria.dev](https://veloria.dev/), which RE2-searches the whole plugin directory.

The result CSVs are **not committed** (~3.8 MB, mostly the two denominators). The regexes and the
search IDs are, so every number below can be re-derived. RE2 syntax — no lookaround, no
backreferences.

| # | Search ID | Regex | Lines | Plugins | Installs |
|---|---|---|---|---|---|
| 1 | `6908fec2-45e9-408e-9788-262008b6a8ce` | `get_method\s*\(\s*\)\s*(===\|==\|!==\|!=)\s*['"]GET['"]` | 108 | 73 | 2,513,060 |
| 2 | `3a825905-2d32-46a4-ae2d-29c0cb91b965` | `['"]GET['"]\s*(===\|==\|!==\|!=)\s*[^;]{0,40}get_method\s*\(` | 130 | 75 | 11,266,170 |
| 3 | `8d5c7719-5681-4cdb-9717-769c0be42b72` | `switch\s*\(\s*[^;]{0,40}get_method\s*\(` | 17 | 8 | 115,920 |
| 4 | `9623ae79-4ace-4abc-93f8-0c3242fe0963` | `in_array\s*\(\s*[^,]{0,60}get_method\s*\(` | 34 | 34 | 10,210,560 |
| 5 | `842deba9-d367-422d-bdbf-b99d0ef54277` | `\[\s*['"]REQUEST_METHOD['"]\s*\]` | 343 | 261 | 25,163,220 |
| 6 | `a70bb6b2-f509-4906-b2ee-15479f9d83d1` | `get_method\s*\(\s*\)` | 2,753 | 1,213 | 64,450,570 |
| 7 | `0d5e1953-80c3-4da1-a5a1-aee137ee2d7b` | `WP_REST_Server::READABLE` | 15,878 | 2,428 | 83,809,680 |

Searches 1–4 are the branching forms. 5 is a control — `$_SERVER['REQUEST_METHOD']` is the other
way to ask, and it is *more* common than the REST accessor, but it is mostly not REST code. 6 and
7 are denominators.

**Search 5 is excluded from every derived number below.** 273 of the 289 raw branching lines use
`$request->get_method()`; a plugin reading `$_SERVER` directly is usually not inside a REST
callback, and the sweep cannot tell which ones are.

### Deriving the at-risk set

Option D only changes routes registered with `WP_REST_Server::READABLE`. A route registered with
the literal string `'GET'` is untouched. So the population at risk is the intersection of
searches 1–4 with search 7 — and how you take that intersection is the whole argument.

De-vendored throughout (`/vendor/`, `/node_modules/`, `/build/` dropped; 17 of the 289 raw
branching lines were vendored, 9 of them copies of core's own abilities-api run controller):

| Set | Lines | Plugins | Installs |
|---|---|---|---|
| branches on the method at all | 272 | 170 | 16,939,450 |
| …and never uses `READABLE` → **safe** | 165 | 105 | 4,976,410 |
| …**∩ same plugin** — upper bound | 107 | 65 | **11,963,040** |
| …**∩ same file** — lower bound | 46 | 28 | **820,270** |

**Both bounds are wrong, and each was falsified by reading its largest member.**

- The **upper** bound over-counts. Its biggest entry is WooCommerce (7M) at
  `src/Api/Infrastructure/GraphQLControllerBase.php:398`, which is a real safe-method guard
  (`// 4. Reject mutations over GET`). But that route registers
  `'methods' => Main::filter_methods_against_settings( array( 'GET', 'POST' ) )` — **literal
  strings, not `READABLE`**. Option D does not touch it. WooCommerce uses `READABLE` elsewhere in
  the plugin, which is the only reason the intersection caught it.
- The **lower** bound under-counts, and Jetpack is the proof — see below. Its branch and its
  registration are in different files, so a same-file intersection misses it entirely.

Resolving this properly means following each `READABLE` registration to its callback, which the
line-oriented sweep cannot do. **Quote the range, not a point estimate**, and say which end was
checked how.

### What the change actually is

Severity is not inferable from a one-line CSV hit — 100 of the 107 at-risk lines are a bare `if`
whose body the sweep cannot see. So the top entries were fetched and read. Three outcomes, and
they are very different:

**1. Benign — the branch is a read/write discriminator that fails the safe way.**
Polylang (800K), `src/functions.php:173`:

```php
return 'GET' === $request->get_method() && 'edit' === $request->get_param( 'context' );
```

`pll_is_edit_rest_request()` returns `false` for `QUERY`, i.e. "not an edit request." Which is
correct. Kadence Security (700K) is the same shape one step worse — its `else` reads params from
`get_query_params()` instead of the card-specific shape, so a `QUERY` gets a degraded response
rather than a wrong one.

**2. Harmful — a safe method performs a write.** Jetpack (3M) is the clean case, verified end to
end. `_inc/lib/class.core-rest-api-endpoints.php` registers three `READABLE` routes whose
callbacks branch:

| Route | `methods` | Callback's `else` branch |
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

Under option D — **or on any site running this plugin** — `QUERY /jetpack/v4/module/all` falls
into the `else` and activates modules. A method RFC 10008 defines as safe and idempotent performs
a state change.

Authorization is *not* bypassed: `can_request()` branches the same way and lands on the stricter
`jetpack_manage_modules` rather than `jetpack_admin_page`, so the `if/else` fails closed on the
permission side. This is a safe-method violation, not a privilege escalation. It still means an
intermediary or a client that retries a "safe" request can double-apply a write.

**3. Unknown.** Everything else. The `easy-facebook-likebox` family (30K × 12 files) and the
`beyondseo` cluster are negated (`'GET' !== …`) guards whose bodies were not read.

### Findings

- **Polarity does not predict severity, and the obvious reading of it is backwards.** Split the
  107 at-risk lines: 79 lines / 55 plugins / 11.96M installs are positive (`=== 'GET'`), 28 lines /
  12 plugins / 37,740 installs are negated (`!== 'GET'`). The tempting conclusion is that positive
  checks are safe because `QUERY` just skips the branch. **Jetpack is a positive check and it is
  the worst case found** — because the branch has an `else`. What matters is `if`-vs-`if/else`,
  which needs the source, not the sweep.
- **The hazard is a safe-method guard losing its grip**, not a crash and not a disclosure. Every
  harmful instance found has the same shape: code that used `'GET'` as a synonym for "this request
  is safe," which stops being true the moment a second safe method exists.
- **This is an argument about sequencing, not about D.** Jetpack breaks identically under option D
  and under this plugin. The difference is only who opted in.

### What the plugin should do about it

Open. The `rest_endpoints` filter currently adds `QUERY` to every `GET` handler unconditionally,
which reproduces the Jetpack hazard on any site that installs it. Options not yet evaluated: an
allowlist, a `wp-json/wp/v2` namespace restriction, or a `wp_http_query_route_allowed` filter
defaulting to core namespaces only. Tracked in #38.
