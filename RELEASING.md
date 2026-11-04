# Releasing the plugin

Releases ship as **GitHub releases**: a zip of `plugin/` attached to a `v*` tag. Publishing to the
WordPress.org plugin directory comes later ([#45](../../issues/45)).

**The version never lives on `main`.** The plugin header says `Version: 0.0.0-dev` and
`plugin/readme.txt` says `Stable tag: 0.0.0-dev`, permanently. Nothing commits a version bump.
The real version is stamped in when the zip is built: `make dist VERSION=0.1.0` copies `plugin/`,
rewrites those two fields in the copy only, and zips it as `dist/wp-http-query-0.1.0.zip`.

The version and the notes come from **pull request titles**, which must use conventional prefixes
(see [CONTRIBUTING.md](CONTRIBUTING.md#pull-request-titles)).

## What's automated

- **On every pull request**,
  [`release-drafter.yml`](.github/workflows/release-drafter.yml)'s autolabeler labels the PR from
  its title: `feat:` → `feature`, `fix:` → `fix`, `docs:` → `documentation`; `ci:`, `build:`,
  `test:`, `chore:`, `style:` and `refactor:` → `maintenance`; a `!` before the colon
  (`feat!:`) or `BREAKING CHANGE` in the body → `breaking`. Retitling the PR updates the labels.
  [`pr-preview-build.yml`](.github/workflows/pr-preview-build.yml) also builds the zip (as
  `0.0.0-dev`), with a Playground preview and a download link; see
  [CONTRIBUTING.md](CONTRIBUTING.md#pr-previews).
- **On every push to `main`**, release-drafter updates a **draft** release listing the PRs merged
  since the last published release, grouped into Breaking changes, Features, Fixes, Docs and
  Maintenance, with a proposed version: `breaking` → next major, `feature` → next minor, anything
  else → next patch. Its tag is `v<version>`. PRs labelled `skip-changelog` are left out.
- **When a release is published**, [`release.yml`](.github/workflows/release.yml) takes the version
  from the tag (`v0.1.0` → `0.1.0`), runs `make dist VERSION=0.1.0`, lints the PHP in the zip, and
  attaches `wp-http-query-0.1.0.zip` to the release.
- **Every day at 06:00 UTC**, [`nightly.yml`](.github/workflows/nightly.yml) builds `main` as the
  rolling [**Nightly** prerelease](../../releases/tag/nightly), if `main` changed since the last
  one (see below).

Configuration for the autolabeler and the draft is in
[`.github/release-drafter.yml`](.github/release-drafter.yml).

## Cutting a release

1. **Merge PRs with conventional titles.** Each merge to `main` updates the draft release.
2. **Check the draft.** Under Releases, open the draft (e.g. `v0.1.0`):
   - Is the **proposed version** right? It follows the labels of the merged PRs. If a PR was
     labelled wrong, fix its title (or labels) and re-run the latest *Release Drafter* run on
     `main`, or edit the tag and title on the draft by hand. Keep the `v` prefix and a semver
     version (`v0.2.0`, `v1.0.0-rc.1`); the release workflow refuses anything else.
   - Read the **notes** and edit them on the draft as needed; that text is the release notes.
     Pre-1.0, a `breaking` PR proposes `1.0.0`. If that isn't intended yet, change the version
     on the draft.
3. **Publish.** Click *Publish release*. That creates the tag on `main` and starts
   [`release.yml`](.github/workflows/release.yml), which attaches the zip within a minute or two
   (watch it in the Actions tab or with `gh run watch`).
4. **Check the zip.** Download `wp-http-query-<version>.zip` from the release: `unzip -l` shows one
   top-level folder, `wp-http-query/`, and its header says `Version: <version>`. Installing it on a
   test site (Plugins → Add New → Upload Plugin) and activating it is the real check.

If the zip didn't attach, re-run the failed *Release* run from the Actions tab; the upload
replaces any existing asset of the same name.

### Prereleases

To ship a beta or release candidate, edit the draft's tag and title to a prerelease version
(`v0.2.0-beta.1`, `v0.2.0-rc.1`), tick **Set as a pre-release**, and publish. The release workflow
stamps that version and attaches the zip as usual. Release-drafter ignores prereleases when it
works out the next draft, so the following draft still lists everything since the last full
release.

## The nightly

The **Nightly** prerelease (tag `nightly`) is an unreleased build of `main`, for testing:

- One release, updated in place; no other releases are created, and it's never marked latest.
- Its single asset is always named `wp-http-query-nightly.zip`, so
  `https://github.com/moonmeister/wp-http-query/releases/download/nightly/wp-http-query-nightly.zip`
  always gets the newest build.
- The plugin inside reports `Version: 0.0.0-nightly.<YYYYMMDD>+<short sha>`. The release body gives
  the build date and the full commit.
- Days when `main` hasn't moved are skipped: if the `nightly` tag already points at `main`'s HEAD
  and the zip is attached, the run exits without building
  ([`scripts/nightly-should-build.sh`](scripts/nightly-should-build.sh)).

**Run it by hand** from the Actions tab (*Nightly* → *Run workflow*), or:

```sh
gh workflow run nightly.yml
```

It always builds `main`, whichever branch you start it from, and still skips if `main` hasn't
moved.

## Building a zip locally

```sh
make dist                   # dist/wp-http-query-0.0.0-dev.zip
make dist VERSION=0.1.0     # dist/wp-http-query-0.1.0.zip, stamped Version: 0.1.0
```

`VERSION` must be semver (`x.y.z`, optionally with `-prerelease` and/or `+build`); anything else
fails the build. `plugin/` itself is never modified.

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
