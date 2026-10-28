# Content briefs + content calendar (V1 slice 4)

Status: approved by delegation (docs/OWNER_INTENT.md item 4, docs/DELIVERY_PLAN.md slice 4).

## Goal

From a keyword cluster, create a brief the owner can write from: primary keyword,
related keywords, what kind of content suits the search intent, suggested
headings and notes. Track each piece from draft → review → published with a due
date and a publication date, and show what is late and what is coming.

## Data

New table `content_briefs` (added to the backup catalog and its guard tests, and
to the connected backup/restore fixture): `project_id`, `keyword_cluster_id`
(nullable, unique — one brief per cluster, `nullOnDelete` so undoing a cluster
never blocks or deletes the brief), `page_id` (the cluster's target page at
creation, `nullOnDelete`), `title`, a snapshot of `primary_keyword`,
`secondary_keywords` and `intent`, editable `headings` (list) and `notes`,
`status` (`draft|review|published`), `due_on`, `published_on`.

## Generation (`ContentBriefs::generate`, `BriefOutline`)

- Only non-archived clusters of the same project; the cluster row is locked, and
  a second request returns the existing brief (idempotent).
- Related keywords are the cluster members ordered by their current search
  volume (the latest observation, as in the SEO plan export), then id; at most 50.
- Headings come only from the cluster's own keywords: up to 8 sections, and
  question keywords (Persian/English question words or ?/؟) as an FAQ block of up
  to 6 after a "پرسش‌های متداول" heading. Persian-normalized de-duplication; the
  primary keyword is not repeated. No templates, AI or external data.
- Intent guidance (what kind of content to write) is fixed beginner copy per intent.

## Editing and audit

Title, headings (one per line), notes, status and dates are editable by
`update`-permitted members; viewers read only; other projects' briefs are 404
through scoped bindings. A published brief always has a publication date: today
when first published without one, otherwise the known date is kept. Create,
update (only when something changed) and delete each write a change set with
full before/after snapshots, so a deleted brief is recoverable from history.

## Calendar

Locale-neutral groups instead of a month grid (the owner reads Persian dates;
a Gregorian month grid would mislead): overdue, next 7 days, within a month,
later, no date, and published in the last 30 days. Each group shows its count and
at most 50 items; the full list is server-side paginated and filterable by status.
The brief can be copied as plain text.

## Out of scope

Writing or AI-generating the content, CMS publishing, assignment/reviewers,
reminders/notifications.
