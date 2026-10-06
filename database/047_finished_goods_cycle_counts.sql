CREATE TABLE IF NOT EXISTS finished_goods_cycle_count_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS finished_goods_cycle_counts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  system_quantity INTEGER NOT NULL CHECK(system_quantity>=0),
  counted_quantity INTEGER NOT NULL CHECK(counted_quantity>=0),
  variance_quantity INTEGER NOT NULL,
  reason VARCHAR(32) NOT NULL CHECK(reason IN ('routine','shrinkage','damage','found_stock','correction','other')),
  notes TEXT NOT NULL DEFAULT '',
  counted_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(batch_id) REFERENCES production_batches(id),
  FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  FOREIGN KEY(counted_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_finished_goods_cycle_counts_batch ON finished_goods_cycle_counts(batch_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_finished_goods_cycle_counts_flavor ON finished_goods_cycle_counts(flavor_id,created_at DESC);

INSERT OR IGNORE INTO finished_goods_cycle_count_settings(setting_key,setting_value) VALUES
 ('count_frequency_days','7');
