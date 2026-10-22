# Cluster domain increment

Status: approved by delegation. Implements Task 4 of the active Keyword
Intelligence plan. No clustering algorithm or new UI is included in this task.

A cluster belongs to one project and has a non-empty name, supported intent,
confidence, review state (`pending` or `reviewed`), lock flag and primary keyword.
Manual creation starts pending; it does not declare mapping/readiness complete.
Each canonical keyword has at most one current cluster membership. Memberships
have their own identity so future moves can be audited explicitly.

CreateKeywordCluster authorizes at the service boundary, validates all keyword
IDs, requires the primary among the supplied members, locks keywords in ascending
ID order and creates cluster, memberships, ChangeSet and Activity atomically.
An existing membership is rejected; creating a cluster never silently moves a
keyword. Empty names, missing IDs and foreign-project inputs cannot partially
create a cluster. Original keyword data and statuses remain unchanged.

Composite foreign keys enforce project consistency in the database. Primary
keyword deletion is restricted by a foreign key. Eloquent member deletion or
identity reassignment refuses to remove the primary; primary replacement must
already be a member of the same cluster. Domain services are the mutation API;
raw SQL/bulk ORM updates bypass model guards and are not a supported mutation
path. Future merge/split/move services must retain these guards and row locks.

This representation keeps a direct primary keyword reference and explicit member
rows, avoiding cyclic foreign keys that complicate MariaDB cascades. Supporting
member removal and primary replacement operations are delivered in Task 6;
creation and invariant enforcement are the current public application boundary.
Lock state is persisted now and must be respected by future automatic clustering.

RED tests cover attribution, invalid input rollback, unique membership, primary
membership, deletion/reassignment guards, project isolation, viewer rejection,
lock persistence and unchanged keywords. Run full tests/static/style gates and
permanent CI before introducing Task 5.
