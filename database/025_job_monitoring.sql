CREATE TABLE IF NOT EXISTS scheduled_jobs (
  job_key VARCHAR(80) PRIMARY KEY,
  description VARCHAR(190) NOT NULL,
  expected_interval_minutes INTEGER NOT NULL CHECK(expected_interval_minutes>=1),
  last_started_at DATETIME NULL,
  last_succeeded_at DATETIME NULL,
  last_failed_at DATETIME NULL,
  last_duration_ms INTEGER NULL,
  last_message VARCHAR(1000) NOT NULL DEFAULT '',
  consecutive_failures INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS scheduled_job_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_key VARCHAR(80) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'running' CHECK(status IN ('running','succeeded','failed')),
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  duration_ms INTEGER NULL,
  message VARCHAR(1000) NOT NULL DEFAULT '',
  FOREIGN KEY(job_key) REFERENCES scheduled_jobs(job_key) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_scheduled_job_runs_job ON scheduled_job_runs(job_key,id DESC);
CREATE INDEX IF NOT EXISTS idx_scheduled_job_runs_status ON scheduled_job_runs(status,started_at);

INSERT OR IGNORE INTO scheduled_jobs(job_key,description,expected_interval_minutes) VALUES
 ('notifications','Transactional email delivery',5),
 ('reservations','Abandoned checkout reservation recovery',5),
 ('operations','Operational health and alert checks',5),
 ('backup','Verified SQLite database backup',1440);
