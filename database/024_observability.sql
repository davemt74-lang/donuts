CREATE TABLE IF NOT EXISTS operational_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  severity VARCHAR(16) NOT NULL CHECK(severity IN ('info','warning','error','critical')),
  event_type VARCHAR(80) NOT NULL,
  fingerprint VARCHAR(64) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  context_json TEXT NOT NULL DEFAULT '',
  occurrences INTEGER NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by INTEGER NULL,
  FOREIGN KEY(resolved_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_operational_events_open ON operational_events(resolved_at,severity,last_seen_at DESC);
CREATE INDEX IF NOT EXISTS idx_operational_events_fingerprint ON operational_events(fingerprint,resolved_at);
