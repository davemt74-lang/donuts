CREATE TABLE IF NOT EXISTS flavor_recipes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  flavor_id INTEGER NOT NULL,
  version INTEGER NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','retired')),
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by) REFERENCES admin_users(id) ON DELETE SET NULL,
  UNIQUE(flavor_id,version)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_flavor_recipe_one_active ON flavor_recipes(flavor_id) WHERE status='active';

CREATE TABLE IF NOT EXISTS flavor_recipe_components (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL,
  ingredient_name VARCHAR(190) NOT NULL,
  quantity_per_donut REAL NOT NULL CHECK(quantity_per_donut>0),
  quantity_unit VARCHAR(32) NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(recipe_id) REFERENCES flavor_recipes(id) ON DELETE CASCADE,
  UNIQUE(recipe_id,ingredient_name,quantity_unit)
);
CREATE INDEX IF NOT EXISTS idx_recipe_components_recipe ON flavor_recipe_components(recipe_id,sort_order,id);

CREATE TABLE IF NOT EXISTS batch_recipe_requirements (
  batch_id INTEGER NOT NULL,
  recipe_id INTEGER NOT NULL,
  recipe_version INTEGER NOT NULL,
  ingredient_name VARCHAR(190) NOT NULL,
  expected_quantity REAL NOT NULL CHECK(expected_quantity>0),
  quantity_unit VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(batch_id,ingredient_name,quantity_unit),
  FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
  FOREIGN KEY(recipe_id) REFERENCES flavor_recipes(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_batch_recipe_requirements_recipe ON batch_recipe_requirements(recipe_id,batch_id);
