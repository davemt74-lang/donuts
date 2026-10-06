CREATE TABLE IF NOT EXISTS auth_rate_limits (
  scope VARCHAR(64) NOT NULL,
  subject_hash VARCHAR(64) NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until DATETIME NULL,
  PRIMARY KEY(scope,subject_hash)
);
