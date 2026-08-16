# Issue plan

**Working document.** This is the source for the GitHub issue tree. **It gets deleted once the
issues exist** — issues become the single source of truth, and this file would only drift.

Structure agreed 2026-08-16: organized by **capability delivered**, not by venue. Venue is a
label. Each parent carries research → code → report children, because on this project
*reporting is a deliverable* — the pitch is "arrive with verified evidence," so a patch nobody
writes up is worth about as much as no patch.

Detail lives in [scope.md](scope.md) and [decisions/](decisions/); issue bodies quote the
specific `file:line` evidence rather than only linking out.

---

## Repo

`moonmeister/wp-http-query`, **public**, `main`, 7 commits.

> **Auth note.** `GITHUB_TOKEN` is exported in the environment with only `read:packages`, and it
> takes precedence over the keyring credential from `gh auth login`, which has `repo`. Prefix gh
> commands with `env -u GITHUB_TOKEN` to use the keyring one.

## Labels

`venue:this-repo` · `venue:core` · `venue:requests` · `venue:test-server` · `venue:standards` ·
`venue:user-docs`
`phase:research` · `phase:code` · `phase:report`
`decision` · `blocked:human` · `good first issue` · `deferred`

## Milestones

| Milestone | Meaning |
|---|---|
| **Core patch v1** | Must be done before posting anything to Trac#65616. Closes when patch + plugin demo + matrix land on the ticket. |
| **Ecosystem** | Parallel, non-gating. Upstream and standards work. |
| **Deferred** | Named so it is not rediscovered as a gap. Not now. |

---

## 1. Method constants & route matching — gap 2

*Parent.* `venue:core` · Core patch v1

`class-wp-rest-server.php:24-56` — no method constant includes `QUERY`, so `ALLMETHODS` routes
will not answer it. The most contested decision in the project.

| Phase | Child | Labels |
|---|---|---|
| R | Read Trac#65616 and record its contents | `phase:research` `venue:core` `blocked:human` |
| R | Measure ADR 0002 blast radius across core's test suite | `phase:research` `venue:this-repo` |
| R | Decide ADR 0002 — `ALLMETHODS` vs `QUERYABLE` | `phase:research` `decision` |
| C | Add the method constant + BC tests | `phase:code` `venue:core` |
| P | Post the constant rationale + failure counts to Trac#65616 | `phase:report` `venue:core` |

**Read Trac#65616** is the project's only hard blocker. No tool here can fetch Trac — it returns
403 with a browser challenge, confirmed twice. Only the ticket number is known; title, status,
component and contents are unverified. Needs: does it contain the original `ALLMETHODS`
rationale (Q3)? That gates the ADR.

**Blast radius** is the cheap unblock. Exact-match `Allow` assertions at
`rest-posts-controller.php:256,266`, `rest-attachments-controller.php:374,383`,
`rest-server.php:397,437,476,536` act as a tripwire — implement each option on a scratch branch
and the failure count *is* the blast radius. Measures core only; plugin breakage must be argued.

## 2. Request body parsing — gap 3

*Parent.* `venue:core` · Core patch v1

`class-wp-rest-request.php:377-380` — `$accepts_body_data` excludes `QUERY`, so a form-encoded
body is parsed by `parse_body_params()` and then silently dropped from the lookup order.

| Phase | Child | Labels |
|---|---|---|
| R | Decide ADR 0001 — QUERY body media type | `phase:research` `decision` |
| C | Add `QUERY` to `$accepts_body_data` + tests | `phase:code` `venue:core` |
| P | Write up the body-parsing rationale for the ticket | `phase:report` `venue:core` |

Evidence is already in: the matrix showed PHP never populates `$_POST` for `QUERY` even with a
form-encoded content type, and `parse_body_params()` parses the raw body precisely for "request
methods that aren't supported natively by PHP" (`class-wp-rest-request.php:746-747`). Option B
is genuinely one line.

## 3. CORS preflight — gap 1

*Parent.* `venue:core` · Core patch v1

`rest-api.php:814` — `Access-Control-Allow-Methods` is hardcoded with no filter at that point,
so every cross-origin `QUERY` preflight fails.

| Phase | Child | Labels |
|---|---|---|
| C | Make `Access-Control-Allow-Methods` filterable + tests | `phase:code` `venue:core` |
| P | Propose as a standalone Trac ticket | `phase:report` `venue:core` |

No ADR behind it. **Independently landable, and probably should be** — a hardcoded header list
with no filter is a defect regardless of `QUERY`, and a small self-contained patch is far easier
to land than a feature.

## 4. Cache safety & `Accept-Query`

*Parent.* `venue:core` · Core patch v1

Security-relevant. No `Vary: body` exists, and trunk emits no explicit `Cache-Control` for
anonymous REST responses (`class-wp-rest-server.php:487`), so a body-blind shared cache can
serve one user's result set to another.

