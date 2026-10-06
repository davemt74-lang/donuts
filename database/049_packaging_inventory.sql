CREATE TABLE IF NOT EXISTS packaging_materials (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sku VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  unit VARCHAR(40) NOT NULL DEFAULT 'each',
  stock_on_hand INTEGER NOT NULL DEFAULT 0 CHECK(stock_on_hand>=0),
  reorder_point INTEGER NOT NULL DEFAULT 0 CHECK(reorder_point>=0),
  reorder_quantity INTEGER NOT NULL DEFAULT 0 CHECK(reorder_quantity>=0),
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pack_packaging_requirements (
  pack_size_id INTEGER NOT NULL,
  material_id INTEGER NOT NULL,
  quantity_per_box INTEGER NOT NULL CHECK(quantity_per_box>0),
  PRIMARY KEY(pack_size_id,material_id),
  FOREIGN KEY(pack_size_id) REFERENCES pack_sizes(id) ON DELETE CASCADE,
  FOREIGN KEY(material_id) REFERENCES packaging_materials(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_packaging_allocations (
  order_id INTEGER NOT NULL,
  material_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  status VARCHAR(16) NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','consumed','released')),
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_at DATETIME NULL,
  released_at DATETIME NULL,
  PRIMARY KEY(order_id,material_id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(material_id) REFERENCES packaging_materials(id)
);
CREATE INDEX IF NOT EXISTS idx_order_packaging_status ON order_packaging_allocations(status,order_id);

CREATE TABLE IF NOT EXISTS packaging_stock_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  material_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  movement_type VARCHAR(24) NOT NULL CHECK(movement_type IN ('adjustment','receive','reserve','consume','release')),
  quantity_delta INTEGER NOT NULL,
  balance_after INTEGER NOT NULL CHECK(balance_after>=0),
  reason VARCHAR(500) NOT NULL DEFAULT '',
  recorded_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(material_id) REFERENCES packaging_materials(id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_packaging_movements_material ON packaging_stock_movements(material_id,id DESC);

INSERT OR IGNORE INTO packaging_materials(sku,name,unit,reorder_point,reorder_quantity) VALUES
 ('BOX-3','3 Pack Gift Box','each',25,100),
 ('BOX-6','6 Pack Gift Box','each',20,75),
 ('BOX-12','12 Pack Gift Box','each',15,50),
 ('WRAPPER','Individual Donut Wrapper','each',100,500),
 ('BRAND-LABEL','Fudge Donuts Brand Label','each',50,250);

INSERT OR IGNORE INTO pack_packaging_requirements(pack_size_id,material_id,quantity_per_box)
SELECT p.id,m.id,1 FROM pack_sizes p JOIN packaging_materials m ON m.sku='BOX-'||p.size WHERE p.size IN (3,6,12);

INSERT OR IGNORE INTO pack_packaging_requirements(pack_size_id,material_id,quantity_per_box)
SELECT p.id,m.id,p.size FROM pack_sizes p CROSS JOIN packaging_materials m WHERE m.sku='WRAPPER' AND p.size IN (3,6,12);

INSERT OR IGNORE INTO pack_packaging_requirements(pack_size_id,material_id,quantity_per_box)
SELECT p.id,m.id,1 FROM pack_sizes p CROSS JOIN packaging_materials m WHERE m.sku='BRAND-LABEL' AND p.size IN (3,6,12);
