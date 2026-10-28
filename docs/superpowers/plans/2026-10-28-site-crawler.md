# Site crawler — implementation plan

Spec: `docs/superpowers/specs/2026-10-28-site-crawler-design.md`. Each task is RED→GREEN, its own
commit, and verified by exact-head CI before the next one is considered done.

| # | Task | Status |
|---|------|--------|
| 1 | `CrawlTargetPolicy`: scheme/host/port scope + resolved-address classification (all blocked ranges, IPv6, IPv4-mapped), operator opt-in for private networks | implemented |
| 2 | `SafeHttpFetcher`: DNS-pinned curl transport, per-hop redirect re-validation, timeouts, body/content-type bounds, stable error codes; real-socket fixture tests | implemented |
| 3 | `RobotsTxt` parser/matcher and `SitemapReader` (index, gzip, bounds, XXE refused) | implemented |
| 4 | `HtmlExtractor` for §16.4 fields with bounds (Persian/RTL + malformed fixtures) | implemented |
| 5 | Schema (`crawl_runs`, `crawl_urls`), models, project-scoped policies, durable backup catalog | implemented |
| 6 | Run engine: `StartCrawlRun` service (one active run per project), `ProcessCrawlRun` batched job with guarded claims, checkpoints, retries, pause/resume/cancel, limits | pending |
| 7 | Page linking after completion (reuse page service; never overwrite user titles) | pending |
| 8 | Persian-first crawl UI with polling, paginated/filterable URL table, authorization tests | pending |
| 9 | Connected MariaDB claim-concurrency acceptance + docs/checkpoint update | pending |

## Resumed verification — 2026-10-28

At inherited head `56d96ef`, CI `40673704613` failed on HTML fixture formatting,
two run-engine regressions, and an incomplete connected backup fixture. Both
engine failures and the MariaDB backup failure were reproduced locally before
correction. Network refusals now retain their stable code before robots rules;
claimed pending rows schedule a delayed retry until reclaimable. The backup
round-trip now seeds both crawl tables and compares their restored raw records.
DOM node narrowing and the optional sitemap checkpoint shape fix strict static
analysis without suppressions.

Local: crawler suite 85 passed; ordinary backend 720 passed / 16 connected-only
skips (5,053 assertions); connected backup/migration/isolation 11 passed with
`--fail-on-skipped` (206 assertions); PHPStan, Pint and frontend check passed.
Task 6 remains in progress pending exact-head CI and concurrency/batch review.
Tasks 7–9 remain pending. The sandbox needs local TCP permission for socket tests;
only the loopback-pinned `site.test` fixture was excluded from proxy routing.

Engine hardening adds observed RED→GREEN tests for one worker per run, checkpointed
sitemap discovery and a shared redirect-chain network budget. Inherited forward
proxies are refused unless the operator excludes the destination explicitly.
Local verification after merging current main: crawler 89 passed; ordinary suite
735 passed / 16 connected-only skips (5,111 assertions); PHPStan/Pint/frontend
check passed. Page linking, HTTP/UI and connected worker-concurrency acceptance
are still unimplemented; this is not a completed crawler claim.
