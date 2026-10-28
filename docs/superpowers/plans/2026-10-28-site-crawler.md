# Site crawler — implementation plan

Spec: `docs/superpowers/specs/2026-10-28-site-crawler-design.md`. Each task is RED→GREEN, its own
commit, and verified by exact-head CI before the next one is considered done.

| # | Task | Status |
|---|------|--------|
| 1 | `CrawlTargetPolicy`: scheme/host/port scope + resolved-address classification (all blocked ranges, IPv6, IPv4-mapped), operator opt-in for private networks | in progress |
| 2 | `SafeHttpFetcher`: DNS-pinned curl transport, per-hop redirect re-validation, timeouts, body/content-type bounds, stable error codes; fake transport for tests | pending |
| 3 | `RobotsTxt` parser/matcher and `SitemapReader` (index, gzip, bounds, XXE refused) | pending |
| 4 | `HtmlExtractor` for §16.4 fields with bounds (Persian/RTL + malformed fixtures) | pending |
| 5 | Schema (`crawl_runs`, `crawl_urls`), models, project-scoped policies | pending |
| 6 | Run engine: `StartCrawlRun` service (one active run per project), `ProcessCrawlRun` batched job with guarded claims, checkpoints, retries, pause/resume/cancel, limits | pending |
| 7 | Page linking after completion (reuse page service; never overwrite user titles) | pending |
| 8 | Persian-first crawl UI with polling, paginated/filterable URL table, authorization tests | pending |
| 9 | Connected MariaDB claim-concurrency acceptance + docs/checkpoint update | pending |
