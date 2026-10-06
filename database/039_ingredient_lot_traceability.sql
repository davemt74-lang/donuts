CREATE TABLE IF NOT EXISTS ingredient_lots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ingredient_name VARCHAR(190) NOT NULL,
  supplier_name VARCHAR(190) NOT NULL,
  supplier_lot_code VARCHAR(120) NOT NULL,
  received_at DATETIME NOT NULL,
  best_by_date DATE NULL,
  quantity_received REAL NULL CHECK(quantity_received IS NULL OR quantity_received>0),
  quantity_unit VARCHAR(32) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK(status IN ('active','hold','recalled')),
  notes TEXT NOT NULL DEFAULT '',
  recall_reason TEXT NOT NULL DEFAULT '',
  recalled_at DATETIME NULL,
  recalled_by INTEGER NULL,
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(ingredient_name,supplier_name,supplier_lot_code),
  FOREIGN KEY(recalled_by) REFERENCES admin_users(id) ON DELETE SET NULL,
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ingredient_lots_status ON ingredient_lots(status,best_by_date);
CREATE INDEX IF NOT EXISTS idx_ingredient_lots_supplier ON ingredient_lots(supplier_name,supplier_lot_code);

CREATE TABLE IF NOT EXISTS production_batch_ingredients (
  batch_id INTEGER NOT NULL,
  ingredient_lot_id INTEGER NOT NULL,
  quantity_used REAL NULL CHECK(quantity_used IS NULL OR quantity_used>0),
  quantity_unit VARCHAR(32) NOT NULL DEFAULT '',
  linked_by INTEGER NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(batch_id,ingredient_lot_id),
  FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
  FOREIGN KEY(ingredient_lot_id) REFERENCES ingredient_lots(id) ON DELETE RESTRICT,
  FOREIGN KEY(linked_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_batch_ingredients_lot ON production_batch_ingredients(ingredient_lot_id,batch_id);
