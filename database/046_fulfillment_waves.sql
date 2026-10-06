CREATE TABLE IF NOT EXISTS fulfillment_waves (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  wave_number VARCHAR(40) NOT NULL UNIQUE,
  fulfillment_type VARCHAR(16) NOT NULL CHECK(fulfillment_type IN ('shipping','pickup')),
  status VARCHAR(20) NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','in_progress','completed','cancelled')),
  operator_name VARCHAR(190) NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_fulfillment_waves_status ON fulfillment_waves(status,fulfillment_type,created_at DESC);

CREATE TABLE IF NOT EXISTS fulfillment_wave_orders (
  wave_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  packed_at DATETIME NULL,
  packed_by INTEGER NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(wave_id,order_id),
  FOREIGN KEY(wave_id) REFERENCES fulfillment_waves(id) ON DELETE CASCADE,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(packed_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_fulfillment_wave_one_active_order ON fulfillment_wave_orders(order_id) WHERE active=1;
CREATE INDEX IF NOT EXISTS idx_fulfillment_wave_orders_wave ON fulfillment_wave_orders(wave_id,active,packed_at);
