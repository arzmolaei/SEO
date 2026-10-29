# Internal link suggestions (V1 slice 6)

Status: approved by delegation (docs/OWNER_INTENT.md item 6, docs/DELIVERY_PLAN.md slice 6).

## Goal

Tell the owner which of their pages should link to which, with the link text to
use, so related pages support each other. Offline: only the owner's clusters and
page mappings are used.

## How suggestions are made (`InternalLinkSuggester`, pure)

- Input: every non-archived cluster mapped to a page (at most 2,000 clusters,
  30 keywords each), with its primary keyword and member keywords.
- Each cluster becomes a set of meaningful words (Persian-normalized; Persian and
  English stop words and generic shopping modifiers such as «خرید», «قیمت»,
  «بهترین» removed; words shorter than 2 letters dropped). A word that appears in
  more than 30% of the mapped clusters (minimum 5) describes the whole site, not a
  topic, and is ignored.
- Two clusters on different pages that share words get a score = shared words /
  all words of both (Jaccard). At ≥ 0.15, the source cluster's page should link to
  the target cluster's page. Each target page keeps its 5 strongest sources.
- Link text: the target's primary keyword; up to two alternative texts are other
  target keywords that also fit the source page's topic, so anchors can vary.
- The existing crawler stores only link counts (no link graph), so crawl data is
  not used; no crawler change.

## Decisions

New table `internal_link_decisions` (backup catalog, guard tests and the
connected backup/restore fixture): one row per (project, source page, target
page) with status `done` (link added) or `dismissed` (not useful), the anchor used,
and who decided; removed with either page. Reopening deletes the decision. Every
change is an audited `internal_link.decided` change set. Both pages must be
different pages of the same project.

## UI

`/projects/{project}/internal-links`: views suggestions / added / dismissed with
counts, 25 per page, optional focus on one page (from the page detail's "internal
link suggestions" link), copyable link texts, and the shared topic words as the
reason. When fewer than two clusters are mapped, the page explains what to do.

## Out of scope

Crawl link graph storage, automatic insertion into a CMS, link position advice.
