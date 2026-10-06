CREATE TABLE IF NOT EXISTS gift_card_purchases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_token VARCHAR(64) NOT NULL UNIQUE,
  amount_cents INTEGER NOT NULL CHECK(amount_cents>0),
  purchaser_email VARCHAR(190) NOT NULL,
  recipient_email VARCHAR(190) NOT NULL,
  recipient_name VARCHAR(190) NOT NULL DEFAULT '',
  message VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed')),
  stripe_session_id VARCHAR(190) NULL UNIQUE,
  gift_card_id INTEGER NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gift_cards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code_hash VARCHAR(64) NOT NULL UNIQUE,
  code_cipher TEXT NOT NULL,
  code_iv VARCHAR(64) NOT NULL,
  code_tag VARCHAR(64) NOT NULL,
  code_last4 VARCHAR(4) NOT NULL,
  initial_balance_cents INTEGER NOT NULL CHECK(initial_balance_cents>0),
  balance_cents INTEGER NOT NULL CHECK(balance_cents>=0),
  reserved_cents INTEGER NOT NULL DEFAULT 0 CHECK(reserved_cents>=0),
  status VARCHAR(24) NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled','depleted')),
  recipient_email VARCHAR(190) NOT NULL,
  issued_from_purchase_id INTEGER NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(issued_from_purchase_id) REFERENCES gift_card_purchases(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS gift_card_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  gift_card_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  purchase_id INTEGER NULL,
  entry_type VARCHAR(24) NOT NULL CHECK(entry_type IN ('issue','redeem','refund','adjustment')),
  amount_cents INTEGER NOT NULL CHECK(amount_cents<>0),
  balance_after_cents INTEGER NOT NULL CHECK(balance_after_cents>=0),
  event_key VARCHAR(190) NOT NULL UNIQUE,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(gift_card_id) REFERENCES gift_cards(id) ON DELETE CASCADE,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY(purchase_id) REFERENCES gift_card_purchases(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_gift_card_ledger_card ON gift_card_ledger(gift_card_id,id);

CREATE TABLE IF NOT EXISTS order_gift_card_applications (
  order_id INTEGER PRIMARY KEY,
  gift_card_id INTEGER NOT NULL,
  reserved_cents INTEGER NOT NULL DEFAULT 0,
  redeemed_cents INTEGER NOT NULL DEFAULT 0,
  refunded_cents INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','redeemed','released')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(gift_card_id) REFERENCES gift_cards(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_order_gift_card_card ON order_gift_card_applications(gift_card_id,status);

ALTER TABLE refund_records ADD COLUMN gift_card_amount_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE refund_records ADD COLUMN stripe_amount_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE order_payment_reconciliation ADD COLUMN external_tender_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE order_payment_reconciliation ADD COLUMN calculated_tax_cents INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_gift_card_purchases_status ON gift_card_purchases(status,created_at);
CREATE INDEX IF NOT EXISTS idx_gift_cards_status ON gift_cards(status,balance_cents);
