# Search Console CSV import + opportunities (V1 slice 7)

Status: approved by delegation (docs/OWNER_INTENT.md item 7, docs/DELIVERY_PLAN.md slice 7).

## Goal

Let the owner see how the site performs in Google from the files Search Console
already exports — no Google API, no account connection — and turn that into a
short list of concrete opportunities.

## Import

- Input: `Queries.csv` or `Pages.csv` from the Performance report's
  "Export → Download CSV", in English or Persian UI (headers such as
  «پرسش‌های برتر», «کلیک‌ها», «نمایش‌ها», «جایگاه»). The kind is detected from the
  header row; any other CSV is refused with an explanation. The owner states the
  date range the export covers (defaults: the last 90 days ending yesterday;
  the end may not be in the future).
- Parsing (`SearchConsoleCsv`, pure) accepts BOM/UTF-16, Persian digits, thousands
  separators and percent signs, and skips impossible rows (empty key, negative
  numbers, clicks above impressions). Duplicate keys after normalization keep
  the first row. Bounds: 10 MB, 50,000 rows.
- Storage: `search_console_imports` (kind, period, filename, row and skipped
  counts) and `search_console_rows` (original key text, derived normalized key and
  hash, clicks, impressions, position). Queries are normalized like keywords;
  page addresses like page URLs, so both link to the owner's keywords and pages.
  Both tables are in the backup catalog and the connected backup/restore fixture.
- Import and delete are each one audited change set. Deleting an import removes
  its rows; the original CSV can simply be imported again.

## Insights

- Totals per import: clicks, impressions, CTR and impression-weighted average
  position; the latest import is compared with the previous import of the same
  kind per day, so periods of different length compare fairly.
- Opportunities (`SearchOpportunities`, pure, fixed beginner defaults; candidates
  narrowed in SQL so large imports never load whole):
  - close to the top: queries at average position 4–20 with ≥ 20 impressions;
  - fewer clicks than expected: page-one queries (≥ 100 impressions) whose CTR is
    under half the typical CTR for their position, ranked by missed clicks;
  - declining pages: pages whose clicks per day fell ≥ 30% versus the previous
    pages import (previous ≥ 1 click/day).
  Each item shows whether it is one of the owner's keywords, links to the owner's
  page when known, and can be added to Tasks in one click.
- Data table: queries or pages of any import, server-side paginated (25),
  searchable and sortable by clicks, impressions or position.

## Out of scope

Google Search Console / Analytics API, automatic scheduled imports, other export
files (countries, devices, dates), rank tracking.
