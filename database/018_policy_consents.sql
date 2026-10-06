CREATE TABLE IF NOT EXISTS order_consents (
  order_id INTEGER PRIMARY KEY,
  terms_accepted INTEGER NOT NULL DEFAULT 0,
  terms_version VARCHAR(64) NOT NULL,
  privacy_version VARCHAR(64) NOT NULL,
  refund_policy_version VARCHAR(64) NOT NULL,
  accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO site_content(content_key,content_value) VALUES
('terms_title','Terms of Service'),
('terms_body','These terms govern purchases from Fudge Donuts. Product availability, pricing, fulfillment, and promotions may change. Orders are not final until payment is confirmed.'),
('privacy_title','Privacy Policy'),
('privacy_body','We use customer information to process orders, provide account features, communicate about purchases, and operate the store. We do not sell customer information.'),
('refund_title','Refund & Cancellation Policy'),
('refund_body','Cancellation requests are reviewed before fulfillment progresses. Approved refunds are returned through the original payment method. Food products that have shipped or been delivered may have limited return eligibility.'),
('shipping_policy_title','Shipping & Pickup Policy'),
('shipping_policy_body','Available shipping methods and local pickup eligibility are shown at checkout. Delivery estimates are not guarantees. Pickup instructions are provided when an order is ready.'),
('terms_version','2026-10'),
('privacy_version','2026-10'),
('refund_policy_version','2026-10');
