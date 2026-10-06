ALTER TABLE flavors ADD COLUMN unit_cost_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE pack_sizes ADD COLUMN packaging_cost_cents INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS order_cost_snapshots (
  order_id INTEGER PRIMARY KEY,
  product_cost_cents INTEGER NOT NULL DEFAULT 0,
  packaging_cost_cents INTEGER NOT NULL DEFAULT 0,
  total_cost_cents INTEGER NOT NULL DEFAULT 0,
  revenue_basis_cents INTEGER NOT NULL DEFAULT 0,
  gross_margin_cents INTEGER NOT NULL DEFAULT 0,
  margin_percent REAL NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_cost_margin ON order_cost_snapshots(created_at,gross_margin_cents);
