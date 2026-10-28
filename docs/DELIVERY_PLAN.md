# SEO OS delivery gates

## Current execution boundary — 2026-10-28

The owner's current direction is defined in `docs/OWNER_INTENT.md` and supersedes older roadmap progression when there is a conflict.

The active product boundary is now **V1 keyword-led SEO workflow + dependable self-host lifecycle**.

Do not continue the historical sequence `crawler -> crawl history/diff -> issues -> architecture -> performance` automatically. The already integrated crawler baseline is preserved, but further crawler/platform expansion is frozen unless the owner explicitly reopens it.

## What V1 is trying to achieve

The release should reliably support this real user path:

`clean install -> owner/workspace -> project -> real keyword import -> background processing -> intent/review -> clustering/manual decisions -> page mapping -> priority/readiness -> Persian SEO export -> full backup -> clean second install -> restore -> safe update -> rollback/recovery if needed -> healthy continued use`

The product succeeds when this path is dependable and the Keyword Intelligence output is genuinely useful for SEO decisions. Feature count is not the goal.

## Integrated baseline

These slices are already integrated and must not be rebuilt from scratch without new failing evidence:

- Foundation + Keyword Core — PR #1.
- Keyword Intelligence — PR #2.
- Self-host Installer — PR #4.
- Persian Export — PR #5.
- Backup + Restore — PR #7.
- Update + Interrupted Recovery/Rollback — PR #8 plus later connected/lifecycle hardening.
- Real browser lifecycle acceptance — integrated through PR #23/#24 and subsequent fixes/polling work.
- Persian framework/auth strings and bilingual launcher UX — integrated through PR #27/#29.
- Workspace/project creation and real keyword import flow — integrated through PR #22 and lifecycle follow-up.
- Site Intelligence crawler baseline — integrated through PR #28. It remains in the product but is **not the active development direction**.

Live Git and exact-head CI remain authoritative for exact implementation status.

## Active work order

### Gate 1 — protect the existing north-star flow

Keep current `main` green. Fix only reproducible regressions, security/authorization defects, data-integrity defects or lifecycle failures that block the V1 path.

Do not reopen already verified subsystems merely to re-audit them without evidence.

### Gate 2 — Keyword Intelligence usefulness

This is the primary product-value gate.

Evaluate realistic Persian keyword sets through the real UI and judge whether the product helps the owner make practical SEO decisions:

- Are intent suggestions useful and explainable?
- Are cluster suggestions coherent?
- Can wrong clusters be corrected quickly without losing manual decisions?
- Does the system make page-separation vs page-consolidation decisions easier?
- Are page mappings and mapping conflicts understandable?
- Does the workflow reveal likely cannibalization/competing targets without silently remapping?
- Are priority/readiness signals useful rather than decorative?
- Does the final export reflect the reviewed state accurately?

Only improve intelligence when a weakness is observed in realistic data or a concrete acceptance case. Preserve human override, provenance, reviewability and deterministic/offline operation unless the owner explicitly chooses a new dependency.

### Gate 3 — lifecycle reliability while V1 evolves

For any changed release candidate, preserve and verify where relevant:

- browser fresh install;
- owner/workspace bootstrap;
- queue/scheduler operation;
- real backup download;
- restore to a clean compatible second installation;
- source APP_KEY/restored encrypted-state usability;
- destination-local database/installation identity guarantees;
- successful update and post-update health;
- interrupted/broken update recovery or rollback;
- fail-closed behavior for unsafe state.

Lifecycle work should protect real SEO data and make future versions safe to adopt. Do not add lifecycle complexity without a concrete reliability need.

### Gate 4 — usable V1 release

When Gates 1–3 are green and no known blocker prevents real keyword-led SEO work:

- produce/verify the supported runtime package;
- keep installation/recovery instructions current;
- document known limitations honestly;
- tag/release the usable V1 boundary;
- start using the product for real SEO work.

Do not postpone this release merely to implement frozen roadmap features.

## Frozen roadmap — not active work

The following are explicitly deferred and must not be started automatically:

- crawl history/diff;
- crawler-derived technical issue engine;
- architecture views/visualizations;
- JS-rendering crawler work;
- broad technical-audit expansion;
- performance/opportunity/recommendation subsystems unrelated to the keyword workflow;
- content/task/internal-linking systems from the old full-product roadmap;
- SERP providers;
- external AI providers;
- embeddings/vector infrastructure;
- CMS integrations;
- SaaS plans, billing, entitlements and commercialization;
- speculative platform abstractions/refactors.

Existing crawler code and tests are preserved. Shared changes must not regress it, but crawler feature development is not a V1 requirement.

Release signing/authenticity and chunked upload remain deferred unless a concrete V1 deployment blocker makes them necessary. SHA-256 claims integrity only, not publisher authenticity.

## Autonomous continuation rule

Agents may continue without routine confirmation only when the next task directly improves:

1. Keyword Intelligence usefulness or core keyword workflow UX;
2. install/backup/restore/update/recovery reliability;
3. security, authorization, data integrity or performance of those paths;
4. release/operational documentation needed to use V1 safely.

If a proposed task does not fit one of those categories, stop and record it as deferred instead of implementing it.

## V1 acceptance checklist

A release candidate is ready for the owner's current need only when:

- clean browser installation works;
- a real project can be created;
- realistic Persian keywords can be imported through the supported UI/worker path;
- intent/review, clustering/manual decisions and page mapping work through the product UI;
- the Persian export is correct and useful;
- a real backup can be created;
- a second clean instance can restore it while preserving the documented destination-local identity guarantees;
- the restored installation remains usable;
- a valid update succeeds and leaves the application healthy;
- broken/interrupted updates recover or roll back according to the documented guarantees;
- permanent CI and relevant connected acceptance are green;
- there is no known blocker preventing the owner from starting real keyword-led SEO work.

After that boundary, use the product first. Reopen frozen subsystems only when actual use demonstrates that they are worth the scope and maintenance cost.