| Phase | Child | Labels |
|---|---|---|
| R | Decide ADR 0003 — cache-safety default | `phase:research` `decision` |
| R | Decide ADR 0004 — `Location` indirection | `phase:research` `decision` `deferred` |
| C | Emit `Accept-Query` + the cache-safety default | `phase:code` `venue:core` |
| P | Put the security rationale in the ticket explicitly | `phase:report` `venue:core` |

The report step is not optional: a security-relevant default that merely *looks* conservative
will get "simplified" by a later contributor. ADR 0003 is narrowed — with Axis B descoped there
will never be evidence a downstream cache is body-aware, so option C is indefensible.

## 5. Feature plugin demo

*Parent.* `venue:this-repo` · Core patch v1

`plugin/wp-http-query.php` works around all three gaps in userland, so it should demonstrate the
end-to-end path on stock core with no patch — the strongest single artifact to bring to Trac.
**It has never been executed.** Until it runs, that is a claim, not a demonstration.

| Phase | Child | Labels |
|---|---|---|
| C | Run the plugin against a real WordPress install | `phase:code` `venue:this-repo` |
| P | Publish it as the "works on stock core" artifact | `phase:report` `venue:this-repo` |

Verify: native `QUERY` route round-trips; `X-HTTP-Method-Override` fallback works;
`Accept-Query` is valid Structured Fields; CORS preflight passes; `private, no-cache` lands.

## 6. PHP client — `WP_Http` / Requests

*Parent.* `venue:requests` · Ecosystem

`QUERY` already transits both transports; the gap is **contract, not capability**. No
`Requests::QUERY` constant, and `class-wp-http.php:115-118` documents a method list excluding it.

| Phase | Child | Labels |
|---|---|---|
| R | Review Requests#1075 against WordPress's needs | `phase:research` `venue:requests` |
| C | Help unblock test-server#13 | `phase:code` `venue:test-server` `good first issue` |
| C | Update `WP_Http`'s contract after the upstream release | `phase:code` `venue:core` |
| P | Comment findings on Requests#1074 / #1075 | `phase:report` `venue:requests` |

Not our authorship — #1075 is dingo-d's, open with a maintainer triaging it, milestone 2.1.0.
**test-server#13 is gating its CI and is the single most useful place outside help can go**: a
concrete blocker on already-moving work rather than a proposal needing consensus.

> Core does not accept patches to vendored dependencies. Anything under
> `src/wp-includes/Requests/` must land upstream first and arrive via a version bump — which is
> why this track runs ahead of the core track despite not being ours.

## 7. JS clients

*Parent.* `venue:this-repo` · Ecosystem

| Phase | Child | Labels |
|---|---|---|
| R | Test `@wordpress/api-fetch` against source (Q7) | `phase:research` |
| R | Test browser `fetch()` across Chrome / Firefox / Safari | `phase:research` |
| P | Publish client results | `phase:report` |

Q7 is genuinely unknown — research claims were refuted or split, so it must be read against
source. Watch the normalization trap: `QUERY` is absent from Fetch's "normalize a method" list,
so lowercase `'query'` transmits verbatim. Always pass the uppercase literal.

---

## Standalone — no parent

| Issue | Labels | Milestone |
|---|---|---|
| Re-file WebKit standards position #692 with the correct template | `venue:standards` `good first issue` `phase:report` | Ecosystem |
| Document the hardening-allowlist 403 failure mode | `venue:user-docs` `phase:report` | Ecosystem |
| Axis A leftovers — HTTP/2, HTTP/3, TLS, OpenLiteSpeed | `venue:this-repo` `deferred` | Deferred |
| First adopter — `/wp/v2/search` QUERY handler | `venue:core` `deferred` | Deferred |

**WebKit#692** was closed `invalid` for using custom formatting instead of the issue template,
with an explicit invitation to re-file, and never was. WebKit has no position on `QUERY` — it
has not been asked in a form its tooling accepts. Minutes of work.

**Hardening allowlist**: `limit_except GET POST` (nginx) and `<LimitExcept GET POST>` (Apache)
both 403 `QUERY`. Measured. First wall a real site owner hits, site configuration rather than a
WordPress defect, unfixable by any patch — so it needs a documented answer before anyone is told
the feature works, or the first bug reports land against WordPress. Also note `php -S` 501s.

---

## Count

7 parents + 23 children + 4 standalone = **34 issues**.

## Explicitly not doing

Full reasoning in [scope.md](scope.md) §3 and §6.

| | Why |
|---|---|
| Matrix Axis B — CDNs, WAFs, managed hosts | Outside the WordPress boundary; Axis A suffices for a readiness claim |
| Designing a query language | ADR 0001 option C, deferred |
| Migrating existing core endpoints | Capability, not adoption |
| Lobbying nginx, CDNs, or hosts | Tracked, not gated on |
| Authoring the Requests PR | #1075 already exists and is being triaged |
