CREATE TABLE IF NOT EXISTS customer_privacy_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL,
  email_hash VARCHAR(64) NOT NULL,
  action VARCHAR(32) NOT NULL CHECK(action IN ('data_export','account_closed')),
  details TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_customer_privacy_events_hash ON customer_privacy_events(email_hash,created_at);
