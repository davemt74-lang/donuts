CREATE INDEX IF NOT EXISTS idx_users_email_normalized ON users(lower(email));
CREATE INDEX IF NOT EXISTS idx_orders_email_normalized ON orders(lower(email));
CREATE INDEX IF NOT EXISTS idx_support_email_normalized ON support_tickets(lower(email));
