# Blast-radius experiment — ADR 0002

Measures what each `WP_REST_Server` method-constant option does to core's REST test suite.
Findings and their interpretation live in
[ADR 0002](../../docs/decisions/0002-allmethods-vs-queryable.md); this directory is the
reproduction.

## Result

Run 2026-08-16 against `wordpress-develop` at `e7739d5414`, `phpunit --group restapi`, 3550 tests.

| Variant | Patch | Tests / Assertions | New failures |
|---|---|---|---|
| baseline | none | 3550 / 16239, Errors 1, Warnings 4, Skipped 6 | — |
| option-A | `ALLMETHODS` += `QUERY` | identical to baseline | **0** |
| option-B | new `QUERYABLE = 'QUERY'` | identical to baseline | **0** |
| option-D | `READABLE` = `'GET, QUERY'` | 3550 / 16184, Failures 20 | **20** |

**Read ADR 0002 before quoting these numbers.** Both zeroes and the 20 are misleading on their
own: core uses `ALLMETHODS` exactly once, so the suite cannot measure option A at all; and 14 of
option D's 20 are a pre-existing core bug rather than a `QUERY` problem.

## Reproducing

`run.sh` expects two sibling checkouts and an already-running `wordpress-develop` Docker stack:

- `../wordpress-develop` — a normal checkout, used only for its `vendor/` (mounted read-only)
- a scratch `git worktree` at the baseline commit, where the script lives and is run from

The worktree needs its own `wp-tests-config.php` pointing at a separate schema (we used
`wordpress_develop_tests_exp` on the existing MySQL container) so the run cannot collide with
anything else using the default test database.

```sh
git -C ../wordpress-develop worktree add --detach ../wpdev-exp e7739d5414
# ...write wp-tests-config.php with a distinct DB_NAME, create the schema...
cp run.sh ../wpdev-exp/ && cd ../wpdev-exp && ./run.sh
```

The script patches `class-wp-rest-server.php` with `perl -0pi`, runs the suite, records
`blast-radius/<variant>.txt` plus a sorted `<variant>.tests` list of failing test names, and
`git checkout --`s the file between variants. It ends by `comm -13`-ing each variant's failure
list against baseline.

`results/*.tests` here are those lists, committed so the decomposition in ADR 0002 can be checked
without re-running.

## Probes

Three standalone tests, not part of the variant sweep. Drop any of them in
`tests/phpunit/tests/rest-api/` and run with `--filter <ClassName>`. **None of them requires a
core patch** — they all run against unmodified trunk, which is the point.

Each is written to fail where the finding is, so the PHPUnit diff *is* the result. Two also
assert `array()` against a collected table purely to make that table print.

### `rest-query-body-probe.php` — does a `QUERY` body populate params?

Answer: **with JSON, yes, already.** `get_parameter_order()` adds the `JSON` source with no
method check, so `QUERY` + `application/json` resolves as `JSON > GET > URL > defaults`. The
`$accepts_body_data` allowlist (`class-wp-rest-request.php:377`) gates only the form-encoded
source, so `QUERY` + `application/x-www-form-urlencoded` resolves as `GET > URL > defaults` and
loses the body. Gap 3 costs exactly that one case.

Note the `PUT` control: `POST` is deliberately not used, because `get_parameter_order()` skips
`parse_body_params()` for `POST` and relies on the SAPI having filled `$_POST`, which a synthetic
request object never has.

### `rest-query-fallback-probe.php` — would a `QUERY → GET` fallback work?

Registers the real `WP_REST_Posts_Controller::get_items()` under `'methods' => 'QUERY'` with its
own `get_collection_params()`, which is what ADR 0002 option D or an option E fallback would
dispatch to. A JSON body **filters the collection correctly and is schema-validated** (`400` on
`per_page=9999`); a form-encoded body returns the **full unfiltered collection with a `200`**.

