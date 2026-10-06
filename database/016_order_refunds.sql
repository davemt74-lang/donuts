ALTER TABLE orders ADD COLUMN stripe_payment_intent_id VARCHAR(190) NULL;

CREATE TABLE IF NOT EXISTS refund_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL CHECK(amount_cents>0),
  reason VARCHAR(255) NOT NULL DEFAULT '',
  provider VARCHAR(32) NOT NULL DEFAULT 'stripe',
  provider_refund_id VARCHAR(190) NULL UNIQUE,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  requested_by_admin_id INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(requested_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS cancellation_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  resolved_by_admin_id INTEGER NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(order_id,status),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(resolved_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
);
