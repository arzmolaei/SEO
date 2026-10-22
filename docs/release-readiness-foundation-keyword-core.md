# Foundation + Keyword Core — Release Readiness

Date: 2026-10-22
Branch: `feature/foundation-keyword-core`
Verification baseline: `bc8ae9bfa4820b232ef28b79a5a9fac428ab619c`

## Scope verified

The first production vertical slice is implemented and exercised end to end:

- Laravel 13 / Inertia / React / TypeScript application foundation.
- Persian-first locale contract with RTL/LTR support and English-ready dictionaries.
- Local authentication, workspace membership roles, project ownership, and project authorization boundaries.
- Project workspace shell and keyword workspace.
- Persian-safe keyword normalization while preserving original source text.
- Staged keyword imports with mapping validation, row diagnostics, checkpoints, and resumable streaming CSV parsing.
- Canonical project-scoped keyword identity with independent source provenance and time/source-aware metrics.
- Server-side keyword search, filtering, sorting, pagination, status filters, numeric filters, column visibility, keyboard navigation, loading/error states, and saved views.
- Auditable bulk keyword status changes with ChangeSet/ActivityEvent attribution and safe undo conflict checks.
- Installer/System Health foundations and release-package contract checks.

## Acceptance evidence

`tests/Feature/Acceptance/FoundationKeywordCoreAcceptanceTest.php` exercises the real first-slice workflow using a UTF-8 CSV rather than mocked row arrays. It verifies:

1. User → workspace → project creation.
2. Streaming CSV staging with Persian/Arabic Yeh variants.
3. Canonical normalization/deduplication without losing source provenance.
4. Metric/source persistence.
5. Keyword Grid rendering through the HTTP/Inertia boundary.
6. Saved View creation and application.
7. Bulk status update with ChangeSet and ActivityEvent records.
8. Safe undo with audit attribution.

On the verification baseline, the permanent CI pipeline completed successfully for the main verify job and the PHP 8.4 and PHP 8.5 compatibility jobs. The verify job includes Composer metadata validation, frontend format/lint, TypeScript checks, production asset build, PHP style, static analysis, backend tests, npm runtime audit, and Composer runtime audit.

## Self-host and asset contract

Production does not require Node.js after assets are built and core runtime behavior does not require an external CDN or AI service.

Vazirmatn is OFL-licensed and is fetched from its pinned upstream release during the controlled build/release process, then served locally at runtime. The release package contract requires the bundled local Vazirmatn asset.

IRANSansX FaNum is the product-default font preference and Yekan Bakh FaNum is also selectable. Their binaries are proprietary assets and are intentionally excluded from the public repository. Licensed deployments install them into `public/fonts/private` using the private-font installer flow. If those licensed assets are not provisioned, the CSS stack falls back to the locally bundled Vazirmatn/Tahoma/system fonts without a runtime CDN request.

The production package contract rejects development or secret material such as `.env`, `auth.json`, `node_modules`, tests, frontend source/build tooling, CI metadata, and private key files. It requires the application bootstrap, Composer runtime, built asset manifest, bundled Vazirmatn, application view, and `.env.example`.

## Release notes / known boundaries

- The current keyword slice is deliberately server-paginated; the browser never receives the complete keyword collection.
- Import source text is immutable provenance. Canonical normalization is stored separately and must not replace original imported text.
- Competing source metrics remain independent records; later enrichment must append or version measurements rather than overwrite another source's value.
- Workspace/project boundaries remain authorization boundaries for queries, views, bulk updates, imports, and future derived intelligence.
- Proprietary font binaries must remain outside the public Git repository. A private deployment/release may provision licensed copies separately.
- AI and international APIs remain optional enhancements, not prerequisites for the core workflow.

## Next subsystem boundary — Intent, Clustering, Mapping

The next implementation slice should begin with rule-based intent and clustering, then add mapping. The approved product design defines clustering as:

1. Rule/lexical/feature-based clustering.
2. Optional SERP-overlap verification.
3. Optional AI assistance.

The implementation boundary should preserve the current Keyword module contract:

- Consume project-scoped canonical keyword IDs and their versioned/source-aware metrics.
- Store intent, cluster membership, confidence, and review state as derived intelligence rather than mutating import provenance.
- Make manual overrides first-class and attributable.
- Support split, merge, move-keyword, recalculate, and lock operations without changing canonical keyword identity.
- Keep uncertain decisions reviewable in a queue rather than silently auto-applying them.
- Make SERP verification and AI enrichment optional adapters so rule-based clustering remains fully usable offline/self-hosted.

Mapping should then connect a reviewed cluster to one explicit target type: existing page, planned page, merge target, redirect target, or ignore/no-target. It should surface conflicts such as multiple pages for one intent, unrelated clusters overloaded onto one page, or high-value clusters without targets.

This boundary keeps the completed Keyword Core stable while allowing Clustering/Mapping to evolve as a separate domain layer.
