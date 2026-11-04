# Releasing the plugin

Releases ship as **GitHub releases**: a zip of `plugin/` attached to a tag. Publishing to the
WordPress.org plugin directory comes later ([#45](../../issues/45)).

The version lives in one place: the `Version:` field of the header in
[`plugin/wp-http-query.php`](plugin/wp-http-query.php). `make dist` and the release workflows read
it from there. `plugin/readme.txt`, when it exists, repeats it as `Stable tag:`, and the two must
match.

**Releasing is a pull request.** Merge a PR that sets a non `-dev` version, and the rest happens
automatically. Nothing is published until you publish the draft release.

## What's automated

- [`.github/workflows/release-on-merge.yml`](.github/workflows/release-on-merge.yml) runs on every
  push to `main`. It's a no-op unless the push changed the header `Version` to a non `-dev`
  version that has no tag yet ([`scripts/release-detect.sh`](scripts/release-detect.sh)). When it
  did, it:
  1. checks the release (`scripts/release-check.sh`): `-dev` versions are refused, `readme.txt`'s
     `Stable tag` must equal `Version`, and `CHANGELOG.md` must have a section for the version;
  2. tags the merge commit `v<Version>` and pushes the tag;
  3. runs `make dist` and creates a **draft** GitHub release with the zip attached and the
     CHANGELOG section as the notes (`scripts/draft-release.sh`);
  4. opens a PR starting the next development version (`scripts/open-dev-bump-pr.sh`).
- [`.github/workflows/release.yml`](.github/workflows/release.yml):
  - **On every pull request** it runs `make dist`, lints the PHP in the zip, and attaches the zip to
    the run as the `wp-http-query-zip` artifact.
  - **On a `v*` tag pushed by a person** it runs the same `scripts/draft-release.sh`. This is the
    [manual fallback](#fallback-tag-by-hand). Tags pushed by `release-on-merge.yml` don't trigger
    it, so there's never a second release.

## Steps

Use `0.1.0` below as the example; substitute the version you're releasing.

1. **Open the version-bump PR.** On a branch from `main`:
   - In `plugin/wp-http-query.php`, change `Version: 0.1.0-dev` to `Version: 0.1.0`.
   - In `plugin/readme.txt` (if it exists), change `Stable tag: 0.1.0-dev` to `Stable tag: 0.1.0`.
   - In `CHANGELOG.md`, rename `## [Unreleased]` to `## [0.1.0] - YYYY-MM-DD` (the date you expect
     to merge). Don't add a new `Unreleased` section or touch the links at the bottom; the
     follow-up PR does both.
   - Check it locally:

     ```sh
     scripts/release-check.sh v0.1.0   # the check the workflow runs before tagging
     make dist                         # builds dist/wp-http-query-0.1.0.zip
     unzip -l dist/wp-http-query-0.1.0.zip
     ```

   Open the PR and let CI pass.

2. **Merge it.** `release-on-merge.yml` tags the merge commit `v0.1.0`, builds the zip, creates the
   draft release, and opens the follow-up PR. Watch it in the Actions tab, or with `gh run watch`.
   If the check fails, nothing is tagged: fix `main` in another PR. Changing `Version` again (to
   `0.1.1`, say) re-triggers it; to release the same version, use the
   [fallback](#fallback-tag-by-hand).

3. **Check and publish the draft release.** Under Releases, open the `v0.1.0` draft:
   - Read the notes. They are the `0.1.0` section of `CHANGELOG.md`, verbatim. Edit them on the
     release if needed (and fix `CHANGELOG.md` in a follow-up PR so the two agree).
   - Download `wp-http-query-0.1.0.zip` and inspect it: `unzip -l` shows one top-level folder,
     `wp-http-query/`, holding only the files a site needs. Installing it on a test site
     (Plugins → Add New → Upload Plugin) and activating it is the real check.
   - Click *Publish release*.

4. **Merge the follow-up PR**, `chore: start 0.2.0-dev` (branch `release/start-0.2.0-dev`). It sets
   `Version` and `Stable tag` to `0.2.0-dev`, adds an empty `## [Unreleased]` section to
   `CHANGELOG.md`, and points the `[Unreleased]` and `[0.1.0]` links at the new tag. It always
   bumps the minor version; for a patch release next, change it to `0.1.1-dev` on the branch
   before merging. **It has no CI yet** (see below): push a commit to the branch, or close and
   reopen the PR, to start it.

## GitHub limitations

- **The follow-up PR doesn't trigger CI by itself.** The workflow opens it with `GITHUB_TOKEN`,
  and events caused by `GITHUB_TOKEN` don't start new workflow runs, so `pull_request` workflows
  don't run on it until someone pushes to the branch or closes and reopens the PR. (The same rule
  is why the tag the workflow pushes doesn't fire `release.yml`.)
- **Opening the follow-up PR needs a repo setting:** *Settings → Actions → General → Allow GitHub
  Actions to create and approve pull requests*. Without it the release still happens and the
  branch is still pushed, but the last step fails with a link to open the PR by hand.

## Fallback: tag by hand

If `release-on-merge.yml` didn't run or failed before tagging (and `main` already has the version
you want to release), tag `main` yourself:

```sh
git switch main && git pull
scripts/release-check.sh v0.1.0
git tag -a v0.1.0 -m "v0.1.0"
git push origin v0.1.0
```

`release.yml` checks the tag and creates the draft release, exactly as the automatic path does.
If its check fails, delete the tag (`git push origin :v0.1.0 && git tag -d v0.1.0`), fix `main`
through a PR, and tag again. Then start the next `-dev` version yourself: `scripts/bump-dev.sh
0.1.0` makes the same changes as the follow-up PR.

If the workflow tagged and drafted but couldn't open the follow-up PR, open it from the pushed
`release/start-*` branch (the run's error links to it).

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
