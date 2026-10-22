# Page foundation — Task 7

Approved by delegation. Existing and planned pages share a stable project-scoped
identity. Planned pages require a title and no live URL. Registration preserves
original URL input separately from normalized identity; repeat equivalent URL
returns the existing page without overwriting title or attribution. Promotion
changes kind/URL on the same page ID, preserving future targeting references.

URL normalization is deterministic and local: only HTTP/HTTPS, lower-case IDN
host, remove default port/fragment, default '/' path, preserve path case and query
order. Reject credentials, controls/whitespace, malformed percent encoding and
invalid hosts/ports. Never fetch URL, guess a canonical, equate HTTP with HTTPS,
or remove meaningful query parameters/trailing slashes.

Authorized application services serialize by project and audit page before/after.
Database uniqueness enforces normalized URL per project; planned NULLs allow
multiple draft pages. PageKind represents existing/planned only; no invented
HTTP/indexability health or crawler-sourced fields. Page type starts 'other'.
ChangeSet supports page identifiers; keyword Undo must continue accepting only
validated keyword status changes. Verify creation, equivalent URL reuse,
isolation, promotion identity/conflict rollback and real MariaDB constraints.
