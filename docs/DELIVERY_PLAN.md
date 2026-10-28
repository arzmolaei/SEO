# SEO OS delivery plan

Authority: `docs/OWNER_INTENT.md` (product direction). Live Git + exact-head CI (implementation status).

Product: a complete personal SEO workspace for **one owner**, self-hosted, Persian-first. Not a team, agency or commercial product.

## Integrated baseline (do not rebuild without new failing evidence)

- Foundation + Keyword Core (PR #1), Keyword Intelligence (PR #2)
- Self-host Installer (PR #4), Persian Export (PR #5), Backup + Restore (PR #7)
- Update + Interrupted Recovery/Rollback (PR #8, #21) with permanent MariaDB and browser lifecycle acceptance (PR #23, #24 and follow-ups)
- Persian home, project creation and real keyword import UI (PR #22), automatic status polling (PR #25)
- Persian framework/auth strings, bilingual launcher pages (PR #27, #29)
- Crawler baseline (PR #28) — reused only for the own-site page check; no expansion

The browser lifecycle acceptance (`.github/workflows/lifecycle-e2e.yml`) covers install → project → CSV import → intelligence → export → backup → restore on a second instance → update and failure/recovery cases. It must stay green.

## Build order

Each slice: short design note → RED/GREEN tests → exact-head CI green → merge → update `docs/PROJECT_STATE.md`. Extend the browser lifecycle acceptance when a slice adds a primary user flow.

| #   | Slice                                     | Done when                                                                                                     |
| --- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| 0   | Scope cleanup                             | Out-of-scope / unbuilt sections hidden from navigation; docs aligned with OWNER_INTENT                        |
| 1   | Guided workflow + "what next?"            | Overview shows ordered SEO steps with status, next actions linking to the right screen, beginner explanations |
| 2   | Pages workspace + on-page checklist       | Per-page target, title/meta/H1/URL, checklist with guidance; server-side list                                 |
| 3   | Own-site page check                       | Bounded check of the owner's pages via existing crawler; per-page problems shown and linked to the checklist  |
| 4   | Content briefs + calendar                 | Brief from cluster; draft/review/published status and dates                                                   |
| 5   | Tasks                                     | Single-user tasks linked to project/page/cluster with due date and status                                     |
| 6   | Internal link suggestions                 | Suggestions from clusters/mappings (and crawl links if present) with anchor text                              |
| 7   | Search Console CSV import + opportunities | Import queries/pages metrics over time; opportunity list                                                      |
| 8   | Project report                            | Printable/exportable progress summary                                                                         |

Then: tag a release, the owner uses it, and only problems found in real use become new work.

## Out of scope

See `docs/OWNER_INTENT.md` — crawler expansion, architecture views, backlinks, SERP/AI providers, Google APIs, CMS integrations, team/agency features, SaaS/billing.

## Release notes

SHA-256 package checks prove integrity, not publisher authenticity; signing is not claimed. Hosts must allow uploads at least as large as a release package.
