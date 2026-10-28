# Own-site page check (V1 slice 3)

Status: approved by delegation (docs/OWNER_INTENT.md item 3, docs/DELIVERY_PLAN.md slice 3).

## Goal

Read the owner's own pages from their live site and show, per page and in plain
Persian, what is wrong there: status code, redirect, noindex, canonical, title,
meta description, H1 and word count, compared with what the owner wrote in the
pages workspace. Reuse the existing crawler unchanged; no crawler expansion.

## How it works

- **Start (`POST /projects/{project}/pages/check`, `crawl` permission):** starts a
  normal crawl run in the existing `list` mode with the project's page addresses
  only (first 500 by id), `max_depth` 0, so links are never followed. The run is
  subject to all existing crawler guarantees (SSRF policy, robots.txt, one active
  run per project, pause/cancel, audit). The www/apex twin of the first page's
  host is allowed; addresses on other hosts are skipped by the existing scope rule.
- **Progress:** the pages list shows the active run's counters and polls only the
  `pages` and `check` props until it settles; details link to the crawl screen.
- **Results (`PageLiveFacts`):** for each page, the latest row among the
  project's *completed* runs whose normalized-URL hash matches the page (one
  grouped query, ids grow per run). Matching by hash finds pages that answered with
  errors or redirects, which the crawler never links to pages.
- **Rules (`LivePageCheck`, pure):** unreadable → `fetch_failed` (with the crawler's
  explained error code); ≥ 400 → `http_error`; otherwise `redirected`, `not_html`,
  `noindex` (meta robots or X-Robots-Tag), `canonical_elsewhere`, title/meta
  missing or different from the planned text (Persian-normalized), H1 missing /
  multiple / different, `thin_content` (< 300 words).
- **Adopt live values (`POST …/pages/{page}/adopt-live`, `update` permission):**
  copies the live title, description and first H1 into fields the owner left
  empty, never overwriting; one `page.live_values_adopted` change set when
  something changed.

## UI

The pages list gets a "check pages on your site" panel and a per-row problem
count ("not checked" when there is no result). The page detail shows the live
values, each problem with its explanation and fix, and the adopt button.

## Out of scope

Scheduling, history/diff of checks, JavaScript rendering, site-wide crawling from
this screen, an issue engine. The crawl screen remains as it was.
