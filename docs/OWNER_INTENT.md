# SEO OS — Owner Intent and V1 Product Boundary

Effective: 2026-10-28

This document records the owner's current product intent. It is the highest-level authority for **what the project should optimize for next**. Live Git and exact-head CI remain authoritative for implementation status. When an older delivery plan, master spec, handoff note, or historical checkpoint conflicts with this document on product direction, **this document wins**.

## Why this project exists

The owner wants a practical self-hosted SEO workspace that helps them start and run real SEO work, with the strongest value concentrated in **keyword intelligence**.

The product is **not currently being built as a crawler-first SEO platform**. A crawler may be useful later, but crawler expansion is not a prerequisite for the owner's current goal.

The owner also deliberately cares about the self-host lifecycle because the project may evolve over time. Installation, backup, restore, update and rollback/recovery are therefore first-class requirements rather than optional polish.

## V1 north star

A successful V1 lets the owner do this reliably:

`Install -> create owner/workspace -> create project -> import real keyword data -> review intelligent intent/cluster suggestions -> make manual cluster decisions -> map clusters to existing/planned pages -> understand priority/readiness -> export a usable SEO plan -> take a full backup -> restore it on a clean installation -> update safely -> recover/rollback if an update fails -> continue working.`

If this path is reliable and genuinely useful for SEO decisions, V1 is successful even if broader SEO-platform features are absent.

## Must-have product areas

### 1. Keyword workflow and intelligence — primary product value

Prioritize the quality and usability of:

- Persian keyword import and preservation of original source data;
- normalization without destroying original text;
- intent classification and human review;
- keyword clustering and evidence for suggestions;
- manual merge/split/move/lock operations and safe undo where applicable;
- existing/planned page targets;
- page mapping and cannibalization/mapping-conflict warnings;
- explainable priority/readiness state;
- server-side handling of large datasets;
- Persian-first RTL-safe workflow;
- export that is useful as an actual SEO planning handoff.

The real quality bar is not the number of tests or features. The quality bar is whether the system helps the owner answer practical questions such as:

- Which keywords belong together?
- Which keywords need separate pages?
- What is the likely search intent?
- Which URL/page should own the cluster?
- Are there competing mappings or cannibalization risks?
- What should be worked on first?

Do not add "AI" complexity for its own sake. Improvements to intelligence should be driven by observed weaknesses in real keyword workflows and should preserve human control and explainability.

### 2. Self-host lifecycle — required reliability layer

Keep these reliable and maintainable:

- browser-driven fresh installation;
- first-owner/workspace bootstrap;
- queue/scheduler operational visibility;
- full backup with bounded, explicit durable scope;
- restore onto a clean compatible installation;
- preservation of destination-local installation/database identity where designed;
- safe update;
- interrupted-update recovery;
- rollback and fail-closed behavior;
- operational health after successful update;
- Persian operator-facing lifecycle UX.

Lifecycle engineering is valuable when it protects the owner's real SEO data and makes future versions easy to adopt. Do not expand lifecycle complexity without a concrete reliability or release need.

### 3. Core usability and safety

Continue to protect:

- project/workspace authorization boundaries;
- data integrity and original SEO input provenance;
- real MariaDB acceptance for risky lifecycle/data paths;
- accessibility basics and RTL/LTR correctness;
- bounded/server-side behavior for large datasets;
- no hidden destructive remapping or silent loss of manual decisions;
- release/runtime behavior that does not require Node.js or Composer after build.

## Frozen for now — do not continue without explicit owner direction

Existing code in these areas may remain and must not be deliberately broken, but **do not spend product-development time expanding them now**:

- crawler expansion beyond the already integrated baseline;
- crawl history/diff;
- crawler-derived technical issue engine;
- site architecture visualizations/views;
- JS-rendering crawler work;
- broad technical-audit feature expansion;
- performance/opportunity/recommendation subsystems not required by the keyword workflow;
- content/task/internal-linking subsystems from the old full-product roadmap;
- SERP provider integrations;
- external AI-provider integration;
- embeddings/vector infrastructure;
- CMS integrations;
- SaaS plans, entitlements, billing or commercialization;
- speculative platform abstractions and broad refactors that do not fix an observed V1 problem.

Release signing/authenticity, chunked upload and other previously deferred release features remain deferred unless a concrete V1 deployment blocker makes them necessary.

**Do not delete the existing crawler simply because it is frozen. Preserve compatibility and tests when touching shared code.**

## Work that is allowed without asking for a new product decision

An agent may continue autonomously when work is directly tied to one of these:

1. a reproducible bug or regression in the V1 north-star flow;
2. a security, authorization, data-integrity or recovery defect;
3. a real usability blocker in import -> intelligence -> mapping -> export;
4. a reliability defect in install/backup/restore/update/recovery;
5. a measured performance problem in the core keyword workflow;
6. a keyword-intelligence quality problem demonstrated by realistic data or an explicit acceptance case;
7. release/operational documentation needed to install, recover or use V1 correctly.

Everything else is deferred unless the owner explicitly reopens that scope.

## V1 definition of done

V1 is considered ready for the owner's current need when all of the following are true on a current release candidate:

1. A clean instance installs through the supported browser flow.
2. The owner can create a project and import a realistic Persian keyword CSV without harness-only shortcuts.
3. Background processing completes through the supported worker path.
4. Intent/review, clustering, manual decisions and page mapping are usable through the product UI.
5. The exported SEO plan preserves Persian text correctly and reflects the reviewed intelligence state.
6. A real backup can be downloaded.
7. A second clean installation can restore that backup without corrupting destination-local installation/database identity.
8. The restored installation remains usable with the restored encrypted application state.
9. A valid update succeeds and the application is healthy afterward.
10. Broken/interrupted update cases recover or roll back according to the documented fail-closed guarantees.
11. Permanent CI and connected acceptance for the changed boundary are green with no acceptance-critical skipped tests presented as passes.
12. No known blocker remains that prevents the owner from beginning real keyword-led SEO work.

## Current product-development order

Until the owner changes direction:

1. Keep the existing integrated lifecycle and keyword workflow green; fix real regressions first.
2. Evaluate the **actual usefulness of Keyword Intelligence** with realistic Persian keyword sets.
3. Improve only the weaknesses observed in intent, clustering, review, mapping, priority or core UX.
4. Keep install/backup/restore/update/recovery dependable as those changes ship.
5. Package/document a usable V1 and let the owner start real SEO work.
6. Reconsider frozen subsystems only after V1 is being used and the owner explicitly decides they are worth the cost.

## Agent guardrail

Before starting any new subsystem or feature, answer this question:

> Does this directly improve the owner's keyword-led SEO workflow or the reliability needed to preserve and update that work?

If the answer is no, do not start it. Record it as deferred instead.

Do not interpret an older roadmap as standing permission to continue feature expansion. Do not restart completed subsystems without new failing evidence. Do not trade away existing lifecycle/security/data-integrity guarantees to move faster.