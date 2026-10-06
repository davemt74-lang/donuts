CREATE TABLE IF NOT EXISTS finished_goods_aging_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS finished_goods_dispositions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  reason VARCHAR(32) NOT NULL CHECK(reason IN ('expired','quality','damage','donation','sample','other')),
  notes TEXT NOT NULL DEFAULT '',
  recorded_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(batch_id) REFERENCES production_batches(id),
  FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_finished_goods_dispositions_batch ON finished_goods_dispositions(batch_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_finished_goods_dispositions_reason ON finished_goods_dispositions(reason,created_at DESC);

INSERT OR IGNORE INTO finished_goods_aging_settings(setting_key,setting_value) VALUES
 ('warning_days','3');

INSERT OR IGNORE INTO scheduled_jobs(job_key,description,expected_interval_minutes) VALUES
 ('finished-goods-aging','Finished-goods expiry and aging control',60);
