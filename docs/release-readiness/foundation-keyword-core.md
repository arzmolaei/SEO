# Foundation + Keyword Core — Release Readiness

Date: 2026-10-22

## Status

The first SEO OS vertical slice is complete on `feature/foundation-keyword-core` and has completed whole-branch review. It is a production-quality foundation slice, not the final SEO OS product release.

Final reviewed implementation baseline before this documentation update:

- Commit: `05738fd3bafe0c7dacbdb05f1917f89ac9cd6a09`
- CI run: `39771206949`
- Main verification job: passed
- PHP 8.4 compatibility: passed
- PHP 8.5 compatibility: passed

## Verified user workflow

The acceptance suite exercises the connected workflow rather than isolated features only:

1. Create a user-owned workspace and project.
2. Read a real UTF-8 CSV through the streaming reader.
3. Stage rows with checkpointed import processing.
4. Normalize Persian keyword identity while preserving original source text.
5. Commit canonical project-scoped keywords with independent provenance and metric history.
6. Browse the server-paginated Keyword Grid.
7. Save and apply a validated project-scoped Keyword View.
8. Bulk-change keyword status with attributed ChangeSet/Activity records.
9. Safely undo the supported bulk change.

## Quality gates

The permanent CI pipeline verifies:

- Composer metadata validation.
- Frontend formatter/linter checks.
- TypeScript checks.
- Production asset build.
- Laravel Pint PHP style.
- PHPStan/Larastan static analysis.
- Full backend test suite.
- PHP compatibility suites on PHP 8.4 and PHP 8.5.
- Runtime npm dependency audit with high-severity gating.
- Runtime Composer dependency audit.
- Production release-package contract.

The reviewed baseline passed all of these gates, including the end-to-end acceptance workflow.

## Whole-branch review

The completed branch was compared against `main` and reviewed with extra attention to authorization boundaries, project-scoped queries, transactional bulk changes, safe undo behavior, installer/release safety, and secret leakage.

One important release-package hardening issue was found and fixed during review: private key files nested below `storage/` could previously evade a top-level-only key check. The release contract now recursively scans `storage` and rejects `.key` files at any depth, reported as `storage/**/*.key`. A dedicated RED/GREEN regression test verifies nested private-key rejection.

No additional Critical or Important findings remained after the focused review and the final reviewed CI baseline above.

## Self-hosting baseline

The slice keeps the intended self-host characteristics:

- Laravel runtime with prebuilt frontend assets; Node.js is not required on the target production host after packaging.
- Database queue and local storage remain the default self-host choices.
- Core application assets do not require an international CDN at runtime.
- Vazirmatn is bundled as an OFL-licensed local runtime font.
- IRANSansX FaNum is the default interface font and Yekan Bakh is selectable, but their proprietary binaries are intentionally excluded from the public repository. Licensed ZIPs can be installed locally with `scripts/fonts/install-private-fonts.php`; the private font directory is ignored by Git.
- Installer/System Health foundations check runtime requirements and application state without making shell access a core product requirement.

## Data and safety guarantees in this slice

- Workspace/project authorization is enforced at HTTP and domain-service boundaries where relevant.
- Guessed cross-project identifiers are rejected.
- Persian normalization never replaces stored original source text.
- Canonical keyword identity is project-scoped.
- Source metrics are time/source aware and do not overwrite competing source records.
- Large CSV parsing is streaming and supports UTF-8 BOM plus UTF-16LE/BE.
- Interrupted staging can resume from checkpoints without duplicate staged rows.
- Import commit is idempotent.
- Saved View state is versioned, validated, user/project scoped, and rejects unknown fields/operators.
- Bulk changes are transactional, attributable, audited, and reversible where safe.
- Undo refuses to overwrite a keyword that changed after the original ChangeSet.
- Release packages reject development/secret artifacts such as `.env`, `node_modules`, test sources and private key files anywhere below `storage/`.

## UI foundation completed

- Persian-first `fa` locale with `en` fallback.
- RTL/LTR direction is a shared typed application contract.
- Project workspace shell and Command Center skeleton.
- Guided/Expert mode state foundation.
- Keyword Grid with server-side search/filter/sort/pagination.
- Status/source/volume filtering and safe sort allowlists.
- Column visibility, keyboard row navigation, loading/error/retry states.
- Saved View controls.
- Appearance font selection with IRANSansX default, Yekan Bakh and Vazirmatn options.

## Deliberately outside this slice

This branch does not claim to complete the full SEO OS product. The following major product systems remain subsequent work:

- Keyword intent classification and review workflow.
- Clustering, cluster merge/split and confidence handling.
- Keyword-to-page mapping and cannibalization workflow.
- Site crawler and technical SEO snapshots/issues.
- Content, internal linking, Search Console/performance and Opportunity Engine.
- Full backup/restore/update/migration delivery flows beyond the foundations already designed.
- SaaS billing/entitlements and commercialization flows.

## Next subsystem boundary

The next implementation slice should be **Keyword Intelligence: Intent + Clustering + Mapping**.

It should build on the canonical keyword/provenance/grid/audit foundation completed here, rather than expanding the import subsystem further. The intended sequence is:

`Review Queue -> Intent -> Cluster -> Primary/Secondary -> Existing/Planned Page -> Mapping Conflicts -> Priority`

Crawler/Site Intelligence should follow after that keyword-to-page relationship is stable, so crawl data can attach to meaningful SEO entities instead of becoming an isolated audit report.

## Integration rule

Do not merge this feature branch solely because individual tests are green. Integration should happen through the reviewed branch workflow after a fresh CI run on the final branch head. The acceptance and CI evidence should remain required for any later integration.
