ALTER TABLE shipping_methods ADD COLUMN description TEXT NOT NULL DEFAULT '';
ALTER TABLE shipping_methods ADD COLUMN eta_min_days INTEGER NULL;
ALTER TABLE shipping_methods ADD COLUMN eta_max_days INTEGER NULL;
ALTER TABLE shipping_methods ADD COLUMN checkout_message TEXT NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS fulfillment_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO fulfillment_settings(setting_key,setting_value) VALUES
 ('pickup_location_name','Fudge Donuts Pickup'),
 ('pickup_address',''),
 ('pickup_hours',''),
 ('pickup_instructions','We’ll email you when your order is ready for pickup.'),
 ('shipping_notice','Orders are prepared fresh before shipment.');
