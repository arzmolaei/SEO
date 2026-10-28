# SEO OS — Product & Architecture Design

> **ARCHIVED — historical context only, not a roadmap.** This design described a commercial multi-client/team SEO platform. The product is now a personal, single-owner SEO workspace; scope is defined solely by `docs/OWNER_INTENT.md` and `docs/DELIVERY_PLAN.md`. Do not build anything from this document that those files do not list.


Date: 2026-10-22
Status: Approved-by-delegation (user delegated product/technical decisions to agent)

## 1. Product Vision

SEO OS is a serious, commercial-grade SEO operations platform for freelancers managing multiple clients and small SEO teams (2–10 people). The first deployment targets the user's own workflows, while architecture remains ready for future SaaS commercialization and self-hosted distribution.

The product is not a spreadsheet replacement, not a single-purpose crawler, and not an AI wrapper. It is a unified SEO operating system that connects research, keyword strategy, site architecture, content, technical SEO, execution, monitoring, and next-action prioritization.

Core value proposition:

**Raw SEO data → Clean structured data → Intelligence → Decisions → Execution → Measurement → Improvement**

## 2. Product Principles

1. **Workflow-first UX, intelligence-first data model.**
2. **Core features remain usable without international internet access.**
3. **AI is optional enhancement, never a critical dependency.**
4. **Every recommendation is explainable.**
5. **Every destructive/bulk change is traceable and, where practical, reversible.**
6. **No artificial architecture ceilings for URL, keyword, or project counts.**
7. **Self-hosting is a first-class deployment target.**
8. **SaaS-readiness is designed in, not implemented prematurely.**
9. **Modern UI is functional: fewer decisions, fewer clicks, better information density.**
10. **Modular boundaries prevent future feature growth from turning into a monolith mess.**

## 3. Target Users

Primary:
- Freelance SEO specialist with multiple client sites.
- Small SEO agency/team with 2–10 members.

Future:
- In-house SEO teams.
- SaaS customers.

## 4. Deployment Strategy

### 4.1 Product model
Hybrid-ready:
- Self-hosted first-class installation.
- Future hosted SaaS edition.

### 4.2 Initial production stack
- Laravel 13
- React
- Inertia
- TypeScript
- Vite
- MariaDB 10.11+ preferred
- MySQL 8+ supported
- PHP 8.3+

### 4.3 Production requirements
- HTTPS
- Writable application storage
- Cron/scheduler capability
- SQL database
- No Node.js runtime requirement in production
- No required external CDN

### 4.4 Architecture style
**Modular Monolith** initially.

Modules communicate through explicit application services/contracts rather than reaching into one another's internals.

Modules:
- Core Platform
- Workspaces & Users
- Projects
- Keywords
- Pages
- Crawling
- Technical SEO
- Content
- Internal Linking
- Tasks
- Performance
- Integrations
- Intelligence
- Reporting
- System Operations

## 5. Core Domain Model

Hierarchy:

- User
- Workspace
  - Membership
  - Project
    - Site
      - Page
      - Crawl
      - CrawlPageSnapshot
      - TechnicalIssue
    - Keyword
      - KeywordMetric
      - KeywordSource
      - KeywordIntent
      - KeywordClusterMembership
    - Cluster
      - TargetMapping
    - PlannedPage
    - ContentItem
    - InternalLinkOpportunity
    - Task
    - PerformanceSnapshot
    - IntegrationConnection
    - Insight
    - Recommendation
    - ActivityEvent
    - ChangeSet

## 6. Project Workspace

Each project acts as a persistent SEO workspace.

Persistent project shell:
- Project selector
- Global/project search
- Command palette
- Project health/status
- Last crawl/sync indicators
- Guided/Expert mode toggle
- Sidebar navigation

Primary sections:
- Overview / Command Center
- Keywords
- Pages
- Site Audit
- Architecture
- Content
- Internal Links
- Tasks
- Performance
- Reports
- Integrations
- Project Settings

