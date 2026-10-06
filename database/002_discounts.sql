CREATE TABLE IF NOT EXISTS discount_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code VARCHAR(64) NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type VARCHAR(16) NOT NULL CHECK (type IN ('percent','fixed')),
  value INTEGER NOT NULL CHECK (value > 0),
  min_units INTEGER NOT NULL DEFAULT 0,
  min_subtotal_cents INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  usage_limit INTEGER NULL,
  usage_count INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO discount_rules
(code,name,type,value,min_units,min_subtotal_cents,active,sort_order)
VALUES
(NULL,'3+ Boxes','percent',5,3,0,1,10),
(NULL,'6+ Boxes','percent',10,6,0,1,20);
