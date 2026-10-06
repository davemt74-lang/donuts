CREATE TABLE IF NOT EXISTS packaging_supplier_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_id INTEGER NOT NULL,
  material_id INTEGER NOT NULL,
  supplier_sku VARCHAR(120) NOT NULL DEFAULT '',
  unit_cost_cents INTEGER NOT NULL DEFAULT 0 CHECK(unit_cost_cents>=0),
  lead_time_days INTEGER NOT NULL DEFAULT 0 CHECK(lead_time_days>=0),
  min_order_quantity INTEGER NULL CHECK(min_order_quantity IS NULL OR min_order_quantity>0),
  active INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY(material_id) REFERENCES packaging_materials(id),
  UNIQUE(supplier_id,material_id)
);
CREATE INDEX IF NOT EXISTS idx_packaging_supplier_items_material ON packaging_supplier_items(material_id,active);

CREATE TABLE IF NOT EXISTS packaging_purchase_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  po_number VARCHAR(40) NOT NULL UNIQUE,
  supplier_id INTEGER NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','ordered','partially_received','received','cancelled')),
  expected_at DATE NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  ordered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_packaging_po_status ON packaging_purchase_orders(status,expected_at);

CREATE TABLE IF NOT EXISTS packaging_purchase_order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_id INTEGER NOT NULL,
  supplier_item_id INTEGER NOT NULL,
  material_id INTEGER NOT NULL,
  material_name VARCHAR(190) NOT NULL,
  quantity_ordered INTEGER NOT NULL CHECK(quantity_ordered>0),
  quantity_received INTEGER NOT NULL DEFAULT 0 CHECK(quantity_received>=0),
  unit_cost_cents INTEGER NOT NULL DEFAULT 0 CHECK(unit_cost_cents>=0),
  FOREIGN KEY(purchase_order_id) REFERENCES packaging_purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY(supplier_item_id) REFERENCES packaging_supplier_items(id),
  FOREIGN KEY(material_id) REFERENCES packaging_materials(id)
);
CREATE INDEX IF NOT EXISTS idx_packaging_po_items_po ON packaging_purchase_order_items(purchase_order_id);

CREATE TABLE IF NOT EXISTS packaging_purchase_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_item_id INTEGER NOT NULL,
  quantity_received INTEGER NOT NULL CHECK(quantity_received>0),
  received_at DATETIME NOT NULL,
  notes TEXT NOT NULL DEFAULT '',
  received_by INTEGER NULL,
  FOREIGN KEY(purchase_order_item_id) REFERENCES packaging_purchase_order_items(id),
  FOREIGN KEY(received_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_packaging_receipts_item ON packaging_purchase_receipts(purchase_order_item_id,id DESC);
