# Project report (V1 slice 8)

Status: approved by delegation (docs/OWNER_INTENT.md item 8, docs/DELIVERY_PLAN.md slice 8).

## Goal

One page the owner can print, save as PDF or download as CSV that summarises a
project's SEO progress for a period: where the SEO path stands, keywords, pages,
content, tasks, internal links, Google performance and the work done.

## Content (`ProjectReport`, counted from stored data, fixed number of queries)

- Period: `from`/`to` (default the last 30 days ending today; at most two years).
- SEO path: the guided workflow's step statuses and progress (reuses `ProjectGuide`).
- Keywords: keywords, non-archived clusters, clusters with a page, ready clusters.
- Pages: existing/planned, on-page status counts, and the latest completed site
  check (pages read, pages with errors).
- Content: briefs by status, published and due in the period.
- Tasks: open, overdue, done and created in the period.
- Internal links: links marked added in the period and in total.
- Performance: the latest Search Console queries import — totals, per-day click
  change versus the previous import, top 10 queries by clicks (null without data).
- Work done: the audit trail's activity events in the period, counted per kind and
  named in plain language (unknown kinds keep their key).

## Output

- Page `/projects/{project}/reports` ("Reports" in navigation) with a period form,
  "print or save as PDF" (browser printing; a print stylesheet shows only the
  report) and "download CSV".
- CSV `/projects/{project}/reports/report.csv`: UTF-8 BOM, columns label (Persian),
  stable key, value; one row per figure plus one per kind of work done.
- Read-only: every project member may view; outsiders are refused.

## Out of scope

Scheduled or emailed reports, client branding, rank tracking beyond imported
Search Console files.
