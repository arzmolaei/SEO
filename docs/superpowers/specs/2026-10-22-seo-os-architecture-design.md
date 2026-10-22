# SEO OS — Product & Architecture Design

**Date:** 2026-10-22  
**Status:** Approved design baseline for implementation planning  
**Repository:** `arzmolaei/SEO`

## 1. Product intent

SEO OS is a self-hostable SEO operations workspace for freelancers and small SEO teams. It should replace a loose collection of spreadsheets, one-off crawlers, scripts, and SaaS dashboards with one durable local system that owns the project data and remains usable even when international internet access is degraded or unavailable.

The product is not intended to be a clone of every enterprise SEO platform in version 1. The first release should be a reliable technical-SEO operating system: project management, site crawling, URL inventory, issue detection, historical comparison, filtering, exports, and a workflow for turning findings into actionable work.

### Success criteria

A successful first production release can:

- run on a single self-hosted server using Docker Compose;
- be installed on infrastructure inside Iran and continue operating on already-reachable/local sites during international connectivity restrictions;
- crawl and analyze projects well beyond 10,000 URLs without an artificial product limit;
- avoid runtime dependencies on CDNs, hosted databases, hosted queues, or third-party UI assets;
- preserve crawl history so users can compare changes between runs;
- recover cleanly after process restarts, server reboots, or interrupted internet connectivity;
- expose understandable technical SEO findings rather than only raw crawl data;
- remain simple enough for one operator to install, back up, restore, and upgrade.

## 2. Guiding principles

### 2.1 Local-first and self-hosted

All core application functions must run from the deployment itself. The browser UI, API, database, queue, worker, and static assets are local. Third-party APIs are optional integrations rather than core dependencies.

### 2.2 No fixed URL ceiling

SEO OS will not contain a commercial-style hard cap such as “10,000 links.” Crawl limits are operational safeguards set per crawl, not licensing restrictions.

Each crawl receives configurable budgets such as:

- maximum discovered URLs;
- maximum crawl depth;
- maximum crawl duration;
- concurrency;
- per-host request rate;
- rendered-page budget;
- response-size limit.

Defaults should be conservative enough for normal shared servers, while administrators can raise them based on available CPU, RAM, disk, and target-site capacity.

### 2.3 Graceful degradation

If Google, an external API, DNS path, or international route is unavailable, the core application must remain usable. Jobs that require unavailable services should move into a retriable/blocked state with a clear explanation rather than breaking unrelated product areas.

### 2.4 Durable jobs

Long crawls cannot live inside HTTP requests. Crawls, imports, exports, and expensive analyses run as durable background jobs with persisted state and resumable/retriable steps.

### 2.5 Explainable findings

Every issue should answer:

1. What is wrong?
2. Why can it matter for SEO or crawlability?
3. Which URLs are affected?
4. What evidence triggered the issue?
5. What is the recommended next action?

### 2.6 Conservative automation

The system can detect and prioritize problems, but it should not silently modify customer websites. External write actions are outside the initial core product.

## 3. Scope

### 3.1 Version 1 core

The first implementation milestone includes:

- local authentication;
- workspaces and projects;
- project site/domain configuration;
- crawl configuration and crawl scheduling on demand;
- URL discovery from seed URLs, HTML links, redirects, canonicals, XML sitemaps, and optionally robots.txt sitemap declarations;
- HTTP crawling without JavaScript rendering as the default;
- optional JavaScript rendering for selected URLs or rules;
- normalized URL inventory;
- page metadata extraction;
- internal-link graph collection;
- technical issue detection;
- crawl summary dashboards;
- URL explorer with filters and saved views;
- issue explorer with affected URLs and evidence;
- crawl-to-crawl comparison;
- CSV exports;
- application health and job status views;
- backup/restore documentation;
- offline-friendly installation package strategy.

### 3.2 Explicitly deferred

These are valuable, but not part of the first core milestone:

- backlink index crawling of the public web;
- rank tracking at search-engine scale;
- AI-generated content publishing;
- full Google Search Console or Analytics parity;
- team billing/subscriptions;
- multi-region distributed crawling;
- automatic changes to customer CMSs;
- enterprise SSO;
- browser extensions;
- mobile applications.

The architecture should leave clean extension points for integrations and future modules without requiring them now.

## 4. Recommended technical architecture

SEO OS should begin as a **modular monolith with separate worker processes**, not a microservice fleet.

