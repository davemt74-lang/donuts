CREATE TABLE IF NOT EXISTS product_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  flavor_id INTEGER NOT NULL,
  rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
  title VARCHAR(190) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
  verified_purchase INTEGER NOT NULL DEFAULT 1,
  moderated_by INTEGER NULL,
  moderated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id,flavor_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE CASCADE,
  FOREIGN KEY(moderated_by) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_product_reviews_flavor ON product_reviews(flavor_id,status,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_product_reviews_moderation ON product_reviews(status,created_at);
