CREATE TABLE IF NOT EXISTS production_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_code VARCHAR(64) NOT NULL UNIQUE,
  flavor_id INTEGER NOT NULL,
  produced_at DATETIME NOT NULL,
  best_by_date DATE NULL,
  quantity_produced INTEGER NOT NULL CHECK(quantity_produced>0),
  quantity_remaining INTEGER NOT NULL CHECK(quantity_remaining>=0 AND quantity_remaining<=quantity_produced),
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK(status IN ('active','depleted','hold','recalled')),
  notes TEXT NOT NULL DEFAULT '',
  recall_reason TEXT NOT NULL DEFAULT '',
  recalled_at DATETIME NULL,
  recalled_by INTEGER NULL,
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  FOREIGN KEY(recalled_by) REFERENCES admin_users(id) ON DELETE SET NULL,
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_production_batches_flavor ON production_batches(flavor_id,status,produced_at);
CREATE INDEX IF NOT EXISTS idx_production_batches_status ON production_batches(status,best_by_date);

CREATE TABLE IF NOT EXISTS order_batch_assignments (
  order_id INTEGER NOT NULL,
  batch_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  assigned_by INTEGER NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(order_id,batch_id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(batch_id) REFERENCES production_batches(id),
  FOREIGN KEY(assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_order_batch_assignments_batch ON order_batch_assignments(batch_id,order_id);
