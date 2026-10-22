# Mapping conflicts — bounded local review

Task 9 adds deterministic warnings, never automatic remapping. Active clusters
only; project authorization and project-first transaction serialization match
mapping services. Existing original keywords, memberships and locks are untouched.

## Conservative rules

- Competing pages: same known intent plus the same two meaningful prefix tokens,
  with distinct non-ignore target pages. Intent alone is not cannibalization.
- Overloaded page: clusters on one page with distinct two-token topic anchors.
  This is a lexical review warning, not proof of semantic/technical SEO damage.
- High-demand unmapped: primary keyword's latest recorded volume is at least the
  configured threshold (default 1000). Include source, observation and metric ID;
  missing metrics never imply demand. Latest observation then ID is explicit.
- Invalid target: type/page-kind/nullability no longer matches the mapping contract.

Group by page/topic+intent; do not enumerate all pairs. Read clusters in chunks
with primary/page projections and batch metrics. Retain O(n) compact grouping
state; O(n log n) deterministic ordering, no architectural keyword count limit.
Warnings summarize counts/first ten IDs plus a digest of all relevant group
facts. Full unrelated member collections never go to the browser.

## Queue and decisions

One mapping-conflict review per current cluster primary, combining its warnings.
Fingerprint includes algorithm version and sorted evidence. Same evidence keeps
resolved decisions closed; changed evidence reopens; absent warnings supersede
pending items with audit. Primary changes supersede the old anchored review.
Accept means acknowledge the warning, never mutate mapping. Skip is explicit.
Resolution rechecks current evidence under the project lock and rejects stale
requests. Both resolutions and supersession are attributed/audited. The existing
paginated review UI labels these as mapping warnings; intent editing is absent.
