CREATE TABLE IF NOT EXISTS production_schedule_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS production_capacity_overrides (
  production_date DATE PRIMARY KEY,
  max_units INTEGER NOT NULL CHECK(max_units>=0),
  notes TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS production_work_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  work_order_number VARCHAR(40) NOT NULL UNIQUE,
  flavor_id INTEGER NOT NULL,
  scheduled_date DATE NOT NULL,
  planned_quantity INTEGER NOT NULL CHECK(planned_quantity>0),
  actual_quantity INTEGER NULL CHECK(actual_quantity IS NULL OR actual_quantity>0),
  priority VARCHAR(16) NOT NULL DEFAULT 'normal' CHECK(priority IN ('normal','high','urgent')),
  status VARCHAR(20) NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','in_progress','completed','cancelled')),
  assigned_to VARCHAR(190) NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  batch_id INTEGER NULL UNIQUE,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  FOREIGN KEY(batch_id) REFERENCES production_batches(id),
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_production_work_orders_schedule ON production_work_orders(scheduled_date,status,priority);
CREATE INDEX IF NOT EXISTS idx_production_work_orders_flavor ON production_work_orders(flavor_id,status,scheduled_date);

INSERT OR IGNORE INTO production_schedule_settings(setting_key,setting_value) VALUES
 ('daily_capacity_units','500'),
 ('planning_horizon_days','14');
