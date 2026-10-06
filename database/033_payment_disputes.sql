ALTER TABLE gift_card_purchases ADD COLUMN stripe_payment_intent_id VARCHAR(190) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_gift_card_purchase_payment_intent ON gift_card_purchases(stripe_payment_intent_id) WHERE stripe_payment_intent_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS stripe_disputes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  stripe_dispute_id VARCHAR(190) NOT NULL UNIQUE,
  order_id INTEGER NULL,
  gift_card_purchase_id INTEGER NULL,
  stripe_payment_intent_id VARCHAR(190) NOT NULL DEFAULT '',
  stripe_charge_id VARCHAR(190) NOT NULL DEFAULT '',
  amount_cents INTEGER NOT NULL CHECK(amount_cents>=0),
  currency VARCHAR(3) NOT NULL DEFAULT 'usd',
  reason VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(40) NOT NULL,
  evidence_due_at DATETIME NULL,
  is_charge_refundable INTEGER NOT NULL DEFAULT 0,
  livemode INTEGER NOT NULL DEFAULT 0,
  last_event_type VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY(gift_card_purchase_id) REFERENCES gift_card_purchases(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_stripe_disputes_status ON stripe_disputes(status,updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_stripe_disputes_order ON stripe_disputes(order_id,status);
CREATE INDEX IF NOT EXISTS idx_stripe_disputes_gift_purchase ON stripe_disputes(gift_card_purchase_id,status);
