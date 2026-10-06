CREATE TABLE IF NOT EXISTS site_content (
  content_key VARCHAR(120) PRIMARY KEY,
  content_value TEXT NOT NULL,
  content_type VARCHAR(32) NOT NULL DEFAULT 'text',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(24) NOT NULL DEFAULT 'subscribed',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO site_content(content_key,content_value) VALUES
('hero_title','Not just a donut. A fudge donut.'),
('hero_subtitle','Small-batch fudge donuts made for gifting, sharing, and keeping to yourself.'),
('story_title','A richer kind of donut.'),
('story_body','Fudge Donuts combines a tender donut with the dense, indulgent character of handcrafted fudge.'),
('seo_title','Fudge Donuts · Gourmet Fudge Donut Gift Boxes'),
('seo_description','Shop handcrafted Fudge Donuts in 3, 6 and 12 packs, including build-your-own boxes and gift options.'),
('contact_email','hello@example.com'),
('faq_shipping','Orders are prepared fresh. Shipping options and local pickup availability are shown at checkout.'),
('faq_allergens','Ingredients and allergen information are listed with each flavor.'),
('faq_gifts','Gift orders can include a personal message at checkout.');
