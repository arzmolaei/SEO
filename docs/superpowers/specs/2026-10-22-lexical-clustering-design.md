# Local lexical clustering — Task 5

Approved by delegation. Resume the active Keyword Intelligence plan, Tasks 1–4
already verified. No SERP, AI, embeddings, crawler or mapping work in this task.

Generator consumes iterable canonical snapshots (id/project/text/accepted intent).
Normalize without changing stored input. Remove configured stop words and action
modifiers. Block by the first two meaningful tokens (order-independent within
that prefix); fewer than two meaningful tokens produce no proposal. This avoids
single generic-token grouping and full pairwise comparisons. It is intentionally
conservative: it does not claim synonym or semantic clustering.

Each non-singleton bucket produces a stable candidate, sorted members, shortest
meaningful phrase as primary (ID tie-break), input snapshots, versioned evidence
and fingerprint. Score: shared two-token anchor 60 + identical meaningful token
sets 25 + one compatible known intent 15. Only score 100 is High; remaining
supported candidates are Medium. Conflicting/unknown/mixed intent is never High.
These weights express evidence strength, not a probability of semantic truth.
Expected complexity O(total tokens + n log n), O(total input) storage; no hard
keyword count ceiling. A 10k synthetic corpus guards gross quadratic regression.

Apply revalidates candidate evidence from current normalized inputs and current
intent assignments, under sorted keyword locks. It locks affected clusters,
members and review rows in ascending identity order. All candidates in one call
commit atomically, including audit. Any foreign ID or stale evidence aborts the
call. Existing memberships are preserved wholesale, including manual moves and
locked clusters. An automation key plus origin identify generated clusters;
repeat input reuses the same result without duplicate audit/reviews. Human
operations invalidate automation reuse and are never silently undone.

High confidence auto-application defaults OFF. Uncertain proposals go to the
existing ReviewItem mechanism, keyed by project/type/primary keyword. Identical
resolved evidence stays closed; changed evidence reopens with attributed audit.
Explicit accept creates a user-owned cluster; skip records no membership.
Intent resolution must reject cluster review items. Queue presentation may show
simple cluster proposal actions; no separate dashboard or merge/split UI here.

Task 6 follows only after final-head Task 5 CI is green. Work continues without
human confirmation. Durable state/checkpoints and semantic commits are updated
at verified boundaries; install/backup/restore/update remain separate release
acceptance requirements, never implied by completion of Keyword Intelligence.
