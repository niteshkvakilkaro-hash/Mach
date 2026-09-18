-- Email sending: outgoing queue + settings
CREATE TABLE IF NOT EXISTS email_queue (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email      VARCHAR(150) NOT NULL,
  to_name       VARCHAR(100) NULL,
  subject       VARCHAR(200) NOT NULL,
  html_body     MEDIUMTEXT NOT NULL,
  text_body     MEDIUMTEXT NOT NULL,
  event         VARCHAR(50)  NOT NULL,            -- lead.new, lead.confirmation, booking.confirmation, payment.receipt, test
  related       VARCHAR(30)  NULL,                -- LEAD-…, BK-…, PAY-…
  status        ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    VARCHAR(500) NULL,
  send_after    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at     DATETIME NULL,
  sent_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eq_pick (status, send_after),
  KEY idx_eq_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
 ('mail_enabled','0','email'),
 ('smtp_host','','email'),
 ('smtp_port','587','email'),
 ('smtp_encryption','tls','email'),
 ('smtp_username','','email'),
 ('smtp_password','','email'),
 ('mail_from_email','','email'),
 ('mail_from_name','','email'),
 ('mail_reply_to','','email'),
 ('email_customer_lead','1','email'),
 ('email_customer_booking','1','email'),
 ('email_customer_receipt','1','email');