## 7. Guided Mode

Guided mode presents an opinionated SEO workflow:

1. Project setup
2. Site discovery
3. Technical baseline
4. Keyword import/research
5. Cleaning
6. Intent review
7. Clustering
8. Mapping
9. Architecture planning
10. Prioritization
11. Content planning
12. On-page execution
13. Internal linking
14. Publishing
15. Indexing
16. Performance monitoring
17. Continuous optimization

Guided mode never blocks Expert mode access.

## 8. Expert Mode

Experts can enter any module at any time. Guided state still updates in the background so the system understands progress without forcing sequence.

## 9. Command Center

The project home page answers:

**"What should I work on next?"**

It prioritizes action-worthy items only.

Examples:
- Unmapped high-value clusters
- High-impression/low-CTR pages
- New technical regressions
- Declining pages
- Crawl changes
- Important tasks overdue
- Content ready for publishing
- Pages published but not indexed

Each card links directly to the corresponding workflow.

## 10. Keyword Intelligence

### 10.1 Keyword lifecycle
Import → Staging → Normalize → Deduplicate → Enrich → Review → Cluster → Map → Prioritize → Ready

### 10.2 Import sources
- Paste
- CSV
- XLSX
- KWFinder export
- Ahrefs export
- Semrush export
- Google Keyword Planner export
- Generic custom mapping

### 10.3 Import pipeline
Raw files are staged before committing to core tables.

Staging validates:
- Required columns
- Data types
- Duplicate rows
- Invalid metrics
- Encoding
- Empty keywords
- Conflicting mappings

Every import becomes an ImportBatch with provenance, counts, warnings, original file, and mapping template.

### 10.4 Normalization
Keep both original and normalized forms.

Persian normalization covers:
- Arabic/Persian Yeh variants
- Arabic/Persian Kaf variants
- whitespace normalization
- zero-width and half-space handling
- punctuation cleanup
- Unicode normalization

Normalization must never destroy original user data.

### 10.5 Metrics
Metrics are time/source-aware and not overwritten.

KeywordMetric:
- source
- captured_at
- volume
- difficulty
- CPC
- competition
- trend metadata

### 10.6 Data grid
Professional data grid with:
- server-side filtering
- server-side sorting
- pagination/keyset pagination where appropriate
- virtualization
- column manager
- frozen columns
- inline editing
- bulk operations
- keyboard navigation
- saved views
- filter builder
- tags
- custom fields

### 10.7 Saved views
Examples:
- Quick Wins
- Needs Review
- High Commercial Value
- Unmapped
- Cannibalization Candidates
- Missing Metrics

## 11. Intent Engine

Supported intents:
- Informational
- Commercial
- Transactional
- Navigational
- Local
- Mixed
- Unknown

Rule-based classification first.

Confidence policy:
- High: may auto-apply when configured
- Medium: suggestion
- Low: review queue

All user overrides are recorded.

## 12. Clustering Engine

Three-stage design:
1. Rule/lexical/feature-based clustering
2. Optional SERP-overlap verification
3. Optional AI assistance

Cluster data:
- name
- primary keyword
- member keywords
- intent
- suggested page type
- business relevance
- search demand summary
- difficulty summary
- confidence
- review state

Operations:
- split
- merge
- move keywords
- recalculate
- lock cluster

## 13. Review Queue

Cross-module review center for uncertain decisions:
- duplicate candidates
- uncertain intent
- uncertain cluster
- ambiguous mapping
- cannibalization candidates
- suspicious imports
- conflicting page targets

Review UX prioritizes keyboard speed and batch decisions.

## 14. Keyword Mapping & SEO Targeting

Cluster → Target relation can point to:
- Existing Page
- Planned Page
- Merge target
- Redirect target
- Ignore/no target

Mapping warnings:
- Multiple pages for same intent
- One page overloaded with unrelated clusters
- High-value cluster without target
- Target page has technical blockers

