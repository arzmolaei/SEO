# Keyword Intelligence — Increment Readiness

Date: 2026-10-22

## Scope

Intent domain, deterministic Persian classifier and Intent Review Queue are
implemented and verified. The complete Intent + Clustering + Mapping subsystem
is **not complete**; Tasks 4–11 remain pending. Foundation was already merged in
PR #1. Development continues on `feature/keyword-intelligence`.

Verified product head: `a85910065ec85b94d86ae301767e9405be1da0a3`.
Permanent CI: https://github.com/arzmolaei/SEO/actions/runs/39788659107 — success.
This documentation update follows that verified product head.

## Delivered workflow

Canonical keywords produce explainable local suggestions through a database queue
job. The Review Queue is available in project navigation. It paginates 25 pending
items, shows evidence and confidence, and supports accept/change/skip inline.
Enter accepts the focused row, S skips, arrows navigate, Tab reaches controls.

High confidence is reviewed by default. Administrators may explicitly set
`keyword-intelligence.intent.auto_apply_high_confidence` to true; only known,
high-confidence suggestions without an existing review are then auto-applied.
Human assignments remain authoritative. Medium/low confidence, mixed and unknown
suggestions require review. Skips remain closed until input/result evidence
changes. Manual assignments supersede pending suggestions on refresh.

Refresh processes at most 100 keywords per job, chaining continuation jobs by ID.
Each retry upserts the same review identity. Every service checks project access;
workers recheck permissions, including access revoked after dispatch. Viewers can
read but cannot refresh or resolve. Decisions reject stale evidence, changed
keyword input and intervening manual assignments. Assignment, resolution and
attributed audit changes commit together. Review decisions do not change keyword
lifecycle status or invoke the existing status-only Undo handler.

## Verification evidence

- 148 PHP tests, 968 assertions passed locally on PHP 8.4.
- CI PHP 8.4 and 8.5 compatibility suites passed.
- Frontend formatter/linter, TypeScript, production build, Pint and PHPStan passed.
- Runtime npm audit: zero vulnerabilities; Composer: no security advisories.
- Runtime-only Composer installation and clean release-tree Laravel boot passed
  locally and in CI. No Node.js runtime is required by the release.
- Independent increment review found no Critical/Important issues.
- Real Chromium Persian workflow verified queue dispatch, actual worker execution,
  keyboard accept/skip, manual intent change and resolved-item refresh idempotency.
- Desktop RTL and 390px mobile were exercised without JavaScript page errors or
  horizontal overflow. Browser testing found a pre-existing fixed-left sidebar
  blocking RTL actions; sidebar side now follows the shared direction contract.

## Runtime and operational notes

The current Laravel 13 dependency baseline actually requires **PHP 8.4.1+**
(`composer.json`), despite earlier documents describing a PHP 8.3 floor. PHP 8.3
compatibility is not verified. Database queue and local storage remain defaults.

Queued suggestion generation needs the existing queue worker (`php artisan
queue:work`) or a cron-driven worker. Jobs have a 60-second timeout, below the
existing database queue retry interval. The UI reports that preparation was
requested and offers explicit result reload; it does not claim a dispatched job
has completed. Worker failures remain visible through Laravel failed-job/log
facilities. No international API, AI service or external font CDN is required.

## Deferred minor and next work

Resolving/reloading page 2+ currently returns to page 1. This affects review
position, not decision integrity; preserve pagination in a later UX pass.

Next task: project-scoped cluster domain/membership and primary-keyword invariant,
then deterministic clustering and manual operations. Existing/planned pages,
mapping conflicts and prioritization follow. Do not start crawler work or merge
this whole subsystem as complete before its remaining acceptance/review gates.
