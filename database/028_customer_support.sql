CREATE TABLE IF NOT EXISTS support_tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_number VARCHAR(32) NOT NULL UNIQUE,
  user_id INTEGER NULL,
  order_id INTEGER NULL,
  email VARCHAR(190) NOT NULL,
  customer_name VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','waiting_customer','resolved','closed')),
  priority VARCHAR(16) NOT NULL DEFAULT 'normal' CHECK(priority IN ('normal','high','urgent')),
  assigned_admin_id INTEGER NULL,
  last_customer_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_admin_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY(assigned_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_support_tickets_queue ON support_tickets(status,priority,updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_support_tickets_user ON support_tickets(user_id,updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_support_tickets_order ON support_tickets(order_id,updated_at DESC);

CREATE TABLE IF NOT EXISTS support_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL,
  author_type VARCHAR(16) NOT NULL CHECK(author_type IN ('customer','admin','system')),
  admin_id INTEGER NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY(admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_support_messages_ticket ON support_messages(ticket_id,id);