Output is an actionable SEO Targeting Plan, not merely sorted keywords.

## 15. Page Intelligence

Page is first-class entity.

Fields include:
- URL
- normalized URL
- type
- status/indexability
- canonical
- title/meta
- headings
- content status
- target cluster
- primary/secondary keywords
- current technical state
- last crawl
- performance state

Current state is derived from the latest crawl snapshot; historical snapshots are retained.

## 16. Site Intelligence & Crawler

### 16.1 Crawler architecture
Web App → Job Queue → Crawler Worker(s) → Crawl Storage → Intelligence Layer

### 16.2 Discovery
- Homepage seeds
- sitemap.xml
- robots.txt
- internal links
- user-provided URL lists

### 16.3 Crawl modes
- Entire site
- Sitemap only
- Specific path
- URL list
- Include/exclude patterns
- Maximum depth
- Sample crawl

### 16.4 Extraction
- HTTP status
- redirects
- title/meta
- H1/H2
- canonical
- robots directives
- hreflang
- structured data summary
- word count
- images/alt
- internal links
- external links
- URL depth

### 16.5 JavaScript rendering
Normal crawling is HTTP-first. JS rendering is an optional separate mode using a browser-capable worker when required.

### 16.6 Scale behavior
No hard architecture limit.

Crawler supports:
- batching
- checkpoints
- pause/resume
- retries
- rate limiting
- concurrency limits
- graceful degradation

Capacity depends on resources, not hard-coded URL count.

## 17. Crawl History & Diff

Every crawl stores snapshots and produces a change set.

Detected changes include:
- new URLs
- removed URLs
- status changes
- title/meta changes
- canonical changes
- indexability changes
- internal link changes
- new/removed technical issues

## 18. Technical SEO

Issue engine groups findings by issue type and affected pages.

Issue fields:
- severity
- confidence
- impact
- first_seen
- last_seen
- status
- affected entities
- explanation
- suggested action
- assignee/task link

Initial checks:
- 4xx/5xx
- redirect chains/loops
- duplicate/missing titles
- duplicate/missing descriptions
- canonical issues
- robots/noindex issues
- heading problems
- orphan candidates
- broken internal links
- excessive crawl depth
- sitemap conflicts
- image/alt issues
- structured-data warnings

## 19. Site Architecture

Site graph connects pages, clusters, and planned pages.

Visual views:
- tree/hierarchy
- topical map
- orphan view
- depth view
- planned vs existing

Planned pages exist before publication and participate in mapping/content/task flows.

## 20. Internal Linking

Opportunities are generated from:
- cluster/topic relationships
- page hierarchy
- existing links
- depth
- strategic priority
- page performance

Opportunity fields:
- source page
- target page
- rationale
- suggested anchor
- confidence
- status

## 21. Content System

Pipeline:
Idea → Research → Brief → Writing → SEO Review → Approval → Scheduled → Published → Indexed → Monitoring → Refresh

Each content item links to:
- target cluster
- page/planned page
- tasks
- internal-link plan
- performance

## 22. Content Briefs

Briefs include:
- search intent
- primary keyword
- supporting topics
- heading suggestions
- questions
- metadata targets
- internal-link targets
- CTA notes
- competitive notes
- custom requirements

AI generation is optional.

## 23. On-Page Workspace

Per-page workspace presents:
- target strategy
- current state
- recommended state
- title/meta/H1
- heading outline
- internal-link plan
- schema notes
- media issues
- technical blockers
- content status
- task checklist

## 24. CMS Integrations

Future connectors:
- WordPress
- WooCommerce
- generic REST CMS

Sensitive writes follow:
Preview → Diff → Approval → Publish

No automatic destructive publish by default.

## 25. Performance & Search Console

Search Console is the first major performance integration.

Persisted data:
- query
- page
- clicks
- impressions
- CTR
- average position
- country/device where useful
- date window

