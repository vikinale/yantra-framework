-- Recommended DB tables for import tracking (app-owned).

CREATE TABLE imports (
  id VARCHAR(64) PRIMARY KEY,
  source_path TEXT NOT NULL,
  status VARCHAR(32) NOT NULL,
  total_rows INTEGER NOT NULL DEFAULT 0,
  processed_rows INTEGER NOT NULL DEFAULT 0,
  failed_rows INTEGER NOT NULL DEFAULT 0,
  meta TEXT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE import_rows (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  import_id VARCHAR(64) NOT NULL,
  row_number INTEGER NOT NULL,
  status VARCHAR(32) NOT NULL,
  meta TEXT NULL
);

CREATE TABLE import_errors (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  import_id VARCHAR(64) NOT NULL,
  row_number INTEGER NOT NULL,
  field TEXT NOT NULL,
  message TEXT NOT NULL,
  meta TEXT NULL,
  created_at INTEGER NOT NULL
);
