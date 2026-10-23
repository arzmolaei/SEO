# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-23

This file is the durable source of truth for continuing SEO OS across agent sessions. **Git + exact-head CI override stale chat or old hashes.** Never reset/force-push over unexpected branch movement or recreate a completed subsystem.

## Stable baseline

- Repository: `arzmolaei/SEO`.
- Stable branch: `main`.
- Foundation + Keyword Core: merged through PR #1.
- Keyword Intelligence: merged through PR #2.
- Self-host Installer: merged through PR #4 and post-merge CI verified.
- Current stable `main`: `c4f03303408a676d43a3fdbf94f1f49e00affbe5`.
- Active integration branch: `feature/persian-export`, PR #5.
- Runtime floor: PHP `>= 8.4.1`; permanent compatibility gates: PHP 8.4 and 8.5.

Always fetch current `main`, active branch head and current CI before changing code. Recorded hashes are evidence, not permission to assume Git has not moved.

## Completed integrated product slices

### Foundation + Keyword Core

Integrated. Includes Laravel 13 + Inertia/React/TypeScript, Persian-first RTL/LTR, auth/onboarding, Workspace/Membership/Project isolation, project shell, Persian keyword normalization preserving original source text, resumable import, canonical keyword/provenance/metric history, server-side Keyword Grid, Saved Views, transactional bulk changes/audit/safe Undo and allowlist-first runtime packaging.

Evidence: `docs/release-readiness/foundation-keyword-core.md`.

### Keyword Intelligence

Integrated. Includes deterministic intent history/classification, project-scoped Review Queue, relational/reversible clusters, manual merge/split/move/lock, existing/planned Pages, mapping history/conflict review, explainable local priority and connected Grid/Intent/Clusters/Mapping workspace. No crawler/SERP/vector/AI dependency was introduced.

Evidence: `docs/release-readiness/keyword-intelligence.md`.

### Self-host Installer

Integrated on `main` at merge commit `c4f03303408a676d43a3fdbf94f1f49e00affbe5`; post-merge permanent CI is green.

The installer provides DB-independent host preflight, browser-managed production `.env` and persistent APP_KEY, real MySQL/MariaDB verification, resumable in-process migrations, exactly-once first owner/workspace, offline-safe password validation, public registration disabled by default, scheduler heartbeat, real database queue probe, shared-host commands, lock-last finalization, authenticated dashboard handoff and installer lockdown. MariaDB 10.11 connected acceptance and runtime-only release `/install` HTTP smoke are permanent gates.

Evidence: `docs/release-readiness/self-host-installer.md`.

## Active slice — Persian Export

Design: `docs/superpowers/specs/2026-10-23-persian-export-design.md`  
Plan: `docs/superpowers/plans/2026-10-23-persian-export.md`  
Release evidence: `docs/release-readiness/persian-export.md`

Tasks 1–6 are complete and Task 7 is at the final review/documentation/merge gate.

Implemented boundary:

1. versioned `seo-plan/1` CSV with exact 74-column schema;
2. UTF-8 BOM, CRLF, deterministic scalar/CSV formatting and incremental SHA-256;
3. spreadsheet formula/control-prefix defense plus exact Persian/ZWNJ recovery through `exact_text_json`;
4. project-scoped current intent/latest metrics/cluster/mapping/review/priority semantics;
5. cluster priority reuses `ClusterPriorityScore::calculate()` with explicit primary-keyword basis;
6. strict export role boundary: Owner/Admin/SEO Manager only;
7. bounded 250-row keyword batches and private `php://temp` output, no durable/public artifact;
8. repeatable-read MySQL/MariaDB snapshot closed before HTTP streaming;
9. real Keywords workspace `خروجی CSV` action and stable Persian generation errors;
10. permanent MariaDB 10.11 gate including 10,000 keywords and a connected Persian domain flow.

Implementation evidence before final documentation:

- Task 6 permanent exact-head SHA: `1898ee48349948d8caa28004920c6e396ae96326`;
- permanent CI run `39911325085`: success across PHP 8.4, PHP 8.5, verify, Installer MariaDB and Persian Export MariaDB;
- review found a selected-metric N+1 query pattern;
- RED regression commit `5f262644f25d4155dc96c8b153086b31772ed6f0` proved four redundant keyword point queries for two keywords;
- verified performance fix commit `1e3aaf62a4297c12cedfbf5d5d435dd6f6b1ef09`;
- focused verification run `39911869862`: success for query-budget regression, Export suite, Pint and PHPStan/Larastan; temporary workflow removed itself.

PR #5 must receive fresh permanent CI success on the final documentation-complete SHA before merge. After merge, `main` must receive independent post-merge permanent CI success before Persian Export is called integrated.

## Self-host/offline invariants

- Core runtime/install must not depend on Composer, Node/npm, SSH, shell access, foreign APIs or international internet on the production host.
- Vazirmatn may be bundled locally under its open license. Proprietary IRANSansX/Yekan Bakh binaries are never committed publicly and require licensed local installation.
- Preserve original keyword text, provenance/metric history and explicit human intent/cluster/mapping decisions.
- Every project resource query/mutation/export remains project-scoped and authorized.
- Automation must not silently overwrite explicit locks/manual decisions.
- `.env`, secrets, private font binaries and local fixtures stay out of public Git/release artifacts.

## Exact next lifecycle work

After PR #5 is merged and post-merge CI is green, start **Backup + Restore** as the next separate subsystem. Its release boundary must include database + uploads + durable settings, versioned checksum manifest, clean restore and verification of users, authorization and SEO data.

Then continue:

1. **Update + interrupted recovery/rollback** — compatibility checks, mandatory pre-update backup, migrations, health validation, interrupted/failed update recovery and rollback on real fixtures.
2. **Final end-to-end lifecycle acceptance** — clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian export -> backup/restore -> update/recovery -> operational health.

Do not start crawler/Site Intelligence, Search Console, SERP, AI or vector work before this first usable self-host lifecycle gate is deliberately complete.

## Mandatory continuation protocol

1. Read `AGENTS.md`, this file, `docs/HANDOFF.md`, `docs/DELIVERY_PLAN.md` and active spec/plan.
2. Fetch current Git heads and exact-head CI before writes.
3. Use RED -> correct RED -> minimal GREEN -> verification -> semantic commit/push.
4. Diagnose failures before changing production code or tests.
5. Keep permanent CI gates intact; temporary diagnostics/workflows must not survive in the product.
6. Never force-push or infer success from stale/cancelled/pending runs.
7. Update durable state at verified subsystem boundaries.
8. Continue autonomously through approved plans without routine confirmation.
9. A genuinely new architecture still follows the mandatory brainstorming -> design approval -> written spec review -> implementation-plan review process.

## Definition of trustworthy progress

Code existence is not completion. Applicable frontend check/TypeScript/build, Pint, PHPStan/Larastan, backend tests, runtime dependency audits, project isolation, connected MariaDB acceptance and release/runtime smoke must be green on the current head. Subsystem integration additionally requires the merge commit on `main` to receive its own permanent CI success.
