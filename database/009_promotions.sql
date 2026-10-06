CREATE TABLE IF NOT EXISTS order_discounts (
  order_id INTEGER NOT NULL,
  discount_rule_id INTEGER NOT NULL,
  name VARCHAR(120) NOT NULL,
  amount_cents INTEGER NOT NULL,
  PRIMARY KEY(order_id,discount_rule_id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(discount_rule_id) REFERENCES discount_rules(id)
);
CREATE TABLE IF NOT EXISTS discount_redemptions (
  order_id INTEGER NOT NULL,
  discount_rule_id INTEGER NOT NULL,
  redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(order_id,discount_rule_id),
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(discount_rule_id) REFERENCES discount_rules(id)
);
