CREATE TABLE IF NOT EXISTS order_fulfillment_details (
  order_id INTEGER PRIMARY KEY,
  carrier VARCHAR(80) NOT NULL DEFAULT '',
  tracking_number VARCHAR(190) NOT NULL DEFAULT '',
  tracking_url VARCHAR(500) NOT NULL DEFAULT '',
  pickup_instructions TEXT NOT NULL DEFAULT '',
  pickup_ready_at DATETIME NULL,
  shipped_at DATETIME NULL,
  delivered_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);
