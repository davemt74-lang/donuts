CREATE TABLE IF NOT EXISTS analytics_visitors (
  visitor_id VARCHAR(64) PRIMARY KEY,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  first_landing_path VARCHAR(500) NOT NULL DEFAULT '',
  first_referrer_host VARCHAR(190) NOT NULL DEFAULT '',
  first_utm_source VARCHAR(190) NOT NULL DEFAULT '',
  first_utm_medium VARCHAR(190) NOT NULL DEFAULT '',
  first_utm_campaign VARCHAR(190) NOT NULL DEFAULT '',
  first_utm_content VARCHAR(190) NOT NULL DEFAULT '',
  first_utm_term VARCHAR(190) NOT NULL DEFAULT '',
  last_landing_path VARCHAR(500) NOT NULL DEFAULT '',
  last_referrer_host VARCHAR(190) NOT NULL DEFAULT '',
  last_utm_source VARCHAR(190) NOT NULL DEFAULT '',
  last_utm_medium VARCHAR(190) NOT NULL DEFAULT '',
  last_utm_campaign VARCHAR(190) NOT NULL DEFAULT '',
  last_utm_content VARCHAR(190) NOT NULL DEFAULT '',
  last_utm_term VARCHAR(190) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS analytics_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  visitor_id VARCHAR(64) NOT NULL,
  event_name VARCHAR(40) NOT NULL,
  event_key VARCHAR(190) NULL UNIQUE,
  path VARCHAR(500) NOT NULL DEFAULT '',
  metadata_json TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(visitor_id) REFERENCES analytics_visitors(visitor_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_analytics_events_event ON analytics_events(event_name,created_at);
CREATE INDEX IF NOT EXISTS idx_analytics_events_visitor ON analytics_events(visitor_id,created_at);

CREATE TABLE IF NOT EXISTS order_attribution (
  order_id INTEGER PRIMARY KEY,
  visitor_id VARCHAR(64) NULL,
  landing_path VARCHAR(500) NOT NULL DEFAULT '',
  referrer_host VARCHAR(190) NOT NULL DEFAULT '',
  utm_source VARCHAR(190) NOT NULL DEFAULT '',
  utm_medium VARCHAR(190) NOT NULL DEFAULT '',
  utm_campaign VARCHAR(190) NOT NULL DEFAULT '',
  utm_content VARCHAR(190) NOT NULL DEFAULT '',
  utm_term VARCHAR(190) NOT NULL DEFAULT '',
  attributed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(visitor_id) REFERENCES analytics_visitors(visitor_id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_order_attribution_source ON order_attribution(utm_source,utm_medium,utm_campaign);
