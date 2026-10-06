CREATE TABLE IF NOT EXISTS production_quality_checks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  work_order_id INTEGER NOT NULL,
  check_key VARCHAR(64) NOT NULL,
  result VARCHAR(16) NOT NULL CHECK(result IN ('pass','fail')),
  notes TEXT NOT NULL DEFAULT '',
  checked_by INTEGER NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(work_order_id) REFERENCES production_work_orders(id) ON DELETE CASCADE,
  FOREIGN KEY(checked_by) REFERENCES admin_users(id) ON DELETE SET NULL,
  UNIQUE(work_order_id,check_key)
);
CREATE INDEX IF NOT EXISTS idx_production_quality_work_order ON production_quality_checks(work_order_id,result);

CREATE TABLE IF NOT EXISTS production_waste_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  work_order_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  reason VARCHAR(32) NOT NULL CHECK(reason IN ('quality','breakage','overproduction','spoilage','setup','other')),
  notes TEXT NOT NULL DEFAULT '',
  recorded_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(work_order_id) REFERENCES production_work_orders(id) ON DELETE CASCADE,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_production_waste_work_order ON production_waste_events(work_order_id,created_at);
CREATE INDEX IF NOT EXISTS idx_production_waste_reason ON production_waste_events(reason,created_at);

CREATE TABLE IF NOT EXISTS production_qa_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO production_qa_settings(setting_key,setting_value) VALUES
 ('yield_warning_percent','10');
