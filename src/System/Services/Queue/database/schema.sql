-- Recommended schema for DatabaseQueue adapter (app-owned).

CREATE TABLE jobs (
  id VARCHAR(64) PRIMARY KEY,
  queue VARCHAR(64) NOT NULL,
  payload TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  available_at INTEGER NOT NULL,
  reserved_at INTEGER NULL,
  visibility_timeout INTEGER NULL,
  created_at INTEGER NOT NULL
);

CREATE INDEX idx_jobs_queue_available ON jobs(queue, available_at);
CREATE INDEX idx_jobs_vt ON jobs(visibility_timeout);

CREATE TABLE failed_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id VARCHAR(64) NOT NULL,
  queue VARCHAR(64) NOT NULL,
  payload TEXT NOT NULL,
  attempts INTEGER NOT NULL,
  error_class TEXT NOT NULL,
  error_message TEXT NOT NULL,
  error_stack TEXT NOT NULL,
  failed_at INTEGER NOT NULL
);

CREATE INDEX idx_failed_jobs_job_id ON failed_jobs(job_id);
