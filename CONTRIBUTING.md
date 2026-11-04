# Contributing

This repo holds the **feature plugin** ([`plugin/`](plugin/)) plus the docs, decision records and
compatibility matrix behind it. Start with the [README](README.md) for what the project is, and
[#37](../../issues/37) for the current working order.

## Prerequisites

| Tool | Why | Check |
|---|---|---|
| **Docker** (Desktop, OrbStack, or similar) | Runs the WordPress test sites. **It must be running** before any `make` target that touches the sites | `docker info` |
| **Node.js 20+** and npm | Runs [wp-env](https://www.npmjs.com/package/@wordpress/env) and the [vitest](https://vitest.dev) suite | `node -v` |
| **make** | Entry point for everything below. Preinstalled on macOS and most Linux | `make -v` |
| `jq` *(matrix only)* | Used by `matrix/run.sh` | `jq --version` |

You don't need PHP, MySQL or a local WordPress install. Docker provides all of them.

## First run

```sh
make check
```

The first run installs npm dependencies, downloads three WordPress builds and the Docker images,
then runs the tests. Expect a few minutes. Later starts take seconds.

CI runs the same `make check` on every PR and on pushes to `main` ([`.github/workflows/test.yml`](.github/workflows/test.yml)).

You get three WordPress sites, all with `plugin/` mounted and active:

| Site | URL | Core | Why it exists |
|---|---|---|---|
| **7.0** | <http://localhost:8870> | 7.0.8, pinned | Previous release. Tests the plugin's pre-7.2 CORS fallback |
| **7.1** | <http://localhost:8871> | 7.1.4, pinned | Current release. Same fallback path |
| **nightly** | <http://localhost:8872> | Latest nightly build | Tests the 7.2+ path through core's `rest_allowed_cors_methods` |

The releases are pinned so the pre-7.2 path stays covered after 7.2 ships.

Admin is at `/wp-admin`, user `admin`, password `password`. `make open` opens it for you.

The plugin directory is **mounted, not copied**, so edits to `plugin/wp-http-query.php` take effect
on the next request. No restart needed.

## Commands

Run `make help` for the list. The ones you'll use:

| Command | What it does |
|---|---|
| `make up` | Start all three sites. Safe to re-run |
| `make test` | Run the test suite against all three sites. The sites must already be up. `make test SITES=7.0,nightly` runs a subset |
| `make check` | `make up`, then `make test` |
| `make status` | Show whether each site is running, and its URL |
| `make open` | Open wp-admin for all three sites in your browser. `make open ENV=nightly` opens one |
| `make down` | Stop all three sites. Data is kept; `make up` brings them back as they were |
| `make destroy` | Delete all three sites and their data. Asks before each one. Use it to start from scratch |

### WP-CLI

```sh
scripts/env.sh wp 7.1 plugin list
scripts/env.sh wp nightly core version
scripts/env.sh wp nightly option get permalink_structure
```

The first argument after `wp` picks the site (`7.0`, `7.1` or `nightly`). The rest goes to WP-CLI.

The plugin's REST hooks attach on `rest_api_init`, which doesn't fire under WP-CLI, so checks like
`has_filter( 'rest_allowed_cors_methods' )` report `false` from the command line. Test REST
behavior over HTTP instead; that's what the test suite does.

### Running part of the suite

```sh
npx vitest run tests/gap-1-cors.test.ts      # one file
npx vitest run -t "advertises"               # tests whose name matches
SITES=nightly npx vitest run                 # one site
npx vitest                                   # watch mode: re-runs on save
```

To point the suite at a different WordPress install that has the plugin active, set
`WP_70_URL`, `WP_71_URL` or `WP_NIGHTLY_URL`.

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
| `adr-0005-reach.test.ts` | [ADR 0005](docs/decisions/0005-reach-for-existing-read-routes.md): existing routes don't gain `QUERY` by default |

The tests make real HTTP requests to both sites, so they cover what a client would actually see:
headers on the wire, CORS, and what the web server passes through.

**When a decision changes, its test changes in the same PR.** If a test fails because a decision
changed, update the expected value on purpose. Don't loosen the assertion.

**`it.fails` marks a decision the plugin doesn't meet yet.** The test asserts the decided behavior
and is expected to fail, so the suite stays green and the gap stays visible. When the plugin
catches up, vitest reports the `.fails` test as an error. That's your cue to remove the marker.
Today this applies to the reach tests, until [#1](../../issues/1) replaces the every-`GET`-route
placeholder.

**`it.todo` marks a test that can't be written yet**, usually because the behavior isn't designed.

### Adding a test

1. Put it in the file for the decision it checks, or create `tests/<adr-or-gap>-<topic>.test.ts`
   with a header naming the decision and issue.
2. Wrap it in `describe.each( sites )` so it runs against both cores.
3. Use the helpers in [`tests/http.ts`](tests/http.ts): `rest( site, 'QUERY', route, { json } )`,
   `{ form }` for form-encoded bodies, and `tokens()` for comma-separated headers.

## Troubleshooting

**"Cannot connect to the Docker daemon"**: Docker isn't running. Start it and retry.

**"WordPress environments not ready … Run `make up` first"**: the tests checked every site before
running and couldn't reach one. Run `make up`, or `make status` to see which site is down.

**"Bind for 127.0.0.1:8871 failed: port is already allocated"**: something else holds the port.
Find it with `lsof -nP -iTCP:8871 -sTCP:LISTEN`, or `docker ps` if it's another container. Stop
it, or change `port` in the site's config: `.wp-env.7.0.json`, `.wp-env.json` (7.1) or
`.wp-env.nightly.json`. If you change a port, also set `WP_70_URL`, `WP_71_URL` or `WP_NIGHTLY_URL`
so the tests follow.

**A site is in a strange state**: `make destroy`, then `make up`. That rebuilds it from nothing.

**npm warns about an install script for `fs-ext-extra-prebuilt`**: expected. wp-env pulls it in
and the Docker setup doesn't need it, so it's left unapproved.

**Nightly behaves differently from yesterday**: it tracks WordPress trunk. To pick up a newer
build, run `npx wp-env start --update --config=.wp-env.nightly.json`.

## Conventions

- **Branch, then open a draft PR.** A maintainer marks it ready.
- **Verify claims before writing them down.** Cite file and line, date anything time-sensitive,
  and say how you measured it. The README's [Principles](README.md#principles) explain why.
- **Text for other trackers is drafted, not sent.** [`docs/submissions/`](docs/submissions/) holds
  drafts for Trac and other projects. Filing is a human step.
- **Keep the core fork clean.** Core patches live in the `wordpress-develop` fork. Project notes,
  scratch files and experiments belong here, not there.
