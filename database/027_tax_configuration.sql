CREATE TABLE IF NOT EXISTS tax_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS order_tax_details (
  order_id INTEGER PRIMARY KEY,
  automatic_tax_enabled INTEGER NOT NULL DEFAULT 1,
  product_tax_code VARCHAR(64) NOT NULL DEFAULT '',
  tax_behavior VARCHAR(16) NOT NULL DEFAULT 'exclusive',
  stripe_tax_cents INTEGER NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO tax_settings(setting_key,setting_value) VALUES
 ('automatic_tax_enabled','1'),
 ('product_tax_code',''),
 ('checkout_notice','Tax is calculated securely at checkout based on your delivery address.'),
 ('tax_behavior','exclusive');
