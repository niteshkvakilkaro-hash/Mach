-- Service quality: post-job feedback & ratings, complaint / warranty tickets
CREATE TABLE IF NOT EXISTS feedback (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id         INT UNSIGNED NOT NULL,
  customer_id        INT UNSIGNED NOT NULL,
  technician_id      INT UNSIGNED NULL,
  token              CHAR(32) NOT NULL,                 -- private link for the customer (128-bit random)
  rating             TINYINT UNSIGNED NULL,             -- 1..5, NULL until submitted
  tags               VARCHAR(255) NULL,                 -- comma separated: on_time,polite,clean,fair_price,fixed,late,rude,not_fixed,overcharged,messy
  comment            TEXT NULL,
  status             ENUM('requested','submitted') NOT NULL DEFAULT 'requested',
  requested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at       DATETIME NULL,
  google_clicked_at  DATETIME NULL,
  complaint_id       INT UNSIGNED NULL,                 -- auto-created for low ratings
  ip_address         VARCHAR(45) NULL,
  UNIQUE KEY uq_feedback_booking (booking_id),
  UNIQUE KEY uq_feedback_token (token),
  KEY idx_feedback_tech (technician_id, status),
  KEY idx_feedback_submitted (status, submitted_at),
  CONSTRAINT fk_fb_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_fb_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_fb_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
  CONSTRAINT chk_fb_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaints (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_number    VARCHAR(20) NOT NULL,
  customer_id         INT UNSIGNED NULL,
  booking_id          INT UNSIGNED NULL,               -- the job being complained about
  technician_id       INT UNSIGNED NULL,               -- technician of that job
  customer_name       VARCHAR(100) NOT NULL DEFAULT '',
  phone               VARCHAR(15) NOT NULL,
  email               VARCHAR(150) NULL,
  category            ENUM('not_fixed','repeat_issue','late','overcharged','behaviour','damage','billing','other') NOT NULL DEFAULT 'other',
  description         TEXT NULL,
  source              ENUM('website','feedback','phone','admin') NOT NULL DEFAULT 'website',
  priority            ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  status              ENUM('open','in_progress','revisit_scheduled','resolved','closed','rejected') NOT NULL DEFAULT 'open',
  is_warranty         TINYINT(1) NOT NULL DEFAULT 0,
  assigned_to         INT UNSIGNED NULL,
  revisit_booking_id  INT UNSIGNED NULL,
  resolution          TEXT NULL,
  sla_due_at          DATETIME NULL,
  sla_notified_at     DATETIME NULL,
  first_response_at   DATETIME NULL,
  resolved_at         DATETIME NULL,
  closed_at           DATETIME NULL,
  created_by          INT UNSIGNED NULL,
  ip_address          VARCHAR(45) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_complaints_number (complaint_number),
  KEY idx_cmp_status (status, sla_due_at),
  KEY idx_cmp_phone (phone),
  KEY idx_cmp_tech (technician_id),
  KEY idx_cmp_booking (booking_id),
  KEY idx_cmp_created (created_at),
  CONSTRAINT fk_cmp_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_cmp_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_cmp_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
  CONSTRAINT fk_cmp_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_cmp_revisit FOREIGN KEY (revisit_booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_cmp_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaint_updates (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id  INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NULL,                    -- NULL = customer / system
  type          ENUM('created','note','status','assign','revisit','customer') NOT NULL DEFAULT 'note',
  old_status    VARCHAR(30) NULL,
  new_status    VARCHAR(30) NULL,
  note          TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cu_complaint (complaint_id, created_at),
  CONSTRAINT fk_cu_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_cu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE feedback ADD CONSTRAINT fk_fb_complaint FOREIGN KEY IF NOT EXISTS (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL;

INSERT IGNORE INTO permissions (module, action, slug, label) VALUES
 ('complaints','view','complaints.view','View complaints & feedback'),
 ('complaints','manage','complaints.manage','Handle complaints (status, assign, revisit)');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('complaints.view', 'complaints.manage')
 WHERE r.slug IN ('super_admin', 'admin', 'manager', 'support');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'complaints.view' WHERE r.slug IN ('sales', 'accountant');

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
 ('feedback_enabled','1','quality'),
 ('google_review_url','','quality'),
 ('feedback_low_rating','3','quality'),
 ('complaint_sla_hours','4','quality'),
 ('complaint_sla_hours_urgent','2','quality');
