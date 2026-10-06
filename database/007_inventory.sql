CREATE TABLE IF NOT EXISTS flavor_inventory (
  flavor_id INTEGER PRIMARY KEY,
  track_inventory INTEGER NOT NULL DEFAULT 0,
  stock_on_hand INTEGER NOT NULL DEFAULT 0 CHECK(stock_on_hand>=0),
  reserved INTEGER NOT NULL DEFAULT 0 CHECK(reserved>=0),
  low_stock_threshold INTEGER NOT NULL DEFAULT 6 CHECK(low_stock_threshold>=0),
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE CASCADE
);
INSERT OR IGNORE INTO flavor_inventory(flavor_id,track_inventory,stock_on_hand,reserved)
SELECT id,0,0,0 FROM flavors;

CREATE TABLE IF NOT EXISTS inventory_reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  status VARCHAR(20) NOT NULL DEFAULT 'reserved',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(order_id,flavor_id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id)
);
