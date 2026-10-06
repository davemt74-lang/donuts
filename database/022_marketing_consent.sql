CREATE TABLE IF NOT EXISTS newsletter_consent_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subscriber_id INTEGER NOT NULL,
  action VARCHAR(24) NOT NULL CHECK(action IN ('subscribe','unsubscribe')),
  source VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_newsletter_consent_events_subscriber ON newsletter_consent_events(subscriber_id,created_at);
