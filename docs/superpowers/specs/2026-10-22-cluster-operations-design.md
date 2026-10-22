# Cluster operations — Task 6

Approved by delegation; Task 5 head 9fb5890 passed CI 39799527623.
Manual services provide merge/split/move/lock, never raw bulk SQL mutations.
Project-first serialization then sorted keyword/cluster/member locking matches
Task 5. Locked clusters require explicit unlock before membership edits.

Moving/splitting a primary requires an explicit replacement among remaining
members. A split uses the existing transactional cluster creation service. Merge
archives empty sources instead of deleting their identity/history. Only active
clusters require primary membership; archived clusters retain historical primary
references. Model guards continue to protect active primary removal/reassignment.
All user membership edits invalidate automation reuse, mark review pending and
retain one current membership per keyword. No implicit primary selection.

Each operation captures full affected cluster/member states and increments a
revision. Changesets/activities retain before/after data and actor. Same-value
lock changes are no-ops. Undo compares exact post-operation state, including
revision and member identity; any intervening change or repeated undo conflicts.
Restore runs atomically: temporarily archive affected clusters, restore member
rows through models, restore prior cluster states with fresh revisions, and
remove split-created empty clusters. No partial undo escapes a failure.

Future mapping services must increment cluster revision when their relationships
change, so Undo cannot erase subsequently attached mapping decisions. No merge,
split or mapping UI in this task. Verify actual MariaDB constraints and the full
permanent gates before advancing to Task 7.
