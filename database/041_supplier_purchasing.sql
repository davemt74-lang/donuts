CREATE TABLE IF NOT EXISTS suppliers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(190) NOT NULL UNIQUE,
  contact_name VARCHAR(190) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(64) NOT NULL DEFAULT '',
  address TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS supplier_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_id INTEGER NOT NULL,
  ingredient_name VARCHAR(190) NOT NULL,
  supplier_sku VARCHAR(120) NOT NULL DEFAULT '',
  quantity_unit VARCHAR(32) NOT NULL,
  unit_cost_cents INTEGER NOT NULL DEFAULT 0 CHECK(unit_cost_cents>=0),
  lead_time_days INTEGER NOT NULL DEFAULT 0 CHECK(lead_time_days>=0),
  min_order_quantity REAL NULL CHECK(min_order_quantity IS NULL OR min_order_quantity>0),
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  UNIQUE(supplier_id,ingredient_name,quantity_unit)
);
CREATE INDEX IF NOT EXISTS idx_supplier_items_supplier ON supplier_items(supplier_id,active);

CREATE TABLE IF NOT EXISTS purchase_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  po_number VARCHAR(40) NOT NULL UNIQUE,
  supplier_id INTEGER NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','ordered','partially_received','received','cancelled')),
  ordered_at DATETIME NULL,
  expected_at DATETIME NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status,expected_at,created_at DESC);

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_id INTEGER NOT NULL,
  supplier_item_id INTEGER NOT NULL,
  ingredient_name VARCHAR(190) NOT NULL,
  quantity_ordered REAL NOT NULL CHECK(quantity_ordered>0),
  quantity_received REAL NOT NULL DEFAULT 0 CHECK(quantity_received>=0),
  quantity_unit VARCHAR(32) NOT NULL,
  unit_cost_cents INTEGER NOT NULL DEFAULT 0 CHECK(unit_cost_cents>=0),
  FOREIGN KEY(purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY(supplier_item_id) REFERENCES supplier_items(id),
  UNIQUE(purchase_order_id,supplier_item_id)
);
CREATE INDEX IF NOT EXISTS idx_purchase_order_items_po ON purchase_order_items(purchase_order_id);

CREATE TABLE IF NOT EXISTS purchase_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_item_id INTEGER NOT NULL,
  ingredient_lot_id INTEGER NOT NULL,
  quantity_received REAL NOT NULL CHECK(quantity_received>0),
  received_at DATETIME NOT NULL,
  received_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(purchase_order_item_id) REFERENCES purchase_order_items(id),
  FOREIGN KEY(ingredient_lot_id) REFERENCES ingredient_lots(id),
  FOREIGN KEY(received_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_purchase_receipts_item ON purchase_receipts(purchase_order_item_id,id);
