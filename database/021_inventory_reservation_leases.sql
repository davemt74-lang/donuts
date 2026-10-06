CREATE TABLE IF NOT EXISTS inventory_reservation_leases (
  order_id INTEGER PRIMARY KEY,
  expires_at DATETIME NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active' CHECK(status IN ('active','released','committed','review_hold')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_inventory_lease_expiry ON inventory_reservation_leases(status,expires_at);