This provides clear boundaries while keeping deployment and operations simple.

### 4.1 Runtime components

1. **Web application**
   - Next.js + TypeScript.
   - Serves the user interface and authenticated application API.
   - Does not execute long-running crawl work.

2. **Worker service**
   - Node.js + TypeScript.
   - Consumes durable background jobs.
   - Performs HTTP fetches, parsing, crawl orchestration, analysis, exports, cleanup, and optional browser-render jobs.

3. **PostgreSQL**
   - System of record for users, projects, crawl runs, normalized URL records, observations, issues, links, job metadata, and configuration.

4. **Redis**
   - Queue coordination, leases, short-lived locks, throttling counters, and transient job state.
   - PostgreSQL remains the durable product record.

5. **Local persistent storage**
   - Filesystem volume by default for generated exports, optional response artifacts, screenshots, and backup staging.
   - Storage abstraction should allow an S3-compatible target later.

6. **Optional browser renderer**
   - Playwright/Chromium executed only for pages that need rendering.
   - Kept logically separate from normal HTTP crawling because it is much more expensive.

### 4.2 Why not microservices initially

Microservices would add deployment fragility, networking complexity, version coordination, logging overhead, and more images to distribute for offline installation. The initial workload does not justify that cost.

Code should still be split into strongly bounded modules so a worker or subsystem can become an independent service later if real scale requires it.

## 5. Repository structure

Target structure:

```text
SEO/
  apps/
    web/                 # Next.js UI + application API
    worker/              # background worker process
  packages/
    db/                  # schema, migrations, repositories
    domain/              # shared domain models and invariants
    crawler/             # URL frontier, fetching, parsing, normalization
    analyzer/            # SEO rules and issue engine
    queue/               # queue contracts and job definitions
    storage/             # local/S3-like artifact abstraction
    config/              # validated configuration
    observability/       # logging/metrics helpers
    ui/                  # shared UI components where useful
  infra/
    docker/
    compose/
    scripts/
  docs/
    superpowers/
      specs/
      plans/
    operations/
  tests/
```

Application modules must not reach directly into unrelated modules’ database tables. Shared behavior should be exposed through explicit repository/service interfaces.

## 6. Core domain model

### Workspace

A logical container for users and projects. Version 1 can support a simple owner/admin model while keeping the schema ready for more roles.

### Project

Represents one SEO property/site and contains:

- display name;
- primary origin/domain;
- allowed hosts/subdomains;
- crawl defaults;
- authentication/header rules if explicitly configured;
- include/exclude patterns;
- rendering policy;
- locale/timezone metadata.

### CrawlRun

An immutable historical crawl execution record containing configuration snapshot, start/end times, state, counters, termination reason, and version of the analyzer rules used.

A crawl run is never “updated into” a future crawl. This enables reliable comparisons.

### UrlEntity

The canonical project-level URL identity after normalization. It allows observations from multiple crawl runs to point to the same logical URL.

### PageObservation

The data observed for one URL during one crawl run, including:

- requested URL;
- final URL;
- status code;
- redirect chain;
- content type;
- response size;
- timing fields;
- robots directives;
- canonical target;
- title;
- meta description;
- headings;
- language/hreflang data;
- structured-data summary;
- content hash/fingerprint;
- indexability classification;
- fetch/render method;
- parse/fetch errors.

### LinkEdge

A directed relationship discovered during a crawl. Store source, target, link type/context, rel attributes, and whether the link is considered internal.

### IssueDefinition

A versioned rule such as `TITLE_MISSING`, `CANONICAL_TO_REDIRECT`, or `INTERNAL_5XX`.

### IssueOccurrence

An issue triggered for a specific crawl run and entity, with severity, structured evidence, and rule version.

### Job

Durable work record for crawl orchestration, render requests, exports, cleanup, and integration syncs.

## 7. Crawl engine design

### 7.1 URL frontier

The crawler uses a persisted frontier rather than an in-memory-only queue. A crawl interruption must not corrupt the run or lose its state.

Each candidate URL receives a normalized key and frontier state such as discovered, queued, fetching, fetched, skipped, failed, or blocked.

### 7.2 URL normalization

Normalization must be deterministic and extensively tested. It should cover at least:

- scheme/host case normalization;
- default ports;
- fragments;
- path normalization where safe;
- percent encoding consistency;
- configurable query-parameter handling;
- trailing slash policy without inventing redirects;
- IDN/punycode handling.

