CREATE TABLE IF NOT EXISTS customer_admin_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_email_hash VARCHAR(64) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  admin_id INTEGER NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_customer_notes_email ON customer_admin_notes(customer_email_hash,id DESC);

CREATE TABLE IF NOT EXISTS customer_tags (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_email_hash VARCHAR(64) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  tag VARCHAR(64) NOT NULL,
  created_by_admin_id INTEGER NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_email_hash,tag),
  FOREIGN KEY(created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_customer_tags_email ON customer_tags(customer_email_hash,tag);
