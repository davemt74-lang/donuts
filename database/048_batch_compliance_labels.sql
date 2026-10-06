CREATE TABLE IF NOT EXISTS production_batch_compliance_snapshots (
  batch_id INTEGER PRIMARY KEY,
  flavor_id INTEGER NOT NULL,
  label_version VARCHAR(40) NOT NULL,
  ingredient_statement TEXT NOT NULL,
  allergen_statement TEXT NOT NULL,
  shared_kitchen_notice TEXT NOT NULL DEFAULT '',
  storage_instructions TEXT NOT NULL,
  shelf_life_days INTEGER NOT NULL CHECK(shelf_life_days BETWEEN 1 AND 365),
  net_weight_oz REAL NOT NULL CHECK(net_weight_oz>0),
  produced_date DATE NOT NULL,
  best_by_date DATE NOT NULL,
  snapshotted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id)
);
CREATE INDEX IF NOT EXISTS idx_batch_compliance_flavor ON production_batch_compliance_snapshots(flavor_id,best_by_date);
