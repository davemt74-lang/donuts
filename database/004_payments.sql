CREATE TABLE IF NOT EXISTS payment_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider VARCHAR(32) NOT NULL,
  provider_event_id VARCHAR(190) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  payload TEXT NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE(provider,provider_event_id)
);
CREATE TABLE IF NOT EXISTS payment_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_token VARCHAR(64) NOT NULL UNIQUE,
  user_id INTEGER NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  currency VARCHAR(3) NOT NULL DEFAULT 'usd',
  amount_cents INTEGER NOT NULL CHECK(amount_cents>=0),
  provider VARCHAR(32) NOT NULL DEFAULT 'stripe',
  provider_session_id VARCHAR(190) NULL UNIQUE,
  idempotency_key VARCHAR(190) NOT NULL UNIQUE,
  snapshot TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
