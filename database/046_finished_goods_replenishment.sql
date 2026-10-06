CREATE TABLE IF NOT EXISTS finished_goods_replenishment_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS finished_goods_replenishment_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  flavor_id INTEGER NOT NULL,
  recommended_units INTEGER NOT NULL CHECK(recommended_units>=0),
  scheduled_units INTEGER NOT NULL DEFAULT 0 CHECK(scheduled_units>=0),
  work_order_id INTEGER NULL,
  action VARCHAR(32) NOT NULL CHECK(action IN ('work_order_created','dismissed')),
  notes TEXT NOT NULL DEFAULT '',
  recorded_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  FOREIGN KEY(work_order_id) REFERENCES production_work_orders(id) ON DELETE SET NULL,
  FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_fg_replenishment_events_flavor ON finished_goods_replenishment_events(flavor_id,created_at DESC);

INSERT OR IGNORE INTO finished_goods_replenishment_settings(setting_key,setting_value) VALUES
 ('history_days','28'),
 ('safety_days','2'),
 ('minimum_run_units','6');
