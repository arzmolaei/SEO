# Crawl history and change sets — implementation plan

Spec: `docs/superpowers/specs/2026-10-28-crawl-history-diff-design.md`.

| # | Task | Status |
|---|------|--------|
| 1 | Schema: run comparison state + durable `crawl_changes` (backup catalog, acceptance round-trip) | implemented |
| 2 | `CrawlRunComparator`: comparable-baseline rules, field diffs, removed URLs only from complete uncapped runs, resumable keyset batches | implemented |
| 3 | Processor: a run completes only after its comparison | implemented |
| 4 | Crawl page: comparison status, per-type counts as filters, server-paginated before/after list, project isolation | implemented |
| 5 | Exact-head CI, merge, post-merge verification, checkpoint docs | pending |
