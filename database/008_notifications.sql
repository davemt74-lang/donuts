CREATE TABLE IF NOT EXISTS notification_outbox (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel VARCHAR(32) NOT NULL DEFAULT 'email',
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  idempotency_key VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  attempts INTEGER NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error TEXT NOT NULL DEFAULT '',
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_outbox_status ON notification_outbox(status,next_attempt_at);