Data sync is incremental and resumable.

## 26. Opportunity Engine

Inputs:
- keyword value
- rankings/performance
- technical health
- mapped targets
- content status
- business relevance
- effort

Outputs explainable recommendations such as:
- optimize existing page
- create missing page
- improve CTR
- consolidate cannibalizing pages
- add internal links
- refresh declining content

## 27. Prioritization Engine

Score components:
- business value
- relevance
- search demand
- difficulty
- current rank
- authority/context
- conversion potential
- required effort
- technical health
- strategic importance

Weights are configurable and every score is explainable.

## 28. Task System

Internal SEO task manager supports:
- list
- board
- calendar

Task fields:
- project
- related entity
- type
- priority
- status
- due date
- assignee
- checklist
- dependencies
- notes

Not intended to replicate full Jira complexity.

## 29. Activity, Audit & Undo

Separate systems:

Activity log:
- user-friendly timeline of actions

Audit/change history:
- before/after data where appropriate
- change-set grouping
- source/action metadata

Bulk operations create ChangeSets and support undo when technically safe.

## 30. Global Search & Command Palette

Global search covers:
- project
- keyword
- cluster
- page
- task
- issue
- content

Command palette (Cmd/Ctrl+K) supports navigation and common actions.

## 31. Notifications

Only actionable notifications:
- crawl completed/failed
- major regression
- import finished/failed
- backup failure
- integration auth expired
- overdue critical tasks
- update available

## 32. Integration Architecture

Integrations are adapters, never core dependencies.

States:
- Connected
- Syncing
- Degraded
- Offline
- Auth Expired
- Retry Pending

Core must continue operating when external integrations are unavailable.

## 33. Iran-Resilient Operation

Core application avoids required international dependencies.

Local/self-hosted:
- fonts
- icons
- JS/CSS
- authentication
- core processing
- stored reports
- keyword workflows
- task workflows
- crawler for reachable sites

External services are optional enhancements.

## 34. UI/UX System

### 34.1 Language
- Persian default initially
- English support built into the foundation
- RTL/LTR first-class from day one

### 34.2 Design principles
- modern, calm, professional
- dense where data-heavy
- minimal visual noise
- strong information hierarchy
- fast keyboard flows
- inline editing
- drawers over excessive modals
- meaningful animation only

### 34.3 Themes
- Light
- Dark

Both designed intentionally, not simple inversion.

### 34.4 Accessibility
- keyboard navigation
- visible focus
- semantic markup
- sufficient contrast
- screen reader labels
- reduced-motion support

### 34.5 Design tokens
- color
- typography
- spacing
- radius
- shadow
- motion
- z-index
- breakpoints

## 35. System Operations

### 35.1 Installer
Self-host flow:
Upload release package → /install → environment checks → database → admin → app settings → cron setup → install → health check

No CLI required for normal installation.

### 35.2 Requirements checker
Checks:
- PHP version/extensions
- DB version/connection
- writable storage
- cron/scheduler
- disk/memory
- upload limits
- rewrite support
- SSL
- queue capability

### 35.3 Health center
Monitors:
- application
- DB
- queue
- scheduler
- storage
- disk
- backup
- crawler
- integrations
- email
- update system

### 35.4 Backups
Backup manifest contains:
- app version
- schema version
- database
- uploaded files
- settings
- checksums

Manual and scheduled backups supported.

### 35.5 Restore
Restore validates compatibility before applying.

### 35.6 Migration/Transfer
Old install: Create Transfer Package
New install: Restore Existing Installation

Transfers:
- projects
- users
- settings
- data
- files

Sensitive integrations may require re-authentication.

### 35.7 Update system
Update flow:
Compatibility check → backup → maintenance → files → migrations → cache refresh → health tests → finish

Supports rollback and manual update-package upload.

Release channels:
- Stable
- Beta
- Development

## 36. Logging

