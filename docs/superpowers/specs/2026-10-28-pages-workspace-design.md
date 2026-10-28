# Pages workspace + on-page checklist (V1 slice 2)

Status: approved by delegation (docs/OWNER_INTENT.md, docs/DELIVERY_PLAN.md slice 2).

## Goal

One place for every page of the owner's site (existing or planned). For each page the
owner writes the SEO title, meta description and H1, and a plain-Persian checklist
says what is fine and what to fix next. No crawling, scoring services or AI: the
checklist is a pure function of what the owner entered and of project facts.

## Data

New nullable columns on `pages` (no new table, so the backup catalog is unchanged):
`seo_title` (255), `meta_description` (500), `h1` (255), `on_page_notes` (text) and
`on_page_status` (`todo|in_progress|done`, default `todo`), with an index on
`(project_id, on_page_status, id)` for the filtered list. The existing `title`
(the owner's page name) is never overwritten.

## Checklist (`App\Domain\Pages\OnPageChecklist`)

Each item is `pass`, `fix` or `na` (does not apply yet); the score counts only
applicable items. Items: has a target cluster; only one cluster targets it (else
cannibalization); title present / 30–65 characters / contains the primary keyword /
unique in the project; meta present / 70–160 characters / contains the keyword; H1
present / contains the keyword; address recorded (`na` for planned pages); address
short (≤ 75 characters, < 5 levels). Keyword and duplicate matching use
`PersianKeywordNormalizer` (Arabic ي/ك, ZWNJ, spacing), case-insensitive. Lengths
count characters, not bytes.

`PageChecklistFacts` gathers targets and title duplicates for a page set in a fixed
number of queries (never per page).

## UI and routes

- `GET /projects/{project}/pages`: server-side paginated (20), filters `q`
  (title/address/SEO title, LIKE-escaped), `status`, `kind`; partial reloads.
- `GET|PATCH /projects/{project}/pages/{page}`: scoped bindings, so a page of another
  project is 404. Form with live character counts; checklist with the fix guidance
  under each red item; related cluster keywords to use in the copy.
- "Pages" is added to the project navigation.

## Authorization and audit

Members view; `update` (owner/admin/SEO roles) edits; viewers are read-only;
outsiders 403. An edit runs in a transaction with a row lock and writes one
`page.on_page_updated` ChangeSet + ActivityEvent with before/after values, only
when something actually changed.

## Out of scope here

Fetching the live page to compare (slice 3, own-site page check), content briefs
(slice 4), tasks (slice 5).