This corrected ADR 0002, which had asserted the fallback would silently discard the query in
general. It does so only for form-encoded bodies — i.e. the objection is really gap 3.

### `rest-array-methods-probe.php` — the array-form normalization bug

A finding that has nothing to do with `QUERY`, discovered while measuring option D.

`register_rest_route()` splits comma-separated method strings only when `methods` is a string;
the array branch (`class-wp-rest-server.php:1008-1020`) does not. So
`array( WP_REST_Server::READABLE, WP_REST_Server::EDITABLE )` registers the literal method key
`'POST, PUT, PATCH'` and `POST` to that route returns `404`.

The probe fails on **unmodified trunk** — this is independent of `QUERY`. Core has exactly one
array-form registration with constants (`class-wp-rest-block-renderer-controller.php:48`, both
single-method), so nothing is broken today; it is a landmine. Option D makes `READABLE`
multi-method and steps on it, which is the entire source of the 14 block-renderer failures.

Filed 2026-08-18 as [Trac#65905](https://core.trac.wordpress.org/ticket/65905); patched in
[wordpress-develop#13136](https://github.com/WordPress/wordpress-develop/pull/13136), which carries
four core-style tests that supersede this probe.

## Plugin-directory search

How far the array-form bug reaches in the wild, and — the reason it matters here — how much
ecosystem breakage each ADR 0002 option would cause. Run 2026-08-18 against
[veloria.dev](https://veloria.dev/), which regex-searches the whole plugin directory.

The result CSVs are **not committed** — ~15 MB of matched lines, most of it noise. The regexes are,
so the numbers can be re-derived. RE2 syntax (no lookaround, no backreferences); `[^...]` classes
match newlines, so multi-line registrations are covered.

| # | Regex | Lines | Plugins | Installs |
|---|---|---|---|---|
| 1 | `['"]methods['"]\s*=>\s*(array\(\|\[)[^)\]]*(EDITABLE\|ALLMETHODS)` | 4 | 3 | 2,000 |
| 2 | `['"]methods['"]\s*=>\s*(array\(\|\[)[^)\]]*['"][A-Za-z]+\s*,` | 1 | 1 | 0 |
| 3 | `['"]methods['"]\s*=>\s*(array\(\|\[)` | 7,024 | 921 | 53.3M |
| 4 | `WP_REST_Server::EDITABLE` | 6,986 | 1,367 | 76.5M |
| 5 | `register_rest_route\s*\(` | 69,835 | 8,748 | 178.9M |

Search 1 is the bug. Search 2's single hit is a **false positive** — a settings schema keyed
`'methods'`, not a route registration.

Filtering search 3's CSV by constant gives the per-option ecosystem blast radius, since a
registration only breaks if it names a constant that gains a second method:

| Constant in array-form `methods` | Lines | Plugins | Installs |
|---|---|---|---|
| `READABLE` | 44 | 27 | 1.19M |
| `CREATABLE` | 76 | 39 | 2.73M |
| `DELETABLE` | 8 | 3 | 6,400 |
| any `WP_REST_Server::` constant | 87 | 44 | 2.75M |

**Lower bounds.** Matches are per-line, so the constant must appear on the same line as the
`methods` key; indirection (`'methods' => $methods`) is invisible. A zero would be a floor, not a
proof.

Two findings:

- The array form is common (921 plugins) but **only 87 of its 7,024 lines name a
  `WP_REST_Server::` constant at all** — the rest pass plain strings like `array( 'POST' )`, which
  are immune. That is why a bug dating to 4.4 has stayed quiet, and it is a better explanation than
  "nobody uses arrays."
- The hazard is **specific to option D**. `ALLMETHODS` is already multi-method and has zero
  array-form uses, and a new single-method `QUERYABLE` cannot trip the bug. Option D would break
  **27 plugins / 1.19M installs** — unless Trac#65905 lands first, which makes this a sequencing
  constraint on D rather than a durable argument against it.
