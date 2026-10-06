CREATE TABLE IF NOT EXISTS shipping_methods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type VARCHAR(32) NOT NULL CHECK(type IN ('shipping','pickup')),
  price_cents INTEGER NOT NULL DEFAULT 0 CHECK(price_cents>=0),
  free_over_cents INTEGER NULL,
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS pickup_zip_codes (
  postal_code VARCHAR(20) PRIMARY KEY,
  active INTEGER NOT NULL DEFAULT 1
);
INSERT OR IGNORE INTO shipping_methods(code,name,type,price_cents,free_over_cents,active,sort_order)
VALUES('standard','Standard Shipping','shipping',899,6500,1,10),
      ('local-pickup','Local Pickup','pickup',0,NULL,1,20);
