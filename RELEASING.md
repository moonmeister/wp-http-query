# Releasing the plugin

Releases ship as **GitHub releases**: a zip of `plugin/` attached to a tag. Publishing to the
WordPress.org plugin directory comes later ([#45](../../issues/45)).

The version lives in one place: the `Version:` field of the header in
[`plugin/wp-http-query.php`](plugin/wp-http-query.php). `make dist` and the release workflow read
it from there.

## What's automated

- **On every pull request**, [`pr-preview-build.yml`](.github/workflows/pr-preview-build.yml) runs
  `make dist`, lints the PHP in the zip, and attaches it to the run (artifact
  `wp-playground-preview-pr<N>-<SHA>`, holding `zips/wp-http-query.zip`). The PR also gets a
  Playground preview and a public download link; see
  [CONTRIBUTING.md](CONTRIBUTING.md#pr-previews).
- **On a pushed `v*` tag**, [`release.yml`](.github/workflows/release.yml) checks the tag against the header (`v0.1.0` needs `Version: 0.1.0`,
  and `-dev` versions are refused), checks `CHANGELOG.md` has a section for the version, runs
  `make dist`, and creates a **draft** GitHub release with the zip attached and that section as
  the notes. Nothing is published until you publish it.

## Steps

Use `0.1.0` below as the example; substitute the version you're releasing.

1. **Prepare the release in a PR.** On a branch from `main`:
   - In `plugin/wp-http-query.php`, change `Version: 0.1.0-dev` to `Version: 0.1.0`.
   - In `CHANGELOG.md`, add a `## [0.1.0] - YYYY-MM-DD` heading (today's date) under
     `## [Unreleased]` so the existing entries move under it, leaving `Unreleased` empty. Update the
     link references at the bottom: `[0.1.0]` pointing at the tag
     (`https://github.com/moonmeister/wp-http-query/releases/tag/v0.1.0`), and `[Unreleased]` at
     `https://github.com/moonmeister/wp-http-query/compare/v0.1.0...HEAD`.
   - Check it locally:

     ```sh
     scripts/release-check.sh v0.1.0   # the same check the workflow runs on the tag
     make dist                         # builds dist/wp-http-query-0.1.0.zip
     unzip -l dist/wp-http-query-0.1.0.zip
     ```

   Open the PR, let CI pass, and merge it.

2. **Tag `main` and push the tag.**

   ```sh
   git switch main && git pull
   git tag -a v0.1.0 -m "v0.1.0"
   git push origin v0.1.0
   ```

   This starts the release workflow. Watch it in the Actions tab, or with `gh run watch`. If the
   tag check fails, delete the tag (`git push origin :v0.1.0 && git tag -d v0.1.0`), fix `main`
   through a PR, and tag again.

3. **Check the draft release.** Under Releases, open the `v0.1.0` draft:
   - Read the notes. They are the `0.1.0` section of `CHANGELOG.md`, verbatim. Edit them on the
     release if needed (and fix `CHANGELOG.md` in a follow-up PR so the two agree).
   - Download `wp-http-query-0.1.0.zip` and inspect it: `unzip -l` shows one top-level folder,
     `wp-http-query/`, holding only the files a site needs. Installing it on a test site
     (Plugins → Add New → Upload Plugin) and activating it is the real check.

4. **Publish the release.** Click *Publish release* on the draft.

5. **Start the next development version.** In a PR, set `Version:` to the next version with `-dev`
   (`0.1.1-dev`, or `0.2.0-dev` if new features are planned) and merge it.

## After the release

- **The first published release is the trigger for [#39](../../issues/39):** the page-cache
  reports for WP Super Cache and W3 Total Cache are written and parked until there's a released
  plugin that sends `QUERY`. Once v0.1.0 is published, they can be filed (filing is still a human
  step; see [CONTRIBUTING.md](CONTRIBUTING.md#conventions)).
- **WordPress.org** publishing is tracked in [#45](../../issues/45).

## What goes in the zip

`make dist` (`scripts/dist.sh`) builds `dist/wp-http-query-<version>.zip` from an explicit list of
files in `plugin/`, set at the top of `scripts/dist.sh`: `SHIP` (required), `SHIP_IF_PRESENT`
(shipped when it exists, e.g. `readme.txt`) and `DEV_ONLY` (never shipped). Any other file in
`plugin/` fails the build, so adding a file there means deciding whether sites get it.
