CREATE TABLE IF NOT EXISTS order_payment_reconciliation (
  order_id INTEGER PRIMARY KEY,
  stripe_session_id VARCHAR(190) NOT NULL,
  expected_pre_tax_cents INTEGER NOT NULL,
  stripe_subtotal_cents INTEGER NOT NULL,
  stripe_tax_cents INTEGER NOT NULL,
  stripe_total_cents INTEGER NOT NULL,
  currency VARCHAR(3) NOT NULL,
  status VARCHAR(24) NOT NULL CHECK(status IN ('matched','mismatch')),
  details TEXT NOT NULL DEFAULT '',
  reconciled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payment_reconciliation_status ON order_payment_reconciliation(status);
