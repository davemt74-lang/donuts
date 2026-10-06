CREATE TABLE IF NOT EXISTS order_shipments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  shipment_number VARCHAR(64) NOT NULL UNIQUE,
  carrier VARCHAR(80) NOT NULL DEFAULT '',
  tracking_number VARCHAR(190) NOT NULL DEFAULT '',
  tracking_url VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','shipped','delivered','cancelled')),
  shipped_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_shipments_order ON order_shipments(order_id,id);
CREATE INDEX IF NOT EXISTS idx_order_shipments_status ON order_shipments(status,updated_at DESC);

CREATE TABLE IF NOT EXISTS order_shipment_items (
  shipment_id INTEGER NOT NULL,
  order_item_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  PRIMARY KEY(shipment_id,order_item_id),
  FOREIGN KEY(shipment_id) REFERENCES order_shipments(id) ON DELETE CASCADE,
  FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_shipment_items_order_item ON order_shipment_items(order_item_id);

INSERT OR IGNORE INTO order_shipments(order_id,shipment_number,carrier,tracking_number,tracking_url,status,shipped_at,delivered_at,created_at,updated_at)
SELECT order_id,'LEGACY-'||order_id,carrier,tracking_number,tracking_url,
  CASE WHEN delivered_at IS NOT NULL THEN 'delivered' WHEN shipped_at IS NOT NULL THEN 'shipped' ELSE 'pending' END,
  shipped_at,delivered_at,COALESCE(shipped_at,updated_at),updated_at
FROM order_fulfillment_details
WHERE tracking_number<>'';