Normalization must not casually remove query parameters because parameters may identify real pages. Tracking-parameter stripping should be an explicit configurable policy.

### 7.3 Discovery sources

The crawler can discover URLs from:

- project seed URLs;
- HTML anchors;
- redirects;
- canonical references;
- hreflang references;
- XML sitemap indexes and sitemap files;
- robots.txt sitemap declarations.

Discovery source is stored as evidence so users can understand why a URL entered the crawl.

### 7.4 Fetching policy

Default crawler behavior:

- normal HTTP fetch first;
- bounded redirects;
- compressed-response support;
- per-host throttling;
- configurable concurrency;
- request timeout;
- maximum response body size;
- clear user agent;
- optional robots.txt obedience mode, explicit in project settings;
- retry only safe transient failures using bounded exponential backoff.

### 7.5 JavaScript rendering

Rendering is opt-in or rule-driven, not default for every page.

Possible policies:

- disabled;
- render manually selected URLs;
- render URLs matching patterns;
- render when static HTML appears insufficient according to conservative detection rules.

Rendered and non-rendered observations should record which method produced the data.

### 7.6 Crawl termination

A run may end as:

- completed;
- completed_with_errors;
- stopped_by_user;
- budget_reached;
- failed;
- blocked_by_dependency.

The user should always see the reason and partial results should remain queryable when safe.

## 8. Initial technical SEO rule set

The first rule engine should support rules in groups.

### Crawlability and status

- 4xx pages;
- 5xx pages;
- redirect chains;
- redirect loops;
- broken internal links;
- excessive redirect hops;
- unexpectedly blocked pages;
- non-HTML responses linked as pages.

### Indexability

- noindex pages;
- X-Robots-Tag noindex;
- conflicting robots directives;
- canonical missing where policy expects it;
- canonical to 4xx/5xx;
- canonical to redirect;
- canonical outside allowed host;
- canonical chains;
- indexable URL canonicalized to another URL.

### Metadata

- missing title;
- empty title;
- duplicate title;
- configurable title length warnings;
- missing meta description;
- duplicate meta description;
- multiple H1s;
- missing H1.

### Internal architecture

- orphan candidates based on crawl/sitemap evidence;
- very high crawl depth;
- pages with zero incoming internal links;
- excessive internal outlinks;
- links to redirected URLs;
- internal links to non-indexable URLs.

### Sitemaps and directives

- sitemap URL returning non-200;
- sitemap URL redirecting;
- sitemap containing non-indexable URL;
- sitemap containing canonicalized URL;
- indexable crawled URL absent from sitemap (informational, configurable);
- robots/sitemap inconsistencies.

Rule thresholds must be configuration, not scattered magic numbers.

## 9. Issue engine

Rules should be deterministic pure logic wherever practical: observation(s) in, issue occurrence(s) out.

Each issue definition contains:

- stable machine code;
- user-facing title;
- severity default;
- category;
- explanation;
- remediation guidance;
- rule version;
- evidence schema.

This makes rules testable and enables re-analysis of stored crawl data when rules evolve.

Severity levels:

- critical;
- high;
- medium;
- low;
- info.

Severity defaults may be overridden at workspace/project level later, but the first release can keep defaults fixed.

## 10. Historical comparison

History is a core feature, not an afterthought.

Users should be able to compare two crawl runs for:

- added/removed URLs;
- status-code changes;
- indexability changes;
- title/meta/canonical changes;
- new/resolved issue counts;
- issue severity deltas;
- changes in crawl depth and internal-link counts.

A comparison should be computed from immutable run observations. The system should not overwrite historical observations when a new crawl runs.

## 11. User experience

### 11.1 Main navigation

Initial application areas:

- Overview;
- Projects;
- Crawls;
- URLs;
- Issues;
- Compare;
- Exports;
- System.

### 11.2 Project dashboard

Show the latest crawl status, headline URL counts, issue counts by severity/category, recent trend deltas, and the most important newly introduced issues.

### 11.3 URL explorer

This is a power-user table and must support server-side filtering/sorting for large datasets.

Useful filters include:

- status code/range;
- indexability;
- content type;
- depth;
- title/meta presence;
- canonical state;
- issue presence/severity;
- sitemap membership;
- incoming/outgoing link counts;
- URL contains/starts with/regex where safely supported.

Views should be linkable/bookmarkable through URL query state where practical.

### 11.4 Issue explorer