Separate logs:
- application
- errors
- audit
- background jobs
- integration sync
- crawler

Logs are visible in System Health with redaction of sensitive values.

## 37. Security

Baseline:
- CSRF protection
- XSS-safe rendering
- parameterized ORM/query usage
- rate limiting
- strong password hashing
- session hardening
- permissions
- secure upload validation
- encryption for sensitive values
- backup protection
- secret separation from repository
- audit trail

## 38. Users, Roles & Teams

Data model supports:
- User
- Workspace
- Membership
- Role
- ProjectAccess

Initial roles:
- Owner
- Admin
- SEO Manager
- SEO Specialist
- Content Writer
- Viewer

First release may expose only a subset while preserving the model.

## 39. SaaS Readiness

Do not implement billing in v1, but provide domain boundaries for:
- plans
- entitlements
- usage metering
- subscriptions
- feature limits

Commercial limits must be configuration, not architecture ceilings.

## 40. Queue Strategy

Default self-hosted mode:
- database-backed queue

Optional advanced mode:
- Redis-backed queues
- dedicated workers

Feature code depends on queue abstraction, not queue implementation.

## 41. Search Strategy

Initial:
- indexed SQL search
- optimized server filtering

Future:
- SearchProvider abstraction for Typesense/OpenSearch etc.

No external search service required in v1.

## 42. Storage Strategy

Default:
- local filesystem storage

Future adapters:
- S3-compatible
- MinIO
- other object storage

## 43. Performance Strategy

Large datasets are handled through:
- server-side filtering
- pagination/keyset pagination where appropriate
- DB indexes
- background processing
- streaming/chunked imports
- UI virtualization
- caching
- lazy loading

No full-dataset browser rendering.

## 44. Reports

Reports derive from live project intelligence, not static templates.

Types:
- executive
- technical
- keyword
- content
- growth
- monthly SEO
- custom

Exports:
- PDF
- CSV
- XLSX

## 45. Out of Scope for First Major Build

- Global backlink index
- Full Ahrefs/Semrush replacement
- Global rank database
- Full AI writing suite
- Native mobile app
- Microservices
- Hundreds of integrations

Architecture must leave room for these without requiring core rewrites.

## 46. Build Order

### Phase A — Foundation
- repo & engineering standards
- Laravel/React/Inertia foundation
- design system
- installer
- auth
- workspace/project model
- settings
- health/logging skeleton

### Phase B — SEO Core
- keyword import/staging
- normalization/dedup
- keyword grid
- views/filters
- intents
- clustering
- review queue
- mapping
- priority engine

### Phase C — Site Intelligence
- page model
- crawler
- crawl history
- issue engine
- architecture views

### Phase D — Execution
- tasks
- content pipeline
- briefs
- on-page workspace
- internal-link opportunities

### Phase E — Performance
- GSC integration
- performance history
- opportunity engine
- recommendation engine

### Phase F — Operations
- backup/restore
- transfer
- update system
- advanced permissions
- team UX

### Phase G — Expansion
- CMS integrations
- SERP verification
- optional AI
- advanced reporting
- SaaS commercialization

## 47. Definition of Done

A feature is not complete merely when the happy path works.

Required where applicable:
- primary UX
- loading state
- empty state
- error state
- validation
- permission checks
- logs
- automated tests
- responsive layout
- RTL/LTR correctness
- accessibility baseline
- performance review
- safe migration
- documentation
- observability

## 48. First Implementation Scope

The first implementation cycle focuses on a coherent vertical slice:

1. Platform shell
2. Installer foundations
3. Authentication
4. Workspace/Project creation
5. Project shell/navigation
6. Keyword import staging
7. Persian-safe normalization
8. Deduplication
9. Keyword storage/provenance
10. High-performance keyword grid
11. Saved views/filters
12. Activity/change tracking for keyword operations

This slice must be production-quality and will establish patterns for later modules.
