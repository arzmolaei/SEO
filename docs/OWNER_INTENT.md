# SEO OS — Owner Intent and Product Boundary

Effective: 2026-10-28 (revised the same day by the owner; replaces the earlier keyword-only version)

This document records the owner's product intent. It is the **highest authority for what to build**. Live Git and exact-head CI remain authoritative for implementation status. When any older spec, delivery plan, handoff note or checkpoint conflicts with this document on product direction, **this document wins**.

## Who this is for

- **One person: the owner**, using it for their own SEO work. The owner is new to SEO.
- Not a team product, not an agency/multi-client platform, not a commercial or SaaS product.
- Self-hosted for the owner. Install, backup, restore and update must stay reliable so the owner's data survives new versions.

The original master design (`docs/superpowers/specs/2026-10-22-seo-os-design.md`) described a commercial multi-client/team SEO platform. It is **historical context only** and is not a roadmap.

## What the product must do

A complete, practical SEO workspace for one person that **guides a beginner through real SEO work** from research to execution and measurement — without heavy crawler infrastructure or paid external data.

### Already integrated (keep green, improve only on observed problems)

- browser installation, owner bootstrap, backup, restore, update, interrupted-update recovery/rollback;
- project creation; Persian keyword import (CSV/paste) with original data preserved;
- normalization, intent suggestions and review, clustering, manual merge/split/move/lock, undo;
- existing/planned pages, cluster-to-page mapping, mapping-conflict/cannibalization warnings, priority/readiness;
- Persian SEO-plan CSV export;
- basic site crawler (kept and reused for checking the owner's own pages; not expanded).

### To build, in this order (each a bounded, separately released slice)

1. **Guided workflow + "what should I do next?"** — project overview shows the SEO steps in order, what is done, and the most important next actions linked to the right screen; short Persian explanations of each step for a beginner.
2. **Pages workspace + on-page checklist** — per page: target keyword/cluster, title, meta description, H1, URL, status, and a checklist with clear pass/fix guidance.
3. **Own-site page check** — reuse the existing crawler, bounded, to read the owner's pages: status code, title, meta description, H1, canonical, noindex, word count; show problems per page. No crawler expansion.
4. **Content briefs + content calendar** — generate a brief from a cluster (primary/secondary keywords, intent, suggested headings from cluster keywords, notes); track draft → review → published with dates.
5. **Tasks** — simple to-do list linked to project/page/cluster with due date and status. Single user: no assignment or team workflow.
6. **Internal link suggestions** — from clusters and page mappings (and existing crawl link data when available): which page should link to which, with suggested anchor text.
7. **Search Console data via CSV import** — queries/pages with clicks, impressions, CTR, position over time; opportunity list (positions 4–20, low CTR, declining pages). Import only; no Google API connection.
8. **Project report** — printable/exportable summary of progress, rankings and work done.

After these, **use the product**. Further work is driven only by problems found in real use.

## Out of scope — do not build

- crawler expansion, crawl history/diff, JavaScript rendering, crawler-derived issue engine, site-architecture visualizations;
- backlink tools or any paid/external SEO data provider;
- SERP APIs, external AI providers, embeddings/vector infrastructure;
- Google Search Console/Analytics API integration (CSV import only);
- WordPress/CMS integrations;
- team features (roles UI, assignment, notifications), multi-client/agency features, client portals;
- SaaS, plans, entitlements, billing, commercialization;
- release signing and chunked upload unless a real deployment blocker appears;
- speculative abstractions and broad refactors not fixing an observed problem.

Existing code for these areas (e.g. the crawler baseline, workspace/membership roles) stays and must not be broken, but must not be extended. Navigation must not show sections that are out of scope or not yet built.

## Rules for any agent

Before starting work, ask: **does this directly help the owner do their own SEO work, or protect their data across updates?** If not, do not build it.

- Build the list above in order; one slice at a time; finish (tests, exact-head CI, merge, docs) before starting the next.
- Keep it simple for a single beginner user: plain Persian explanations, sensible defaults, no configuration the owner must understand first.
- Persian-first RTL UI; interactions use Inertia/AJAX partial updates where useful, no full-page reloads except where technically required (release switch after update).
- Offline-capable core: no external API is required.
- Do not trade away existing lifecycle, security or data-integrity guarantees to move faster.
