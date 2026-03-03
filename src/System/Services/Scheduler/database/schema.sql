-- Recommended DB tables if the application wants DB-backed schedules/runs.
-- Apps decide schema and may rename anything.

CREATE TABLE schedules (
  id VARCHAR(64) PRIMARY KEY,
  name TEXT NOT NULL,
  cron VARCHAR(64) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  enabled INTEGER NOT NULL DEFAULT 1,
  meta TEXT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE schedule_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schedule_id VARCHAR(64) NOT NULL,
  schedule_name TEXT NOT NULL,
  started_at INTEGER NOT NULL,
  finished_at INTEGER NULL,
  status VARCHAR(16) NOT NULL,
  error_class TEXT NULL,
  error_message TEXT NULL,
  meta TEXT NULL
);

CREATE INDEX idx_schedule_runs_schedule_id ON schedule_runs(schedule_id);
