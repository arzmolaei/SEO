# Backup + Restore Written-Spec Review

Date: 2026-10-23
Design: `docs/superpowers/specs/2026-10-23-backup-restore-design.md`
Branch: `feature/backup-restore`
Reviewed product tree: `79494174fc0a1d6be23fb7595f3fffa5c3187596`
Current `main` at review: `fabf2bf02d18c4e14f4aa97b6ab819da8620b174`
Result: **Approved for implementation planning with the corrections and rulings below.**

## Evidence reviewed

- `AGENTS.md`, `docs/PROJECT_STATE.md`, `docs/DELIVERY_PLAN.md`, `docs/HANDOFF.md`.
- Current `main`, current feature branch and current GitHub Actions history.
- Backup/Restore design through its release boundary.
- Installer lifecycle code (`InstallationState`, `FinalizeInstallation`, `config/installer.php`).
- Release package builder/contract and CI release smoke-test.
- All current application migrations needed to establish the durable-data dependency graph.

## Repository-state ruling

`PROJECT_STATE.md` is stale about Persian Export: PR #5 is already merged and current `main` CI is green. `main@a6924d77...` and current `main@fabf2bf...` point at the same product tree (`79494174...`); the intervening main commits only isolated/removed Backup/Restore design documentation. Therefore the Backup/Restore feature branch is not missing product-code changes even though the design header names the earlier merge commit.

## Required design correction

Section 8 currently lists durable table #6 as `keyword_import_batches`. The real migration creates **`import_batches`**. Implementation, manifest validation, tests and acceptance must use `import_batches`; `keyword_import_batches` is invalid and must never enter the runtime catalog.

This review addendum is authoritative for that typo until the design document itself is mechanically corrected.

## Verified durable catalog

The current durable catalog is exactly:

1. `users`
2. `passkeys`
3. `workspaces`
4. `memberships`
5. `projects`
6. `import_batches`
7. `staged_keyword_rows`
8. `keywords`
9. `keyword_sources`
10. `keyword_metrics`
11. `saved_keyword_views`
12. `change_sets`
13. `activity_events`
14. `keyword_intent_assignments`
15. `review_items`
16. `keyword_clusters`
17. `keyword_cluster_members`
18. `pages`
19. `cluster_targets`

Framework/volatile exclusions remain those in the design.

## Verified restore dependency order

Parent-first inserts:

1. `users`
2. `passkeys`
3. `workspaces`
4. `memberships`
5. `projects`
6. `import_batches`
7. `staged_keyword_rows`
8. `keywords`
9. `keyword_sources`
10. `keyword_metrics`
11. `saved_keyword_views`
12. `change_sets`
13. `activity_events`
14. `keyword_intent_assignments`
15. `review_items`
16. `keyword_clusters`
17. `keyword_cluster_members`
18. `pages`
19. `cluster_targets`

Child-first delete order is the exact reverse. The implementation must not disable foreign-key enforcement as a shortcut.

Key non-obvious dependencies verified from migrations:

- `keyword_sources` depends on `keywords`, `import_batches` and `staged_keyword_rows`.
- `keyword_metrics` depends on both `keywords` and `keyword_sources`.
- `activity_events` depends on `change_sets` in addition to workspace/project/user references.
- `keyword_clusters` depends on its primary `keywords` row and optional creator user.
- `keyword_cluster_members` depends on both `keyword_clusters` and `keywords` through project-qualified foreign keys.
- `cluster_targets` depends on `keyword_clusters`, optional `pages`, project and optional actor user.

## Security/architecture rulings

- V1 backup bundles remain intentionally **unencrypted by a user passphrase** as the design states. The file is treated as sensitive because it contains password hashes, APP_KEY and durable authentication data. Do not silently add a home-grown encryption layer in V1.
- Restore remains clean-target only and same-product-version/same-schema only. No merge restore and no cross-version adapter belongs in this feature.
- Database credentials and unrelated environment secrets are never backed up or overwritten.
- Destination `installation_id` and database fingerprint remain local identity after restore.
- `LifecycleMutex` is the cross-feature serialization primitive; maintenance mode is an additional quiescence/UX guard, not a replacement for the mutex.
- Restore verification must finish before mutation, and interruption after destructive progress must fail closed into durable `recovery_required` state.
- Foreign keys stay enabled during normal restore; ordering is the correctness mechanism.

## Planning gate

No unresolved architecture question requires product-owner input before implementation. Routine implementation choices are delegated by `AGENTS.md`, and the current user instruction explicitly asks the agent to continue the repository-defined workflow. The next gate is the detailed implementation plan, followed by RED -> GREEN execution on the isolated feature branch.