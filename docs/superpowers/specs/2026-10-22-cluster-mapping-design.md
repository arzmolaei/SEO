# Cluster mapping — Task 8

Approved by delegation after Task 7 final-head CI 39803137862 passed.
One current ClusterTarget per active cluster, with existing_page, planned_page,
merge_target, redirect_target or ignore intent. Non-ignore targets require a
same-project Page; existing/merge/redirect need an existing URL-bearing page.
Ignore requires no page. No automatic remapping or destructive redirect execution.

Each remap preserves before/after in ChangeSet and Activity; identical requests
are no-ops. Authorization is checked before data lookup. Project-first serialized
transactions retain scope, row locks, source and actor. Every target change bumps
cluster revision, so earlier membership Undo cannot erase subsequent targeting.
Composite FKs protect project alignment and mapped-page deletion.

Promoting a planned page keeps page and mapping identities. A synchronous domain
event invokes the mapping application service to convert active planned targets
to existing targets atomically. Archived historical cluster targets remain
historical, but their cluster revisions advance to invalidate stale Undo.
Page services do not reach into mapping models directly. This does not implement
crawler, redirects, page publishing or mapping conflict detection (Task 9).
