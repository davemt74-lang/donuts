CREATE TABLE IF NOT EXISTS checkout_recoveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL,
  email VARCHAR(190) NOT NULL,
  cart_json TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active' CHECK(status IN ('active','recovered','converted','expired')),
  reminder_sent_at DATETIME NULL,
  recovered_at DATETIME NULL,
  linked_order_id INTEGER NULL,
  converted_order_id INTEGER NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY(linked_order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY(converted_order_id) REFERENCES orders(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_checkout_recovery_due ON checkout_recoveries(status,reminder_sent_at,expires_at,created_at);
CREATE INDEX IF NOT EXISTS idx_checkout_recovery_email ON checkout_recoveries(email,updated_at DESC);

INSERT OR IGNORE INTO scheduled_jobs(job_key,description,expected_interval_minutes)
VALUES('checkout-recovery','Consent-safe abandoned checkout recovery',60);
