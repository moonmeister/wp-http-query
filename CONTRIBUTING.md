# Contributing

This repo holds the **feature plugin** ([`plugin/`](plugin/)) plus the docs, decision records and
compatibility matrix behind it. Start with the [README](README.md) for what the project is, and
the [project board](https://github.com/users/moonmeister/projects/1) for the current working order.

## Prerequisites

| Tool | Why | Check |
|---|---|---|
| **Docker** (Desktop, OrbStack, or similar) | Runs the WordPress test sites. **It must be running** before any `make` target that touches the sites | `docker info` |
| **Node.js 24.18+** and npm (26, the current LTS, recommended; `nvm use` reads `.nvmrc`) | Runs [wp-env](https://www.npmjs.com/package/@wordpress/env) and the [vitest](https://vitest.dev) suite | `node -v` |
| **make** | Entry point for everything below. Preinstalled on macOS and most Linux | `make -v` |
| `jq` *(matrix only)* | Used by `matrix/run.sh` | `jq --version` |

You don't need PHP, MySQL or a local WordPress install. Docker provides all of them.

## First run

```sh
make check
```

The first run installs npm dependencies, downloads the WordPress nightly build and the Docker images,
then runs the tests. Expect a few minutes. Later starts take seconds.

CI runs the same `make check` on every PR and on pushes to `main` ([`.github/workflows/test.yml`](.github/workflows/test.yml)).
It also runs `make lint` (WordPress Coding Standards and PHP 8.0+ compatibility for `plugin/`) on every PR and on pushes to `main` ([`.github/workflows/lint.yml`](.github/workflows/lint.yml)).

You get one WordPress site, **nightly**, at <http://localhost:8872>, running the latest nightly
build with `plugin/` and the [example plugin](examples/query-route-example/) mounted and active.
The plugin needs WordPress 7.2 or later, and nightly is the build that has it.

Admin is at `/wp-admin`, user `admin`, password `password`. `make open` opens it for you.

The plugin directory is **mounted, not copied**, so edits to `plugin/wp-http-query.php` take effect
on the next request. No restart needed.

## Try it by hand

```sh
make demo
```

The test site above isn't what a real install looks like: it mounts a test fixture
(`tests/fixtures/mu-plugins/`) that opts routes in, turns the preset on and makes the echo route
public, plus the example plugin. `make demo` starts a separate site with none of that: the latest
nightly on <http://localhost:8873> (`admin` / `password`), only `plugin/` mounted and active,
pretty permalinks, and default settings. The echo route is admins only. It opens
**Settings → HTTP QUERY**:

1. On **Settings → HTTP QUERY**, the "Routes that accept QUERY" table lists only the plugin's own
   two routes: no core route accepts `QUERY` yet, so a `QUERY` to `/wp-json/wp/v2/posts` gets `404`.
2. Tick **Core collections** and click **Save Changes**.
3. Send a `QUERY`. It returns `200` and the matching posts (the sample "Hello world!" post):

   ```sh
   curl -X QUERY http://localhost:8873/wp-json/wp/v2/posts -H 'Content-Type: application/json' -d '{"search":"hello"}'
   ```

4. Click **Test your server**. It runs four checks, from your browser and from the server, and
   explains each result.

`make demo-down` stops it (data kept), `make demo-destroy` removes it (asks first). The test-site
targets never touch it. WP-CLI works as for the others (`scripts/env.sh wp demo plugin list`). For
a newer nightly, `npx wp-env start --update --config=.wp-env.demo.json`.

## Commands

Run `make help` for the list. The ones you'll use:

| Command | What it does |
|---|---|
| `make up` | Start the site. Safe to re-run |
| `make test` | Run the test suite. The site must already be up |
| `make check` | `make up`, then `make test` |
| `make status` | Show whether the site is running, and its URL |
| `make open` | Open wp-admin in your browser |
| `make down` | Stop the site. Data is kept; `make up` brings it back as it was |
| `make destroy` | Delete the site and its data. Asks first. Use it to start from scratch |
| `make dist` | Build the installable zip, `dist/wp-http-query-<version>.zip` (`VERSION=0.1.0` stamps a version in; default `0.0.0-dev`). No Docker needed. See [RELEASING.md](RELEASING.md) |

### WP-CLI

```sh
scripts/env.sh wp nightly plugin list
scripts/env.sh wp nightly core version
scripts/env.sh wp nightly option get permalink_structure
```

The first argument after `wp` picks the site (`nightly` or `demo`). The rest goes to WP-CLI.

Routes register on `rest_api_init`, which doesn't fire under WP-CLI, so the command line can't see
them. Test REST behavior over HTTP instead; that's what the test suite does.

### Running part of the suite

```sh
npx vitest run tests/gap-1-cors.test.ts      # one file
npx vitest run -t "advertises"               # tests whose name matches
npx vitest                                   # watch mode: re-runs on save
```

To point the suite at a different WordPress install that has the plugin active, set
`WP_NIGHTLY_URL`.

## How the tests are organized

**Each test file encodes one project decision**, and its header names the ADR and issue it comes
from:

| File | Decision |
|---|---|
| `gap-3-body-parsing.test.ts` | [ADR 0001](docs/decisions/0001-query-body-media-type.md) B: JSON and form-encoded bodies are both parsed |
| `adr-0001-accept-query.test.ts` | `Accept-Query` advertises `application/json` only (current default, revisitable) |
| `gap-1-cors.test.ts` | `QUERY` is in `Access-Control-Allow-Methods`, and core's defaults are kept |
| `adr-0003-no-cache-headers.test.ts` | [ADR 0003](docs/decisions/0003-cache-safety-default.md) C: no cache headers added |
| `method-override-tunnel.test.ts` | `POST` + `X-HTTP-Method-Override: QUERY` is handled as `QUERY` |
| `adr-0005-reach.test.ts` | [ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md): no route gains `QUERY` by default; the route, filter and preset opt-ins work; write handlers never gain it; a form-encoded `QUERY /wp/v2/posts` search filters a real collection |
| `lifecycle/adr-0005-preset-setting.test.ts` | ADR 0005: the stored preset setting switches reach, and the filter overrides it |
| `lifecycle/demo-route.test.ts` | The diagnostic echo route is admins only unless `wp_http_query_public_demo_route` returns true |
| `lifecycle/cors-site-choice.test.ts` | A site that removed core's CORS handler keeps REST CORS off, and a CORS callback that runs after core's still wins |
| `lifecycle/self-test.test.ts` | Not a decision: the self-test's button, REST routes and Site Health tests, and its verdicts for each documented failure mode, from fabricated responses |
| `lifecycle/plugin-lifecycle.test.ts` | [#22](../../issues/22): deactivated, the site is stock; uninstall deletes the option |
| `examples.test.ts` | Not a decision: the example plugin behind [docs/integrating.md](docs/integrating.md) works, and the guide quotes it verbatim |
| `lifecycle/examples-feature-detection.test.ts` | Not a decision: without wp-http-query, the example answers `GET` and doesn't register `QUERY` |

The tests make real HTTP requests to the site, so they cover what a client would actually see:
headers on the wire, CORS, and what the web server passes through.

**When a decision changes, its test changes in the same PR.** If a test fails because a decision
changed, update the expected value on purpose. Don't loosen the assertion.

**`it.fails` marks a decision the plugin doesn't meet yet.** The test asserts the decided behavior
and is expected to fail, so the suite stays green and the gap stays visible. When the plugin
catches up, vitest reports the `.fails` test as an error. That's your cue to remove the marker.
No test uses it today.

**Opt-ins for the tests live in a fixture mu-plugin**,
[`tests/fixtures/mu-plugins/`](tests/fixtures/mu-plugins/), mounted on every site. It enables the
`core-collections` preset, opts in `/wp/v2/taxonomies` and `/wp/v2/posts/(?P<id>[\d]+)`, and
registers two test routes. It also makes the plugin's diagnostic echo route public; it's admins only by default.
`/wp/v2/types` is deliberately left alone for the default-off tests.
Global setup seeds two posts through WP-CLI, idempotently. Changing the mappings in the
`.wp-env*.json` files needs `make up` to take effect.

**Tests that change site state go in `tests/lifecycle/`.** They deactivate the plugin, run its
uninstall routine or write options, so they'd break the main suite if they ran alongside it.
`make test` runs them after the main suite, one file at a time
(`vitest.lifecycle.config.ts`), and each restores what it changed in `afterAll`, failure or not.
`npx vitest run` alone skips them. They take about a minute and a half: every step is a WP-CLI call.

**The example plugin is tested too.** [`examples/query-route-example/`](examples/query-route-example/)
is mounted and activated on every site, next to the plugin. The full-route samples in
[docs/integrating.md](docs/integrating.md) are copied from it, and `examples.test.ts` fails if
they drift. Change the example and the guide together.

**`it.todo` marks a test that can't be written yet**, usually because the behavior isn't designed.

### Adding a test

1. Put it in the file for the decision it checks, or create `tests/<adr-or-gap>-<topic>.test.ts`
   with a header naming the decision and issue.
2. Wrap it in `describe.each( sites )` so it runs against every test site.
3. Use the helpers in [`tests/http.ts`](tests/http.ts): `rest( site, 'QUERY', route, { json } )`,
   `{ form }` for form-encoded bodies, and `tokens()` for comma-separated headers.

## Compatibility matrix

```sh
cd matrix && ./run.sh
```

Tests whether `QUERY` and its body reach PHP through each web server and SAPI, with no WordPress
involved. Requires Docker and `jq`. Results land in `matrix/results/`. See
[matrix/README.md](matrix/README.md).

## Repository layout

```
plugin/               The plugin: QUERY on the REST API, stock core, no patch
examples/             Example plugin behind docs/integrating.md, tested on every site
tests/                Decision tests (vitest, HTTP) run against the wp-env sites
scripts/env.sh        Drives the wp-env sites; wrapped by the Makefile
docs/
  integrating.md      Developer guide: adding QUERY to routes, and sending it
  scope.md            Project scope, current state of trunk, open questions
  ecosystem.md        Ecosystem support status: time-sensitive, dated, re-verified
  background.md       Project history: research log, decisions in context, upstream tickets
  decisions/          ADRs for contested design calls
matrix/
  probe/              Bare SAPI probe (no WordPress): the make-or-break test
  stacks/             Per-web-server configs
  compose.yaml        The stack matrix
  run.sh              Runs everything, emits results/results.json
  results/            Machine-readable results + generated MATRIX.md
experiments/          One-off research: blast radius, cache survey, plugin spike
```

Everything above needs only this repo. One experiment, the ADR 0002 blast-radius run, also needs
a checkout of [WordPress/wordpress-develop](https://github.com/WordPress/wordpress-develop)
(upstream or your own fork), plus a scratch worktree, a running Docker stack and a separate test
database: see [experiments/blast-radius/README.md](experiments/blast-radius/README.md#reproducing).

## PR previews

Every pull request gets one comment, updated on each push, with a **Preview in Playground**
button and a public download link for that commit's plugin zip. The button boots the latest
WordPress (PHP 8.3) in your browser via [WordPress Playground](https://playground.wordpress.net),
logged in as admin, with the zip active, the `core-collections` preset on, the
`/wp-http-query/v1/echo` demo route enabled, and Settings → HTTP QUERY open. The link needs no GitHub login.

Two workflows do it, using
[`WordPress/action-wp-playground-pr-preview`](https://github.com/WordPress/action-wp-playground-pr-preview):

- [`pr-preview-build.yml`](.github/workflows/pr-preview-build.yml) runs on the PR with a
  read-only token: `make dist`, `php -l` on the zip, upload as an artifact. This is the PR's only
  zip build.
- [`pr-preview-publish.yml`](.github/workflows/pr-preview-publish.yml) runs on `workflow_run`
  after a successful build. It copies the zip to the `ci-artifacts` prerelease as
  `pr-<N>-<SHA>.zip` (keeping the latest two per PR) and posts the comment. It never checks out
  PR code.

Things to know:

- **Publish-side changes only take effect once merged to `main`.** GitHub runs `workflow_run`
  workflows from the default branch, so editing `pr-preview-publish.yml` in a PR changes nothing
  for that PR.
- **Fork PRs.** The build runs with a read-only token, as GitHub gives every fork PR. The publish
  workflow runs in this repo's context, so fork PRs still get the comment, but only after a
  maintainer approves the workflow run if GitHub asks for that. A fork's built zip is public on
  the `ci-artifacts` release until it's pruned. Never switch either workflow to
  `pull_request_target`.
- **The preview is not the test suite.** It's Playground's PHP in your browser, not the wp-env
  sites the tests use.
- **The preview zip is unversioned.** PR builds report `Version: 0.0.0-dev`, the value `plugin/`
  always carries; real versions are stamped in only when a release is built (see
  [RELEASING.md](RELEASING.md)).

## Pull request titles

PR titles must start with a [conventional commit](https://www.conventionalcommits.org/) prefix.
They aren't cosmetic: the title labels the PR, the labels pick the next version, and the merged
titles become the release notes.

| Title | Label | Next version | Notes section |
| --- | --- | --- | --- |
| `feat: ...` | `feature` | minor | Features |
| `fix: ...` | `fix` | patch | Fixes |
| `docs: ...` | `documentation` | patch | Docs |
| `ci:`, `build:`, `test:`, `chore:`, `style:`, `refactor:` | `maintenance` | patch | Maintenance |
| a `!` before the colon (`feat!: ...`), or `BREAKING CHANGE` in the description | `breaking` (as well) | major | Breaking changes |

A scope is fine (`fix(cors): ...`). Write the rest of the title for someone reading the release
notes. A title without a prefix gets no label and lands under "Other changes" as a patch.
Retitling a PR updates its labels. Add `skip-changelog` by hand to leave a PR out of the notes.
Don't edit `Version:` or `Stable tag:` in `plugin/`, and don't add release notes to a file:
neither is part of a PR any more.

## Troubleshooting

**"Cannot connect to the Docker daemon"**: Docker isn't running. Start it and retry.

**"WordPress environments not ready … Run `make up` first"**: the tests checked every site before
running and couldn't reach one. Run `make up`, or `make status` to see whether it's down.

**"Bind for 127.0.0.1:8872 failed: port is already allocated"**: something else holds the port.
Find it with `lsof -nP -iTCP:8872 -sTCP:LISTEN`, or `docker ps` if it's another container. Stop
it, or change `port` in `.wp-env.nightly.json`. If you change it, also set `WP_NIGHTLY_URL` so the
tests follow.

**A site is in a strange state**: `make destroy`, then `make up`. That rebuilds it from nothing.

**npm warns about an install script for `fs-ext-extra-prebuilt`**: expected. wp-env pulls it in
and the Docker setup doesn't need it, so it's left unapproved.

**Nightly behaves differently from yesterday**: it tracks WordPress trunk. To pick up a newer
build, run `npx wp-env start --update --config=.wp-env.nightly.json`.

## Conventions

- **Open a draft PR while you're working,** and mark it ready for review when it's done: CI green
  and review comments addressed. Copilot reviews drafts, so you get feedback early.
- **Verify claims before writing them down.** Cite file and line, date anything time-sensitive,
  and say how you measured it. [Principles](#principles) explains why.
- **Text for other trackers is drafted, not sent.** Issues, Trac comments and PRs for other
  projects are drafted for a maintainer to review and file. Filing is a human step.
- **Use a conventional PR title** (`feat: ...`, `fix: ...`); see
  [Pull request titles](#pull-request-titles).
- **Releases follow [RELEASING.md](RELEASING.md).** Release-drafter keeps a draft GitHub release
  up to date from merged PRs; a maintainer publishes it, and the zip is built and attached then.
- **Changes to WordPress core or Requests go to those projects,** from your own checkout or fork of
  them, not this repo. This repo holds the plugin, its tests, docs and research. Keep project notes
  and scratch files out of core checkouts: a stray file has to be cleaned out before a Trac patch.

## Principles

1. **Plugin before patches.** Ship the capability as a plugin, then arrive at Trac with usage,
   matrix data and a working plugin, not an argument.
2. **Publish negative results.** A stack that drops `QUERY` is the most useful thing this
   project can document.
3. **Date everything time-sensitive.** The ecosystem is moving weekly. Undated claims rot
   silently.
4. **Additive only.** No existing route changes behavior. See
   [ADR 0002](docs/decisions/0002-allmethods-vs-queryable.md). This applies to the plugin too:
   reach is opt-in, never all routes by default
   ([ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md)).
