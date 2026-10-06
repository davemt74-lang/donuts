CREATE TABLE IF NOT EXISTS loyalty_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS loyalty_accounts (
  user_id INTEGER PRIMARY KEY,
  points_balance INTEGER NOT NULL DEFAULT 0,
  reserved_points INTEGER NOT NULL DEFAULT 0 CHECK(reserved_points>=0),
  lifetime_earned_points INTEGER NOT NULL DEFAULT 0,
  lifetime_redeemed_points INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_loyalty_redemptions (
  order_id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL,
  reserved_points INTEGER NOT NULL DEFAULT 0,
  redeemed_points INTEGER NOT NULL DEFAULT 0,
  restored_points INTEGER NOT NULL DEFAULT 0,
  discount_cents INTEGER NOT NULL DEFAULT 0,
  earned_points INTEGER NOT NULL DEFAULT 0,
  reversed_points INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'none' CHECK(status IN ('none','reserved','redeemed','released')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_loyalty_user ON order_loyalty_redemptions(user_id,order_id);

CREATE TABLE IF NOT EXISTS loyalty_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  refund_id INTEGER NULL,
  entry_type VARCHAR(32) NOT NULL CHECK(entry_type IN ('earn','redeem','refund_restore','refund_reversal','adjustment')),
  points INTEGER NOT NULL CHECK(points<>0),
  balance_after_points INTEGER NOT NULL,
  event_key VARCHAR(190) NOT NULL UNIQUE,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY(refund_id) REFERENCES refund_records(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_loyalty_ledger_user ON loyalty_ledger(user_id,id DESC);

INSERT OR IGNORE INTO loyalty_settings(setting_key,setting_value) VALUES
 ('enabled','1'),
 ('points_per_dollar','1'),
 ('cents_per_point','1'),
 ('minimum_redeem_points','100'),
 ('maximum_redeem_percent','100');
