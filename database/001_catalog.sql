CREATE TABLE IF NOT EXISTS pack_sizes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  size INTEGER NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  base_price_cents INTEGER NOT NULL CHECK (base_price_cents >= 0),
  customizable INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS flavors (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  surcharge_cents INTEGER NOT NULL DEFAULT 0 CHECK (surcharge_cents >= 0),
  image_path VARCHAR(255) NOT NULL DEFAULT '',
  ingredients TEXT NOT NULL DEFAULT '',
  allergens TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  sold_out INTEGER NOT NULL DEFAULT 0,
  seasonal INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pack_flavor_eligibility (
  pack_size_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (pack_size_id, flavor_id),
  FOREIGN KEY (pack_size_id) REFERENCES pack_sizes(id) ON DELETE CASCADE,
  FOREIGN KEY (flavor_id) REFERENCES flavors(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS preset_packs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  pack_size_id INTEGER NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  image_path VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (pack_size_id) REFERENCES pack_sizes(id)
);

CREATE TABLE IF NOT EXISTS preset_pack_items (
  preset_pack_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL CHECK (quantity > 0),
  PRIMARY KEY (preset_pack_id, flavor_id),
  FOREIGN KEY (preset_pack_id) REFERENCES preset_packs(id) ON DELETE CASCADE,
  FOREIGN KEY (flavor_id) REFERENCES flavors(id)
);

INSERT OR IGNORE INTO pack_sizes (size,name,base_price_cents,customizable,sort_order) VALUES
(3,'3 Pack',1299,1,10),(6,'6 Pack',2399,1,20),(12,'12 Pack',4299,1,30);

INSERT OR IGNORE INTO flavors (name,slug,description,surcharge_cents,image_path,active,sort_order) VALUES
('S''mores','smores','Chocolate fudge donut with toasted marshmallow and graham crunch.',0,'/images/flavor-smores.png',1,10),
('Caramel Pretzel','caramel-pretzel','Chocolate fudge donut with caramel and pretzel crunch.',100,'/images/flavor-caramel-pretzel.png',1,20),
('Cookies & Cream','cookies-cream','Chocolate fudge donut with cookies-and-cream topping.',50,'/images/flavor-cookies-cream.png',1,30),
('Mint Chocolate','mint-chocolate','Chocolate fudge donut with mint cream and chocolate.',50,'/images/flavor-mint-chocolate.png',1,40);

INSERT OR IGNORE INTO pack_flavor_eligibility(pack_size_id,flavor_id,enabled)
SELECT p.id,f.id,1 FROM pack_sizes p CROSS JOIN flavors f;
