CREATE TABLE IF NOT EXISTS flavor_compliance_profiles (
  flavor_id INTEGER PRIMARY KEY,
  ingredient_statement TEXT NOT NULL DEFAULT '',
  allergen_statement TEXT NOT NULL DEFAULT '',
  shared_kitchen_notice TEXT NOT NULL DEFAULT 'Prepared in a shared kitchen. Cross-contact may occur.',
  storage_instructions TEXT NOT NULL DEFAULT '',
  shelf_life_days INTEGER NULL CHECK(shelf_life_days IS NULL OR shelf_life_days BETWEEN 1 AND 365),
  net_weight_oz REAL NULL CHECK(net_weight_oz IS NULL OR net_weight_oz > 0),
  label_version VARCHAR(40) NOT NULL DEFAULT '1',
  published INTEGER NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO flavor_compliance_profiles(flavor_id,ingredient_statement,allergen_statement)
SELECT id,ingredients,allergens FROM flavors;
