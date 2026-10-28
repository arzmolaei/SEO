# Guided workflow — "what should I do next?" (V1 slice 1)

Authority: `docs/OWNER_INTENT.md`, build-order item 1.

The project overview becomes the beginner's home: the SEO path in order, each step's real status,
the most important next actions linked to the exact screen, and a short plain-Persian explanation.

## Steps and how status is derived (server-side, from stored data only)

| Step | Done when | Link |
| --- | --- | --- |
| import | the project has keywords | keyword import |
| intent | no pending intent reviews | review queue |
| clusters | at least one active cluster, none pending review | review queue / clusters tab |
| mapping | every active cluster has a target page | mapping tab |
| conflicts | no pending mapping-conflict reviews (status `attention` otherwise) | review queue |
| ready | every mapped cluster is marked ready | mapping tab |
| export | the SEO plan was downloaded at least once (recorded as activity `export.seo_plan`) | CSV download |
| site_check | a crawl of the owner's site has completed | own-site check |

Statuses: `done`, `todo`, `attention`, `waiting` (an earlier prerequisite is missing). Archived
clusters never count. Next actions: up to three actionable steps, problems (`attention`) first,
then path order. Nothing is self-reported; a step can return to `todo` when data changes.

The keyword page accepts `?tab=clusters|mapping|intent` so links open the right tab. Copy lives in
`resources/js/lib/project-guide.ts` (Persian and English, Persian digits).

## Tests

Status derivation through the whole path (including archived clusters and conflicts first),
export recording, overview props and project authorization; copy coverage for every step; the
browser lifecycle acceptance asserts the guide after a real import.
