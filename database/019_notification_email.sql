CREATE TABLE IF NOT EXISTS notification_email_content (
  outbox_id INTEGER PRIMARY KEY,
  html_body TEXT NOT NULL DEFAULT '',
  FOREIGN KEY(outbox_id) REFERENCES notification_outbox(id) ON DELETE CASCADE
);
