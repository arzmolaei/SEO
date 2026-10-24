# Task 10 prerequisite — exact remaining rollback proof

Base: active Update/Rollback at 1a016f93. The Task 9/recovery review independently confirmed an Important gap in the Task 8 reverse runner: missing data-only migration history can match a shorter recorded prefix while schema remains unchanged. Eventual snapshot application does not authorize guessing past unexpected repository drift.

Require the caller's recorded remaining state to match exactly unless a durable completed-attempt marker proves reconciliation. Actual recovery callers publish each reverse checkpoint to the journal before clearing that marker. A callback-free caller interrupted after evidence cleanup may require manual recovery; it must not silently infer a down from unchanged DDL.

Tests first on SQLite and connected MariaDB: apply two data-only increments, delete the last migration log without executing down, and require recovery without executing any other down or changing remaining history/data. Existing completed-marker interruption and exact checkpoint tests remain permanent regressions. No broad rollback and no second lifecycle stack. Final exact-head CI/review remain required before integration; full Task10 coordinator is still pending.


Correct observed RED: 43a0c3dd / CI40060229919. SQLite regression failed because the runner accepted the missing row; connected MariaDB also failed the same assertion while eight existing connected cases passed. Minimal fix refuses scanning a shorter prefix when no durable completed-attempt marker exists. A caller that persists each verified remaining checkpoint still resumes normally. Final-head CI and review remain required.
