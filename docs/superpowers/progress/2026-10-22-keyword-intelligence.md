# Keyword Intelligence Progress

Branch: `feature/keyword-intelligence`
Plan: `docs/superpowers/plans/2026-10-22-keyword-intelligence.md`

Repository evidence and current CI are authoritative. Historical task designs and
RED contracts remain under `docs/superpowers/specs/` and
`docs/superpowers/contracts/`.

## Task status

- Task 1 — Intent domain and override history: **verified**
  - Immutable assignment history, manual override precedence, project isolation,
    ChangeSet/Activity attribution. CI `39784623206`.
- Task 2 — Deterministic Persian intent engine: **verified**
  - Explainable deterministic rules; ambiguous input stays Mixed/Unknown rather
    than false certainty. CI `39786352782`.
- Task 3 — Intent Review Queue: **verified**
  - Idempotent/stale-safe decisions, project authorization, keyboard Persian RTL
    workflow. CI `39788659107` on `a85910065ec85b94d86ae301767e9405be1da0a3`.
- Task 4 — Cluster domain/membership: **verified**
  - Project-scoped membership and primary invariant, transactional/audited create,
    lock persistence. MariaDB 10.11 coverage. CI `39790364710` on
    `e720c0a6bf21ea3e9acbf95b4ace02651ae92e43`.
- Task 5 — Lexical candidate generation/application: **verified**
  - Deterministic bounded lexical blocking, explainable evidence, opt-in only for
    High confidence, uncertain decisions in Review Queue, no all-pairs scan.
    Final CI `39799527623` on `9fb589035cec7c3f430e5959b1113dedfb012504`.
- Task 6 — Cluster merge/split/move/lock + Undo: **verified**
  - Project-first serialization, attributed history, lock/manual preservation and
    revision-safe Undo. MariaDB coverage. CI `39801676900` on
    `9adedd5238368b8d23b13552066bbd8935974c5a`.
- Task 7 — Existing/planned Page foundation: **verified**
  - Conservative URL identity, IDN-safe normalization, project isolation, stable
    planned→existing promotion. CI `39803137862` on
    `c0af6e086069b36f015371d2df52374ddc0d2d4c`.
- Task 8 — Cluster target mapping: **verified**
  - Target kinds, one current mapping, project isolation, mapping history,
    promotion identity, deletion restrictions and stale-Undo protection.
  - 204 tests / 1166 assertions at boundary; MariaDB mapping coverage.
  - CI `39804656866` on `d19e4ab390668ed606b8414c069e1a197a4e768e`.
- Task 9 — Mapping conflict review: **verified**
  - Original pending RED source was restored and corrected to current provenance
    schema; final contract includes unrelated shared-page overload, competing pages
    requiring same known intent + shared meaningful topic, real latest-volume
    high-demand unmapped evidence, changed-evidence reopen/supersede, stale
    acknowledgement refusal, invalid-target warning, HTTP resolution and foreign
    project rejection.
  - Detector is deterministic/bounded, groups rather than enumerating all pairs,
    never silently remaps and keeps browser evidence compact.
  - Mapping-conflict ReviewItems retain resolved decisions for identical evidence,
    reopen only when fingerprints change and audit resolution/supersession.
  - Verified implementation boundary `bd38cc696de7eaca60776c52abd62c4e32929fe1`;
    CI `39810763754`, success; 212 tests / 1187 assertions.
- Task 10 — Priority scoring + Ready lifecycle: **verified**
  - `ClusterPriorityScore` is deterministic/explainable. It uses only latest local
    volume, latest local difficulty, optional manual business relevance (1–5),
    mapping completeness and review completeness. Missing metrics remain null and
    add no invented priority; component evidence and data completeness are exposed.
  - Clusters have separate `planning|ready` lifecycle. Ready requires an active,
    reviewed cluster, an explicit mapping (page target or Ignore), and no pending
    primary-keyword review.
  - Real remaps, membership/cluster mutations and new/reopened pending reviews
    invalidate stale Ready decisions back to Planning. Cluster mutation snapshots
    include lifecycle/business relevance so Undo remains revision-safe.
  - RED evidence included missing priority object/lifecycle behavior and a later
    regression proving new mapping conflicts must invalidate an existing Ready
    decision. CI/static-analysis failures were diagnosed rather than weakening
    gates.
  - Final verified implementation `f51f5d76aca3331153383726bbc76eebffab6846`;
    CI `39813493267`, success; **226 tests / 1229 assertions**. PHP 8.4/8.5,
    frontend check/TypeScript/build, Pint, PHPStan, runtime audits and release
    smoke all passed.
- Task 11 — Connected Keyword Intelligence workspace + acceptance: **active**
  - The existing `intelligence` server prop is now connected to the Keywords page
    instead of remaining dead UI. The workspace exposes Grid, Intent, Clusters and
    Mapping entry points while preserving the existing server-paginated Grid.
  - Manual cluster merge/move/split controls call only the existing project-scoped,
    service-backed HTTP boundaries and respect cluster locks. Mapping, priority,
    conflict refresh, Review Queue and Ready operations remain service-owned.
  - Acceptance now requires explicit `intelligence.project_id` so the client does
    not infer project identity from URL shape. RED was observed on CI `39826284166`:
    only the new property assertion failed while 236 tests passed. The minimal
    backend/type/UI contract is now implemented and awaiting a permanent full CI.
  - Final acceptance, Persian RTL/keyboard/empty/error checks, whole-branch review
    and fresh final-head CI are still required before PR #2 may merge.

## Execution rules

Continue RED → correct RED → minimal GREEN → refactor → meaningful focused
verification → permanent final-head CI → semantic commit/push. Do not advance a
task while its current implementation boundary is unverified. Human overrides,
locks, original keyword/provenance data and later mapping decisions must never be
silently overwritten. No crawler/SERP/AI/vector dependency belongs in Task 11.