CREATE TABLE IF NOT EXISTS production_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_code VARCHAR(64) NOT NULL UNIQUE,
  produced_at DATETIME NOT NULL,
  best_by_date DATE NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','released','hold','recalled','closed')),
  notes TEXT NOT NULL DEFAULT '',
  recall_reason TEXT NOT NULL DEFAULT '',
  recalled_at DATETIME NULL,
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_production_batches_status ON production_batches(status,produced_at DESC);

CREATE TABLE IF NOT EXISTS production_batch_flavors (
  batch_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  quantity_produced INTEGER NOT NULL CHECK(quantity_produced>=0),
  PRIMARY KEY(batch_id,flavor_id),
  FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_batch_flavors_flavor ON production_batch_flavors(flavor_id,batch_id);

CREATE TABLE IF NOT EXISTS order_production_batches (
  order_id INTEGER NOT NULL,
  batch_id INTEGER NOT NULL,
  assigned_by INTEGER NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(order_id,batch_id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE RESTRICT,
  FOREIGN KEY(assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_order_batches_batch ON order_production_batches(batch_id,order_id);
