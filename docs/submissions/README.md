# Submissions — text written for somewhere else

Reports, tickets and issue bodies drafted here and destined for an upstream tracker. **Nothing
in this directory has been sent.** Filing is a human step, always.

| Draft | Target | Status |
| --- | --- | --- |
| [`trac-65616-approach.md`](trac-65616-approach.md) | [Trac #65616](https://core.trac.wordpress.org/ticket/65616) | **In draft** — reply to khokansardar's three-phase proposal |
| [`wp-super-cache-method-denylist.md`](wp-super-cache-method-denylist.md) | [`Automattic/wp-super-cache`](https://github.com/Automattic/wp-super-cache) | **Parked** — file on trigger, [#39](../../../../issues/39) |
| [`w3-total-cache-method-denylist.md`](w3-total-cache-method-denylist.md) | [`BoldGrid/w3-total-cache`](https://github.com/BoldGrid/w3-total-cache) | **Parked** — file on trigger, [#39](../../../../issues/39) |

Each is read against a specific version — a plugin release for the two cache reports, a trunk
revision for the Trac draft — stated at the top of the file. **Re-verify before filing.** The
whole point of citing file and line is that the citation is checkable, and a stale one is worse
than none.

## Parked, not blocked

The two page-cache reports are finished. They are waiting on a **trigger**, not on review or
information: nothing sends `QUERY` to a WordPress site today, so the hazard they describe is not
reachable, and a maintainer is right to deprioritize a report about a method that does not
arrive. They go out when the feature plugin is published
([#23](../../../../issues/23)) or `QUERY` dispatch ships in a core release
([#11](../../../../issues/11)).

Each draft ends with a **"notes for Alex"** section — filing decisions, tone, and what has and
has not been reproduced. That section is not part of the issue text. Delete it on the way out.

## Why the drafts exist before they are needed

Two reasons, and the second is the real one:

1. The findings are fresh now. Re-deriving them from the survey in a year costs more than
   re-checking line numbers against a new release.
2. **It converts an assurance into an artifact.** [#21](../../../../issues/21) has to answer
   "what about page caches?" on Trac. *"Two plugins are affected, here are the exact checks,
   here are the written reports, they go out on release"* is a claim a reviewer can audit.
   *"Plugins can fix this when it ships"* is not.
