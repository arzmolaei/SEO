# Site Intelligence — crawl history and change sets (Phase C, slice 2)

Status: approved by delegation (AGENTS.md autonomous contract). Master spec §17, builds on
`2026-10-28-site-crawler-design.md`. Technical-issue diffs arrive with the issue engine (§18).

## 1. Goal

Every completed crawl produces a durable, attributable change set against the previous
comparable completed crawl of the same project, so an operator can see what changed on the site:
new URLs, removed URLs, HTTP status changes, title/meta description changes, canonical changes,
indexability changes and internal-link count changes.

## 2. Comparability (fail closed, never guess)

A run is compared only with the latest earlier run of the same project that is `completed`,
has the same mode, the same normalized start URL, the same allowed hosts, include/exclude
prefixes and the same depth and URL limits (they change what a crawl can see). Otherwise no change set is produced and the run records why (`no_baseline`,
`scope_changed`). Cancelled or failed runs are never a baseline and never produce "removed" URLs,
because a URL missing from a partial crawl proves nothing. A `max_urls`-capped run marks its
"removed" list as non-authoritative (`truncated`) instead of asserting removals.

## 3. Data

`crawl_changes`: crawl_run_id, baseline_run_id, url_hash, normalized_url, change_type
(`added|removed|status|title|meta_description|canonical|indexability|internal_links`), before
(json, bounded), after (json, bounded), created_at. Unique (crawl_run_id, url_hash, change_type);
keyset indexes by (crawl_run_id, change_type, id). `crawl_runs` gains `comparison_status`,
`baseline_run_id` and per-type counts (json). Durable, so it joins the backup catalog.

Only fetched rows are compared field by field. Values are compared after the same normalization
the extractor used; whitespace-only differences are not changes.

## 4. Processing

Computed by the run job after page linking, in bounded keyset batches over `url_hash` (join of
the two runs' `crawl_urls`), checkpointed on the run so a killed worker resumes. The run becomes
`completed` only after the comparison finishes, keeping polling truthful.

## 5. UI

Crawl page: per-run summary of change counts and comparison status; a server-side paginated,
filterable (change type) change list showing before/after (LTR for URLs, `dir="auto"` for text);
run history shows each run's counts. Persian-first, accessible, polled while running.

## 6. Tests

Comparison rules (no baseline, scope change, partial/cancelled baseline, truncated run), each
change type including whitespace-only non-changes and Persian text, resumable batches, project
isolation and authorization, backup round-trip of the new table, and connected MariaDB batching.
