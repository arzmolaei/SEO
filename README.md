# SEO OS

A personal, Persian-first SEO workspace that you host yourself. It guides one
owner through real SEO work on their own site — keyword research and clustering,
pages and on-page checks, content briefs, tasks, internal links, Search Console
data and progress reports — without any external service, AI provider or CDN.

- **Install:** download the latest release from the GitHub Releases page, point the
  web root at `public/` and open `/install`. Requirements and steps are in the
  release notes (`docs/releases/`).
- **Product direction:** `docs/OWNER_INTENT.md` and `docs/DELIVERY_PLAN.md`.
- **Current state:** `docs/PROJECT_STATE.md`.
- **Development:** `docs/development.md` and `AGENTS.md`.
- **Operations:** `docs/backup-restore-operations.md`, `docs/update-recovery-operations.md`,
  `docs/update-package-build.md`.

A release is published by pushing a tag `v<VERSION>` that matches the `VERSION`
file; the Release workflow builds the runtime-only tree and attaches it with
SHA-256 sums.
