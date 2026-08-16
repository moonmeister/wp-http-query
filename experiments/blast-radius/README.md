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

## `rest-array-methods-probe.php`

A standalone finding, not part of the variant sweep. Drop it in
`tests/phpunit/tests/rest-api/` and run `--filter Tests_REST_Array_Methods_Probe`.

`register_rest_route()` splits comma-separated method strings only when `methods` is a string;
the array branch (`class-wp-rest-server.php:1008-1020`) does not. So
`array( WP_REST_Server::READABLE, WP_REST_Server::EDITABLE )` registers the literal method key
`'POST, PUT, PATCH'` and `POST` to that route returns `404`.

The probe fails on **unmodified trunk** — this is independent of `QUERY`. Core has exactly one
array-form registration with constants (`class-wp-rest-block-renderer-controller.php:48`, both
single-method), so nothing is broken today; it is a landmine. Option D makes `READABLE`
multi-method and steps on it, which is the entire source of the 14 block-renderer failures.