Users navigate from issue type to affected URLs, evidence, and recommendation. Do not force users to export raw data just to understand a finding.

## 12. Scale strategy

Version 1 should be engineered for hundreds of thousands of URL observations across historical runs on one serious server, without promising unlimited scale.

Important practices:

- pagination everywhere;
- no “load all URLs into browser” paths;
- bulk inserts/upserts;
- indexed query columns;
- compressed/structured artifact storage where appropriate;
- streaming exports;
- bounded in-memory parsing;
- database retention/cleanup tools;
- explicit rendered-page budgets;
- internal-link graph storage designed to avoid accidental N+1 queries.

The practical crawl capacity will depend on server resources and target sites, but product logic must not impose a 10k ceiling.

## 13. Reliability and failure handling

### 13.1 Job idempotency

Jobs should be safe to retry. A worker crash after writing partial data must not duplicate observations or silently corrupt counters.

### 13.2 Leases and heartbeats

Long tasks use a lease/heartbeat model. If a worker disappears, abandoned work can be recovered after a timeout.

### 13.3 Network failure classification

Differentiate at least:

- DNS failure;
- connection timeout;
- TLS failure;
- remote reset;
- HTTP status failure;
- parser failure;
- browser-render failure;
- dependency unavailable.

### 13.4 Restart behavior

After container/server restart:

- web app comes back independently;
- queue reconnects;
- abandoned jobs become recoverable;
- historical data remains usable;
- users can see if a crawl resumed, retried, or failed.

## 14. Iran / restricted-connectivity design

This is a first-class operational requirement.

### 14.1 No CDN dependency

Fonts, icons, JavaScript, CSS, and application assets must ship locally. The product should not require Google Fonts, unpkg, jsDelivr, CDN-hosted scripts, or remote UI resources at runtime.

### 14.2 Offline installation bundle

Provide two installation paths:

1. **Online install** — normal Docker image pulls.
2. **Offline/limited-network install** — downloadable release bundle prepared in advance containing versioned Docker image archives, Compose files, environment template, migration tooling, checksums, and install/upgrade scripts.

Operators should be able to transfer the bundle to an Iranian server and load images with Docker without reaching international registries during installation.

### 14.3 Dependency isolation

External integrations use clear adapters and timeouts. If an integration is unreachable, its jobs pause/fail independently.

### 14.4 Local documentation

Installation, upgrade, backup, restore, and troubleshooting documentation should be included in the repository/release bundle rather than relying only on an external documentation website.

### 14.5 Time and DNS awareness

Operational docs should recommend reliable local NTP/time configuration and DNS behavior because TLS and network restrictions can create misleading crawl errors when server time or name resolution is unhealthy.

## 15. Security baseline

Version 1 must include:

- password hashing using a modern memory-hard algorithm;
- secure session cookies;
- CSRF protection where applicable;
- server-side authorization checks;
- secrets only from environment/secret files, never committed;
- SSRF defenses in crawler and integrations;
- egress target validation;
- blocking unsafe/private-address crawling by default unless explicitly enabled by an administrator;
- HTML sanitization/escaping in evidence views;
- upload/import size limits;
- rate limiting for authentication endpoints;
- audit logging for sensitive administrative actions where practical.

Because the product intentionally fetches user-provided URLs, SSRF protection is a critical design requirement.

## 16. Observability

The system should expose enough local information to diagnose failures without a hosted observability vendor.

Required:

- structured JSON logs;
- correlation IDs for crawl/job operations;
- system health endpoint;
- queue depth/worker health view;
- recent job failures;
- database migration/version information;
- disk-space warnings for persistent volumes.

A future Prometheus endpoint is useful, but hosted telemetry must remain optional.

## 17. Data retention, backup, and restore

Default posture: keep crawl history until the operator removes it, but provide retention controls before storage becomes unmanageable.

Backup scope includes:

- PostgreSQL database;
- application artifact volume;
- configuration needed to restore the same deployment.

A documented restore test is part of release readiness. A backup process that has never been restored successfully is not considered complete.

## 18. Configuration

Configuration layers:

1. deployment environment configuration;
2. workspace settings;
3. project defaults;
4. crawl-run snapshot.

Every crawl stores the effective configuration snapshot so historical results remain interpretable after settings change.

Environment variables must be validated at startup with actionable errors.

## 19. Testing strategy

### Unit tests

High priority for:

