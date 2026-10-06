CREATE TABLE IF NOT EXISTS store_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO store_settings(setting_key,setting_value) VALUES
 ('store_name','Fudge Donuts'),
 ('legal_name','Fudge Donuts'),
 ('contact_email','hello@example.com'),
 ('support_email',''),
 ('phone',''),
 ('address_line1',''),
 ('address_line2',''),
 ('city',''),
 ('region',''),
 ('postal_code',''),
 ('country','US'),
 ('timezone','UTC'),
 ('order_prefix','FD'),
 ('instagram_url',''),
 ('facebook_url','');

INSERT OR REPLACE INTO store_settings(setting_key,setting_value)
SELECT 'contact_email',content_value FROM site_content
WHERE content_key='contact_email' AND trim(content_value)<>'';
