# Site Intelligence — HTTP crawler (Phase C, slice 1)

Status: approved by delegation (AGENTS.md autonomous contract). Master spec §15–§17, §33, §40, §43.
This slice delivers an HTTP-first, offline-capable crawler for a project's own site. JS rendering,
crawl diff/history UI, issue engine and architecture views are later slices and are not claimed here.

## 1. Goals

- An owner/SEO manager starts a crawl of the project's site from the browser; a database-queue
  worker performs it; the UI reflects real server progress through Inertia polling.
- Crawl modes in this slice: entire site from a start URL (internal links + sitemap discovery),
  sitemap only, URL list. Include/exclude path prefixes, maximum depth and maximum URL count.
- Extraction per fetched URL (spec §16.4): HTTP status, redirect chain, content type, response
  time, byte size, title, meta description, meta robots + X-Robots-Tag, canonical, H1/H2 (bounded),
  hreflang, structured-data types summary (JSON-LD `@type` only), word count, image count and
  images missing alt, internal/external link counts, URL depth.
- Every crawl is an immutable run: one snapshot row per URL per run. Current page state is derived
  from the latest completed run; history is retained (spec §15, §17). Pages are linked by the
  existing project-scoped normalized URL identity; crawling never overwrites user page titles.
- Batching, checkpoints, pause/resume/cancel, retries with backoff, per-run politeness delay and a
  hard per-run concurrency of one request in flight (spec §16.6: graceful, resource-bound).

## 2. Security model (fail closed)

The crawler runs on the operator's server, so every fetch is an SSRF risk.

1. **Scope.** A run has an allowlist of hosts: the start URL host, plus its `www.`/apex twin only
   when the operator ticks "include www variant". Links, redirects, sitemaps and robots to any other
   host are recorded as external and never fetched.
2. **Schemes/ports.** `http`/`https` only, default ports or an explicit port on the allowlisted
   start URL. Credentials in URLs are rejected (reuse `UrlNormalizer` rules).
3. **Network targets.** Before each connection the host is resolved locally; the request is pinned
   to the resolved address (`CURLOPT_RESOLVE`) so DNS rebinding cannot change it between check
   and connect. Loopback, private (RFC 1918/ULA), link-local (incl. 169.254.169.254), CGNAT,
   multicast, reserved, unspecified and IPv4-mapped forms of those are refused unless the operator
   sets `CRAWLER_ALLOW_PRIVATE_NETWORKS=true` (intranet sites). Refusals are recorded per URL with a
   stable code, never a raw exception.
4. **Bounds.** Connect timeout 5 s, total 20 s, max 5 redirects (each hop re-validated), max body
   2 MiB (download aborted beyond), only `text/html`/`application/xhtml+xml` bodies are parsed;
   sitemaps (XML, optionally gzip, max 10 MiB inflated, max 50,000 URLs per file, max 50 files) and
   robots.txt (max 512 KiB). XML is parsed with external entities disabled.
5. **Robots.** robots.txt of each allowlisted host is honoured for the configured user agent
   (`SEO-OS-Crawler/1`, plus `*`); disallowed URLs are recorded as `blocked_by_robots`, not fetched.
   Crawl-delay is honoured up to 10 s.
6. **Authorization.** Project boundaries are authorization boundaries: only project members with
   write roles start/pause/resume/cancel; viewers read. All queries are project-scoped; run and
   snapshot IDs are only resolvable through their project.
7. **Privacy.** No cookies are stored or sent; no page bodies are retained (only extracted, bounded
   fields). Raw exception messages and resolved IPs never reach page props.

No external service is required; the crawler only contacts the site the operator owns.

## 3. Data model

- `crawl_runs`: project_id, created_by_id, mode, start_url, normalized start, allowed_hosts (json),
  settings (json: include/exclude prefixes, max_depth, max_urls, delay_ms, include_www), status
  (`queued|running|paused|completed|cancelled|failed`), pause_requested_at, cancel_requested_at,
  counters (discovered, fetched, failed, blocked), started_at, finished_at, failure_code,
  timestamps. One active (queued/running/paused) run per project, enforced in the service under a
  project lock.
- `crawl_urls` (frontier + result, one row per normalized URL per run): crawl_run_id, url,
  normalized_url, url_hash, depth, source (`start|link|sitemap|list|redirect`), state
  (`pending|fetched|failed|blocked|skipped`), attempts, next_attempt_at, and extracted fields
  (status_code, final_url, redirect_count, content_type, response_ms, bytes, title, meta
  description, robots, canonical, h1 (json, ≤5), h2 (json, ≤20), hreflang (json, ≤50), schema_types
  (json, ≤20), word_count, images, images_missing_alt, internal_links, external_links, indexable,
  error_code), page_id (nullable link to `pages`), fetched_at. Unique (crawl_run_id, url_hash);
  indexes for keyset pagination and state scans.
- `crawl_links` is deferred to the internal-linking slice; this slice stores counts only.

Original start URL input is preserved; normalized values are stored separately (AGENTS.md).

## 4. Processing

`ProcessCrawlRun` (database queue, `retry_after` ≥ job timeout) processes a bounded batch
(default 25 URLs or 50 s), checkpoints counters, then re-dispatches itself while work remains,
the run is not paused/cancelled and limits are not reached. A hard-killed worker resumes from the
frontier state (`pending` rows); a row is claimed by a guarded update so duplicate workers cannot
fetch the same URL twice. Transient failures (connect/timeout/5xx/429) retry up to 3 attempts with
backoff; permanent ones are recorded. Pause takes effect at the next URL boundary; resume
re-dispatches; cancel stops and marks remaining rows `skipped`.

After completion each fetched internal HTML URL is linked to a `pages` row by normalized URL
(existing pages reused, new `existing` pages registered through the page service as crawler
attributed); user-entered page titles are never overwritten.

## 5. UI

`/projects/{project}/crawls`: start form (Persian-first, RTL, start URL LTR), active run panel with
real counters polled via `useServerPoll` while queued/running, pause/resume/cancel, run history
(paginated) and a server-side paginated, filterable (state, status class, indexability) and
sortable URL table per run. Large runs are never sent wholesale to the browser.

## 6. Tests (behaviour, not implementation mirroring)

- URL policy: every blocked address class, IPv6 and IPv4-mapped forms, DNS pinning, redirect to a
  private address or another host refused, credentials/scheme/port rules.
- Extraction from fixture HTML, including malformed and Persian/RTL content and size bounds.
- robots.txt matching (longest match, allow/disallow, wildcards, `$`), sitemap index + gzip bounds,
  XXE refused.
- Run lifecycle through the queue with a fake transport: batches/checkpoints, resume after crash,
  pause/resume/cancel, one active run per project, retries, limits, page linking without overwrite.
- Authorization and project isolation for every endpoint; partial-reload polling returns only
  fresh run state.
- Connected MariaDB acceptance for concurrency of row claims is added to the existing connected
  job once the run engine lands.

## 7. Out of scope (later slices)

JS rendering; crawl diff/change sets and history views (§17); technical issue engine (§18);
architecture views (§19); link graph storage and internal linking (§20); scheduling of recurring
crawls; authenticated crawling.
