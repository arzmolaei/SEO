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
  - Final contract covers unrelated shared-page overload, competing pages only
    with same known intent plus shared meaningful topic, real latest-volume
    high-demand unmapped evidence, changed-evidence reopen/supersede, stale
    acknowledgement refusal, invalid-target warning, HTTP resolution and foreign
    project rejection.
  - Detector is deterministic/bounded, groups rather than enumerating all pairs,
    never silently remaps and keeps browser evidence compact.
  - Mapping-conflict ReviewItems retain resolved decisions for identical evidence,
    reopen only when fingerprints change and audit resolution/supersession.
  - Verified boundary `bd38cc696de7eaca60776c52abd62c4e32929fe1`;
    CI `39810763754`, success; 212 tests / 1187 assertions.
- Task 10 — Priority scoring + Ready lifecycle: **verified**
  - `ClusterPriorityScore` is deterministic/explainable and uses only latest local
    volume/difficulty, optional manual business relevance, mapping completeness and
    review completeness. Missing metrics remain null and add no invented priority.
  - Ready requires an active reviewed cluster, explicit mapping (page or Ignore),
    and no pending primary-keyword review. Real remaps, cluster mutations and
    new/reopened reviews invalidate stale Ready back to Planning.
  - Final boundary `f51f5d76aca3331153383726bbc76eebffab6846`;
    CI `39813493267`, success; 226 tests / 1229 assertions.
- Task 11 — Connected Keyword Intelligence workspace + acceptance: **verified**
  - The server-provided intelligence workspace is connected to the existing
    high-performance Keywords page with Grid, Intent, Clusters and Mapping entry
    points; the server-paginated Grid remains intact.
  - Manual cluster merge/move/split controls call only project-scoped,
    service-backed HTTP boundaries, respect cluster locks and require explicit
    primary replacement when needed.
  - Mapping/page targeting, conflict refresh/review, priority and Ready stay owned
    by existing transactional services; the browser never silently remaps.
  - Acceptance requires explicit `intelligence.project_id`; RED was observed on CI
    `39826284166` where only the new property assertion failed while 236 tests
    passed. The URL-inference bug was then removed by the minimal backend/type/UI
    contract.
  - Whole-branch review found and fixed the Review Queue `mapping_conflict`
    frontend contract/label mismatch and hardened manual-editor empty/stale
    replacement states.
  - Review focus rechecked authorization/project isolation, deterministic
    idempotency, locked/manual override preservation, revision-safe Undo/Ready
    invalidation and the no-silent-remap rule. No open PR review threads remain.
  - Post-review implementation boundary: `216a4d2448defdd2f481dcf0beb55dbeeb112f10`.
    Permanent CI `39828064083`: **success**. Full backend suite:
    **237 tests / 1317 assertions**. PHP 8.4/8.5 compatibility, frontend formatter,
    TypeScript, production build, Pint, PHPStan, runtime npm/Composer audits,
    runtime-only Composer install and clean release-tree smoke all passed.

## Slice status

Keyword Intelligence Tasks 1–11 are complete at the implementation boundary above.
PR #2 is the single integration PR into `main`. The remaining integration gate is
a fresh permanent CI on the final documentation-complete branch head; merge only
that exact green head. After integration, move to the first usable self-host
release gate in `docs/DELIVERY_PLAN.md`; do not start crawler/SERP/AI/vector work
before the operational release prerequisites are deliberately planned.

## Execution rules

Continue RED → correct RED → minimal GREEN → refactor → meaningful focused
verification → permanent final-head CI → semantic commit/push. Human overrides,
locks, original keyword/provenance data and later mapping decisions must never be
silently overwritten. CI evidence belongs to the exact head it verified; never
infer success from an older or cancelled run.