- URL normalization;
- scope/include/exclude decisions;
- robots/meta directive parsing;
- canonical resolution;
- issue rules;
- crawl budget behavior;
- retry policy.

### Integration tests

Use real PostgreSQL and Redis containers for:

- migrations;
- repository queries;
- durable job behavior;
- worker crash/retry flows;
- crawl-run state transitions.

### Crawler fixtures

Maintain a deterministic local fixture website covering:

- redirects and loops;
- canonicals;
- robots directives;
- sitemaps;
- duplicate metadata;
- broken links;
- slow responses;
- large pages;
- malformed HTML;
- JavaScript-rendered content.

Tests should crawl the fixture locally rather than depend on the public internet.

### End-to-end tests

Core flows:

- sign in;
- create project;
- start crawl;
- observe progress;
- inspect issue;
- filter URLs;
- compare runs;
- export CSV.

## 20. Deployment model

Initial supported production deployment is Docker Compose on one Linux server.

Containers:

- `web`;
- `worker`;
- `postgres`;
- `redis`;
- optional `renderer` if separated;
- optional reverse proxy profile.

Persistent volumes:

- database;
- application artifacts;
- optional Redis persistence if configured.

Reverse proxy/TLS can be provided through a documented Caddy or Nginx profile, while allowing operators to use an existing proxy.

## 21. Upgrade strategy

Releases are versioned and include:

- application images;
- database migrations;
- release notes;
- backup warning when migrations are destructive/high risk;
- offline image bundle instructions.

Upgrade scripts should perform preflight checks such as free disk space, current schema version, configuration validation, and required image availability.

Avoid automatic destructive migrations at container startup without clear operator visibility.

## 22. Architectural boundaries

The following dependencies are allowed conceptually:

```text
web -> domain/application services -> repositories
worker -> crawler/analyzer/application services -> repositories
crawler -> domain + fetch/parser abstractions
analyzer -> domain observations (not HTTP)
queue -> job contracts
storage -> artifact interface
repositories -> database adapter
```

The analyzer must not fetch the network. The web UI must not contain crawl logic. The crawler must not know presentation concerns. Queue payloads should contain stable identifiers rather than huge raw data blobs.

## 23. Implementation sequencing

The implementation plan should build vertical slices rather than all infrastructure first.

Recommended sequence:

1. repository/tooling baseline;
2. local Compose dependencies and configuration validation;
3. database schema + migrations;
4. authentication + project creation;
5. durable job framework;
6. minimal local fixture crawler producing observations;
7. crawl UI/progress;
8. first issue rules and issue explorer;
9. URL explorer and filtering;
10. link graph and sitemap support;
11. crawl comparison;
12. rendering policy;
13. exports;
14. backup/restore and offline release packaging;
15. hardening, performance, and release validation.

Each vertical slice should be testable before the next one begins.

## 24. Key decisions recorded

- **Architecture:** modular monolith + separate worker processes.
- **Core language:** TypeScript for web and worker to minimize operational/tooling complexity.
- **Database:** PostgreSQL.
- **Queue/cache coordination:** Redis.
- **Deployment:** single-server Docker Compose first.
- **Rendering:** optional/targeted Playwright, not default.
- **URL limits:** no product hard cap; configurable operational budgets.
- **History:** immutable crawl-run observations.
- **Connectivity:** core functionality has no mandatory external SaaS dependency.
- **Offline operations:** release bundles must support loading Docker images without registry access.
- **Testing:** local deterministic crawler fixtures are mandatory.

## 25. Non-negotiable release gates

A release is not considered production-ready unless:

- installation works from documented steps on a clean Linux host;
- an offline image bundle installation has been tested;
- database backup and restore have been tested;
- a worker restart during a crawl does not corrupt data;
- crawling over the former 10,000-URL concern is proven by a controlled load test;
- the UI remains responsive with a large URL table through server-side queries;
- no runtime page assets depend on public CDNs;
- core tests run without public internet access;
- SSRF protections have dedicated tests;
- migrations can be run predictably and their failure mode is documented.

## 26. Definition of the first usable milestone

The first usable milestone is complete when an operator can install SEO OS, sign in, create a project pointing at the included fixture site or a reachable real site, run a durable crawl, see live progress, inspect normalized URL observations, review a useful initial set of technical SEO issues with evidence, and restart the server without losing the crawl history.

This milestone intentionally prioritizes trustworthiness and recoverability over breadth of integrations.