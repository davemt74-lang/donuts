CREATE TABLE IF NOT EXISTS order_gift_options (
  order_id INTEGER PRIMARY KEY,
  hide_price INTEGER NOT NULL DEFAULT 0,
  packaging VARCHAR(40) NOT NULL DEFAULT 'standard',
  requested_delivery_date DATE NULL,
  recipient_email VARCHAR(190) NOT NULL DEFAULT '',
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);
