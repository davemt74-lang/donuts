CREATE TABLE IF NOT EXISTS finished_goods_allocation_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  mode VARCHAR(24) NOT NULL DEFAULT 'manual',
  allocation_json TEXT NOT NULL DEFAULT '',
  assigned_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_finished_goods_allocation_order ON finished_goods_allocation_events(order_id,id DESC);
