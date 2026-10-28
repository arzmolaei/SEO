# Tasks (V1 slice 5)

Status: approved by delegation (docs/OWNER_INTENT.md item 5, docs/DELIVERY_PLAN.md slice 5).

## Goal

A simple to-do list for the owner's SEO work in a project. A task can be linked
to a page or a cluster, has an optional due date and notes, and a status
(to do → doing → done). Single user: no assignment, reviewers or notifications.

## Data

New table `project_tasks` (in the backup catalog, its guard tests and the
connected backup/restore fixture): `project_id`, optional `page_id` and
`keyword_cluster_id` (`nullOnDelete`: removing a page or cluster keeps the task),
`title`, `notes`, `status` (`todo|doing|done`), `due_on`, `completed_at`,
`created_by_id`. Indexed for the filtered list.

## Behaviour

- Links must belong to the same project (validated in `ProjectTasks`).
- Marking done records `completed_at`; editing a done task keeps it; reopening
  clears it.
- Create, update (only when something changed) and delete each write a change set
  with before/after snapshots, so a deleted task is recoverable from history.
- List (`/projects/{project}/tasks`): server-side paginated (25) with views open
  (default), overdue, done, all, a search, and filters by page or cluster. Open
  tasks are ordered by due date (undated last); done tasks by completion. Counts
  per view. When the list is filtered to a page or cluster, new tasks link to it.
- Entry points: "Tasks" in the project navigation and "tasks for this page" on
  each page.
- Viewers read only; other projects' tasks are 404 through scoped bindings.

## Out of scope

Assignment, reminders/notifications, recurring tasks, sub-tasks.
