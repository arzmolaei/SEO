# Intent Review — bounded Keyword Intelligence increment

Status: Approved-by-delegation; product and implementation decisions delegated by the user.

## Baseline and scope

Foundation was merged through PR #1. Existing Keyword Intelligence at `b8a6456`
provides assignments/history and a deterministic Persian classifier; CI run
39786352782 passed. Continue that branch. Do not rebuild imports or the grid.

Deliver the next connected increment: canonical keyword → local intent suggestion
→ paginated review → accept/change/skip → attributed assignment/history.
Clustering, pages, mapping, priority and crawler remain subsequent tasks in the
parent plan. This increment must pass its gates before adding those features.

## Decisions

Reuse the classifier and assignment service. Keep suggestions in `review_items`,
separate from accepted intent and keyword lifecycle status. Use one row per
project/type/keyword, with a fingerprint of classifier input and result. Default
is human review even for high confidence. Explicit configuration may auto-apply
known high-confidence suggestions. Medium/low, mixed and unknown always require
review. Human overrides always win.

Refresh runs as a database-queue job, traversing keywords by ID in chunks. Each
keyword is processed transactionally under its own row lock; retries upsert the
same review item. No entire project is sent to the browser or loaded into memory.
Project authorization is rechecked in the worker and every application service.
Read-only viewers may view the queue, but cannot refresh or resolve it.

Accept persists the suggested intent as a user decision; change persists the
chosen supported intent. Skip records a decision without inventing an intent.
All resolutions record actor, time, previous/new state and evidence in existing
audit primitives. Resolved evidence is not reopened on identical refresh. A
skipped item reopens only when its classifier input/result changes. Explicit user
intent remains authoritative even if later classifier evidence changes.

A submitted evidence fingerprint must still match the pending item. Otherwise
return 409 and require refresh; never apply stale decisions. Resolution and
assignment are one transaction. Lock keyword before review row everywhere.

## UX and verification

Dedicated Persian-first Review Queue in project navigation, server pagination,
explanation and confidence, inline intent selection, accept/change/skip, keyboard
navigation, pending/error/empty/retry states. No modal or external API dependency.
Refresh submission reports queueing honestly; results are reloaded explicitly.

RED/GREEN tests cover refresh idempotency, all confidence classes, opt-in
high-confidence application, human overrides, changed evidence, stale decisions,
audit attribution, worker retries, guessed IDs and viewer permissions. Run the
full existing test/static/style/frontend/release gates. Local PHP uses an isolated
Docker runtime because the workspace has no PHP binary.

## Runtime discrepancy

The handoff says PHP 8.3+, but existing Laravel 13 dependencies require PHP
^8.4.1. Preserve the verified dependency baseline; compatibility with PHP 8.3 is
not established and must not be advertised as verified.
