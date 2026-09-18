-- =====================================================================
-- MACH Home Services — Database schema + seed data (Phase 2)
-- MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4
-- Safe to re-run: tables use IF NOT EXISTS and seeds use INSERT IGNORE.
-- Seed admin login: admin@mach.local / Admin@12345  (change after first login)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS mach_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mach_crm;
SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ---------------------------------------------------------------------
-- ACCESS CONTROL
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(50)  NOT NULL,
  slug         VARCHAR(50)  NOT NULL,
  description  VARCHAR(255) NULL,
  is_system    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module  VARCHAR(50)  NOT NULL,
  action  VARCHAR(50)  NOT NULL,
  slug    VARCHAR(100) NOT NULL,
  label   VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_permissions_slug (slug),
  KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id             INT UNSIGNED NOT NULL,
  name                VARCHAR(100) NOT NULL,
  email               VARCHAR(150) NOT NULL,
  phone               VARCHAR(15)  NULL,
  password_hash       VARCHAR(255) NOT NULL,
  avatar              VARCHAR(255) NULL,
  status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login_at       DATETIME NULL,
  last_login_ip       VARCHAR(45) NULL,
  password_changed_at DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  KEY idx_users_status (status),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic throttle table (login attempts, form submissions)
CREATE TABLE IF NOT EXISTS rate_limits (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action      VARCHAR(50) NOT NULL,
  identifier  CHAR(64)    NOT NULL,           -- sha256 of ip / email / phone
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rl_lookup (action, identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SYSTEM
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key    VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value  TEXT NULL,
  setting_group  VARCHAR(50) NOT NULL DEFAULT 'general',
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic yearly counters for LEAD-2026-000001, BK-…, PAY-…, CUS-…
CREATE TABLE IF NOT EXISTS sequences (
  seq_name    VARCHAR(20) NOT NULL,
  seq_year    SMALLINT UNSIGNED NOT NULL,
  last_value  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (seq_name, seq_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(50)  NOT NULL,         -- created, updated, deleted, assigned, status_changed, login…
  module       VARCHAR(50)  NOT NULL,         -- leads, bookings, services…
  record_id    INT UNSIGNED NULL,
  description  VARCHAR(500) NULL,
  old_values   LONGTEXT NULL,                 -- JSON
  new_values   LONGTEXT NULL,                 -- JSON
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_module_record (module, record_id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,          -- one row per recipient
  type        VARCHAR(50)  NOT NULL,          -- lead.new, lead.assigned, booking.created, payment.received…
  title       VARCHAR(150) NOT NULL,
  message     VARCHAR(500) NULL,
  link        VARCHAR(255) NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user_read (user_id, read_at),
  KEY idx_notif_created (created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CATALOGUE
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id   INT UNSIGNED NULL,              -- NULL = city, otherwise area within city
  type        ENUM('city','area') NOT NULL DEFAULT 'area',
  name        VARCHAR(100) NOT NULL,
  slug        VARCHAR(120) NOT NULL,
  pincode     VARCHAR(10)  NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_locations_slug (slug),
  KEY idx_locations_parent (parent_id, is_active, sort_order),
  CONSTRAINT fk_locations_parent FOREIGN KEY (parent_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_categories (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(100) NOT NULL,
  slug               VARCHAR(120) NOT NULL,
  icon               VARCHAR(60)  NULL,        -- Bootstrap Icons class, e.g. bi-snow2
  image              VARCHAR(255) NULL,
  short_description  VARCHAR(300) NULL,
  appliance_types    VARCHAR(500) NULL,        -- comma separated: "Split AC, Window AC"
  sort_order         INT NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id        INT UNSIGNED NOT NULL,
  name               VARCHAR(120) NOT NULL,
  slug               VARCHAR(140) NOT NULL,
  icon               VARCHAR(60)  NULL,
  short_description  VARCHAR(300) NULL,
  description        MEDIUMTEXT NULL,          -- light markup: "## Heading", "- item", blank-line paragraphs
  image              VARCHAR(255) NULL,
  starting_price     DECIMAL(10,2) NULL,
  price_note         VARCHAR(100) NULL,        -- e.g. "visit + inspection"
  duration           VARCHAR(50) NULL,
  warranty           VARCHAR(50) NULL,
  is_featured        TINYINT(1) NOT NULL DEFAULT 0,
  is_emergency       TINYINT(1) NOT NULL DEFAULT 0,
  sort_order         INT NOT NULL DEFAULT 0,
  status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  seo_title          VARCHAR(160) NULL,
  seo_description    VARCHAR(320) NULL,
  seo_keywords       VARCHAR(255) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_services_slug (slug),
  KEY idx_services_category (category_id, status, sort_order),
  KEY idx_services_featured (is_featured, status),
  CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_problems (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_problem (service_id, name),
  CONSTRAINT fk_problems_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_prices (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id  INT UNSIGNED NOT NULL,
  label       VARCHAR(150) NOT NULL,           -- "Split AC gas refill (1.5 ton)"
  price       DECIMAL(10,2) NOT NULL,
  price_type  ENUM('fixed','starting','per_unit') NOT NULL DEFAULT 'starting',
  note        VARCHAR(255) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_price_label (service_id, label),
  CONSTRAINT fk_prices_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CUSTOMERS & TECHNICIANS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_number    VARCHAR(20)  NOT NULL,
  name               VARCHAR(100) NOT NULL,
  phone              VARCHAR(15)  NOT NULL,    -- normalised 10-digit; duplicate check field
  alt_phone          VARCHAR(15)  NULL,
  email              VARCHAR(150) NULL,
  address            VARCHAR(500) NULL,
  city               VARCHAR(80)  NULL,
  pincode            VARCHAR(10)  NULL,
  location_id        INT UNSIGNED NULL,
  source             VARCHAR(30)  NULL,
  total_bookings     INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent        DECIMAL(12,2) NOT NULL DEFAULT 0,
  last_service_date  DATE NULL,
  notes              TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  UNIQUE KEY uq_customers_number (customer_number),
  UNIQUE KEY uq_customers_phone (phone),
  KEY idx_customers_email (email),
  KEY idx_customers_created (created_at),
  CONSTRAINT fk_customers_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS technicians (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NULL,       -- login account (role = technician)
  name                VARCHAR(100) NOT NULL,
  phone               VARCHAR(15)  NOT NULL,
  email               VARCHAR(150) NULL,
  specialization      VARCHAR(255) NULL,
  experience_years    TINYINT UNSIGNED NULL,
  service_areas       VARCHAR(500) NULL,       -- display text; structured in technician_locations
  address             VARCHAR(500) NULL,
  status              ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  joining_date        DATE NULL,
  profile_photo       VARCHAR(255) NULL,
  commission_percent  DECIMAL(5,2) NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  UNIQUE KEY uq_technicians_phone (phone),
  UNIQUE KEY uq_technicians_user (user_id),
  KEY idx_technicians_status (status),
  CONSTRAINT fk_technicians_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS technician_services (
  technician_id  INT UNSIGNED NOT NULL,
  service_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (technician_id, service_id),
  KEY idx_ts_service (service_id),
  CONSTRAINT fk_ts_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS technician_locations (
  technician_id  INT UNSIGNED NOT NULL,
  location_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (technician_id, location_id),
  KEY idx_tl_location (location_id),
  CONSTRAINT fk_tl_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LEADS (CRM)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_statuses (
  slug        VARCHAR(30) NOT NULL PRIMARY KEY,
  label       VARCHAR(50) NOT NULL,
  color       VARCHAR(20) NOT NULL DEFAULT 'secondary',   -- UI badge colour
  sort_order  INT NOT NULL DEFAULT 0,
  is_closed   TINYINT(1) NOT NULL DEFAULT 0,             -- closed = no longer "open" for duplicate check
  is_won      TINYINT(1) NOT NULL DEFAULT 0              -- counts as converted
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_number         VARCHAR(20)  NOT NULL,
  parent_lead_id      INT UNSIGNED NULL,                  -- re-enquiry of an earlier lead
  customer_id         INT UNSIGNED NULL,
  customer_name       VARCHAR(100) NOT NULL DEFAULT '',
  phone               VARCHAR(15)  NOT NULL,
  email               VARCHAR(150) NULL,
  category_id         INT UNSIGNED NULL,
  service_id          INT UNSIGNED NULL,
  appliance           VARCHAR(100) NULL,
  brand               VARCHAR(60)  NULL,
  problem_type        VARCHAR(150) NULL,
  description         TEXT NULL,
  address             VARCHAR(500) NULL,
  city                VARCHAR(80)  NULL,
  pincode             VARCHAR(10)  NULL,
  location_id         INT UNSIGNED NULL,
  preferred_date      DATE NULL,
  preferred_time      VARCHAR(40)  NULL,
  form_type           VARCHAR(20)  NOT NULL DEFAULT 'booking',   -- booking | callback | contact | admin
  source              VARCHAR(30)  NOT NULL DEFAULT 'website',
  utm_source          VARCHAR(150) NULL,
  utm_medium          VARCHAR(150) NULL,
  utm_campaign        VARCHAR(150) NULL,
  utm_term            VARCHAR(150) NULL,
  utm_content         VARCHAR(150) NULL,
  gclid               VARCHAR(255) NULL,
  fbclid              VARCHAR(255) NULL,
  landing_page        VARCHAR(500) NULL,
  referrer            VARCHAR(500) NULL,
  user_agent          VARCHAR(500) NULL,
  ip_address          VARCHAR(45)  NULL,
  status              VARCHAR(30)  NOT NULL DEFAULT 'new',
  priority            ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to         INT UNSIGNED NULL,
  technician_id       INT UNSIGNED NULL,
  enquiry_count       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_repeat_customer  TINYINT(1) NOT NULL DEFAULT 0,
  last_enquiry_at     DATETIME NULL,
  consent_at          DATETIME NULL,
  converted_at        DATETIME NULL,
  lost_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,                  -- NULL = created from website
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  UNIQUE KEY uq_leads_number (lead_number),
  KEY idx_leads_phone (phone),
  KEY idx_leads_status (status),
  KEY idx_leads_assigned (assigned_to),
  KEY idx_leads_service (service_id),
  KEY idx_leads_created (created_at),
  KEY idx_leads_technician (technician_id),
  KEY idx_leads_source (source),
  KEY idx_leads_status_created (status, created_at),
  KEY idx_leads_email (email),
  CONSTRAINT fk_leads_parent     FOREIGN KEY (parent_lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_category   FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_service    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_location   FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_status     FOREIGN KEY (status) REFERENCES lead_statuses(slug) ON UPDATE CASCADE,
  CONSTRAINT fk_leads_assigned   FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_technician FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every form submission, including repeats merged into an open lead
CREATE TABLE IF NOT EXISTS lead_enquiries (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id         INT UNSIGNED NOT NULL,
  service_id      INT UNSIGNED NULL,
  form_type       VARCHAR(20) NOT NULL,
  source          VARCHAR(30) NOT NULL,
  problem_type    VARCHAR(150) NULL,
  message         TEXT NULL,
  preferred_date  DATE NULL,
  preferred_time  VARCHAR(40) NULL,
  landing_page    VARCHAR(500) NULL,
  ip_address      VARCHAR(45) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_le_lead (lead_id),
  CONSTRAINT fk_le_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_le_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_status_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id     INT UNSIGNED NOT NULL,
  old_status  VARCHAR(30) NULL,
  new_status  VARCHAR(30) NOT NULL,
  changed_by  INT UNSIGNED NULL,                -- NULL = system
  note        VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lsh_lead (lead_id, created_at),
  CONSTRAINT fk_lsh_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lsh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_assignments (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id          INT UNSIGNED NOT NULL,
  assignment_type  ENUM('sales','support','technician') NOT NULL,
  assigned_to      INT UNSIGNED NULL,           -- users.id (sales/support)
  technician_id    INT UNSIGNED NULL,           -- technicians.id
  assigned_by      INT UNSIGNED NULL,           -- NULL = auto-assignment
  note             VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_lead (lead_id, created_at),
  KEY idx_la_user (assigned_to),
  KEY idx_la_tech (technician_id),
  CONSTRAINT fk_la_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_la_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_la_tech FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
  CONSTRAINT fk_la_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,                -- NULL = system note
  note        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ln_lead (lead_id, created_at),
  CONSTRAINT fk_ln_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_ln_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_followups (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id        INT UNSIGNED NOT NULL,
  assigned_to    INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  followup_date  DATE NOT NULL,
  followup_time  TIME NULL,
  type           ENUM('call','whatsapp','visit','payment_reminder','service_reminder','other') NOT NULL DEFAULT 'call',
  note           VARCHAR(500) NULL,
  status         ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  outcome        VARCHAR(500) NULL,
  completed_at   DATETIME NULL,
  completed_by   INT UNSIGNED NULL,
  overdue_notified_at DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lf_lead (lead_id),
  KEY idx_lf_due (status, followup_date),
  KEY idx_lf_user (assigned_to, status, followup_date),
  CONSTRAINT fk_lf_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lf_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_lf_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_lf_completed FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_submissions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  phone       VARCHAR(15)  NULL,
  email       VARCHAR(150) NULL,
  subject     VARCHAR(150) NULL,
  message     TEXT NULL,
  lead_id     INT UNSIGNED NULL,
  status      ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
  ip_address  VARCHAR(45)  NULL,
  user_agent  VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cs_status (status, created_at),
  CONSTRAINT fk_cs_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- BOOKINGS & PAYMENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS booking_statuses (
  slug                VARCHAR(30) NOT NULL PRIMARY KEY,
  label               VARCHAR(50) NOT NULL,
  color               VARCHAR(20) NOT NULL DEFAULT 'secondary',
  sort_order          INT NOT NULL DEFAULT 0,
  is_closed           TINYINT(1) NOT NULL DEFAULT 0,
  technician_can_set  TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30) NOT NULL,
  description   VARCHAR(255) NULL,
  discount_type ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  value         DECIMAL(10,2) NOT NULL,
  min_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_discount  DECIMAL(10,2) NULL,
  valid_from    DATE NULL,
  valid_to      DATE NULL,
  usage_limit   INT UNSIGNED NULL,
  used_count    INT UNSIGNED NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_number    VARCHAR(20) NOT NULL,
  lead_id           INT UNSIGNED NULL,
  customer_id       INT UNSIGNED NOT NULL,
  technician_id     INT UNSIGNED NULL,
  service_id        INT UNSIGNED NULL,
  scheduled_date    DATE NOT NULL,
  scheduled_time    VARCHAR(40) NULL,
  address           VARCHAR(500) NOT NULL,
  city              VARCHAR(80)  NULL,
  pincode           VARCHAR(10)  NULL,
  location_id       INT UNSIGNED NULL,
  status            VARCHAR(30) NOT NULL DEFAULT 'pending',
  estimated_amount  DECIMAL(10,2) NULL,
  quoted_amount     DECIMAL(10,2) NULL,
  discount_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
  coupon_id         INT UNSIGNED NULL,
  final_amount      DECIMAL(10,2) NULL,
  amount_paid       DECIMAL(10,2) NOT NULL DEFAULT 0,          -- cached sum of paid payments
  payment_status    ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  warranty_until    DATE NULL,
  notes             TEXT NULL,
  cancel_reason     VARCHAR(255) NULL,
  reschedule_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  started_at        DATETIME NULL,
  completed_at      DATETIME NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME NULL,
  UNIQUE KEY uq_bookings_number (booking_number),
  KEY idx_bookings_technician (technician_id),
  KEY idx_bookings_scheduled (scheduled_date),
  KEY idx_bookings_tech_date (technician_id, scheduled_date),
  KEY idx_bookings_status (status),
  KEY idx_bookings_customer (customer_id),
  KEY idx_bookings_lead (lead_id),
  CONSTRAINT fk_bookings_lead       FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_technician FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_service    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_location   FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_status     FOREIGN KEY (status) REFERENCES booking_statuses(slug) ON UPDATE CASCADE,
  CONSTRAINT fk_bookings_coupon     FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_status_history (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id     INT UNSIGNED NOT NULL,
  old_status     VARCHAR(30) NULL,
  new_status     VARCHAR(30) NOT NULL,
  changed_by     INT UNSIGNED NULL,
  note           VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bsh_booking (booking_id, created_at),
  CONSTRAINT fk_bsh_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_bsh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_number           VARCHAR(20) NOT NULL,
  booking_id               INT UNSIGNED NOT NULL,
  customer_id              INT UNSIGNED NOT NULL,
  amount                   DECIMAL(10,2) NOT NULL,
  payment_method           ENUM('cash','upi','card','bank_transfer','online','other') NOT NULL DEFAULT 'cash',
  transaction_id           VARCHAR(100) NULL,
  gateway                  VARCHAR(50)  NULL,            -- future: razorpay, phonepe…
  status                   ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  payment_date             DATETIME NULL,
  collected_by_user        INT UNSIGNED NULL,
  collected_by_technician  INT UNSIGNED NULL,
  notes                    VARCHAR(500) NULL,
  created_by               INT UNSIGNED NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_number (payment_number),
  KEY idx_payments_booking (booking_id),
  KEY idx_payments_customer (customer_id),
  KEY idx_payments_status_date (status, payment_date),
  CONSTRAINT fk_payments_booking  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_user     FOREIGN KEY (collected_by_user) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_tech     FOREIGN KEY (collected_by_technician) REFERENCES technicians(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_created  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- WEBSITE CONTENT
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS testimonials (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_name  VARCHAR(100) NOT NULL,
  location       VARCHAR(100) NULL,
  service_id     INT UNSIGNED NULL,
  rating         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  review         TEXT NOT NULL,
  photo          VARCHAR(255) NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_testimonials_active (is_active, sort_order),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_testimonials_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id  INT UNSIGNED NULL,               -- NULL = general FAQ
  question    VARCHAR(255) NOT NULL,
  answer      TEXT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_faq_question (question),
  KEY idx_faqs_service (service_id, is_active, sort_order),
  CONSTRAINT fk_faqs_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug             VARCHAR(120) NOT NULL,
  title            VARCHAR(160) NOT NULL,
  content          MEDIUMTEXT NULL,
  seo_title        VARCHAR(160) NULL,
  seo_description  VARCHAR(320) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- EMAIL QUEUE
-- ---------------------------------------------------------------------
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


-- =====================================================================
-- SEED DATA
-- =====================================================================

INSERT IGNORE INTO roles (id, name, slug, description, is_system) VALUES
 (1,'Super Admin','super_admin','Full access including permissions',1),
 (2,'Admin','admin','Full business access',1),
 (3,'Manager','manager','Leads, bookings, technicians, reports',1),
 (4,'Sales','sales','Assigned leads and follow-ups',1),
 (5,'Support','support','Customer support, leads and bookings',1),
 (6,'Technician','technician','Own assigned jobs only',1),
 (7,'Accountant','accountant','Payments and reports',1);

INSERT IGNORE INTO permissions (module, action, slug, label) VALUES
 ('dashboard','view','dashboard.view','View dashboard'),
 ('leads','view','leads.view','View leads'),
 ('leads','view_all','leads.view_all','View all leads (not only assigned)'),
 ('leads','create','leads.create','Create leads'),
 ('leads','edit','leads.edit','Edit leads'),
 ('leads','delete','leads.delete','Delete leads'),
 ('leads','assign','leads.assign','Assign leads'),
 ('leads','export','leads.export','Export leads'),
 ('customers','view','customers.view','View customers'),
 ('customers','create','customers.create','Create customers'),
 ('customers','edit','customers.edit','Edit customers'),
 ('customers','delete','customers.delete','Delete customers'),
 ('bookings','view','bookings.view','View bookings'),
 ('bookings','create','bookings.create','Create bookings'),
 ('bookings','edit','bookings.edit','Edit bookings'),
 ('bookings','delete','bookings.delete','Delete bookings'),
 ('technicians','view','technicians.view','View technicians'),
 ('technicians','create','technicians.create','Create technicians'),
 ('technicians','edit','technicians.edit','Edit technicians'),
 ('technicians','delete','technicians.delete','Delete technicians'),
 ('services','view','services.view','View services'),
 ('services','create','services.create','Create services'),
 ('services','edit','services.edit','Edit services'),
 ('services','delete','services.delete','Delete services'),
 ('payments','view','payments.view','View payments'),
 ('payments','create','payments.create','Record payments'),
 ('payments','edit','payments.edit','Edit payments'),
 ('payments','delete','payments.delete','Delete payments'),
 ('content','manage','content.manage','Manage testimonials, FAQs, pages, locations'),
 ('coupons','manage','coupons.manage','Manage coupons'),
 ('reports','view','reports.view','View reports'),
 ('reports','export','reports.export','Export reports'),
 ('staff','view','staff.view','View staff'),
 ('staff','create','staff.create','Create staff'),
 ('staff','edit','staff.edit','Edit staff'),
 ('staff','delete','staff.delete','Delete staff'),
 ('roles','manage','roles.manage','Manage roles & permissions'),
 ('settings','manage','settings.manage','Manage settings'),
 ('audit','view','audit.view','View audit log'),
 ('jobs','view_own','jobs.view_own','View own technician jobs'),
 ('jobs','update_own','jobs.update_own','Update own technician jobs');

-- Super Admin: everything
INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;
-- Admin: everything except permission management and technician-only app
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT 2, id FROM permissions WHERE slug NOT IN ('roles.manage','jobs.view_own','jobs.update_own');
-- Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT 3, id FROM permissions WHERE module IN ('dashboard','leads','customers','bookings','content','reports')
   OR slug IN ('technicians.view','technicians.edit','services.view','payments.view','payments.create','staff.view');
-- Sales
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT 4, id FROM permissions WHERE slug IN ('dashboard.view','leads.view','leads.create','leads.edit',
   'customers.view','customers.create','bookings.view','bookings.create','services.view');
-- Support
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT 5, id FROM permissions WHERE slug IN ('dashboard.view','leads.view','leads.view_all','leads.create','leads.edit',
   'customers.view','customers.edit','bookings.view','bookings.edit','services.view','technicians.view');
-- Technician
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT 6, id FROM permissions WHERE slug IN ('jobs.view_own','jobs.update_own');
-- Accountant
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT 7, id FROM permissions WHERE module IN ('payments','reports')
   OR slug IN ('dashboard.view','bookings.view','customers.view');

-- Admin user — password: Admin@12345 (change immediately)
INSERT IGNORE INTO users (id, role_id, name, email, phone, password_hash, status, password_changed_at) VALUES
 (1, 1, 'Super Admin', 'admin@mach.local', '8502837831',
  '$2y$10$JOf/OTZTkIJDJVUKl8YHl.K6C3e24RNGcI07LkiK9XmhxpJpInhSu', 'active', NOW());

INSERT IGNORE INTO lead_statuses (slug, label, color, sort_order, is_closed, is_won) VALUES
 ('new','New','primary',1,0,0),
 ('contacted','Contacted','info',2,0,0),
 ('follow_up','Follow Up','warning',3,0,0),
 ('scheduled','Scheduled','indigo',4,0,1),
 ('technician_assigned','Technician Assigned','indigo',5,0,1),
 ('visit_completed','Visit Completed','teal',6,0,1),
 ('quotation_sent','Quotation Sent','orange',7,0,1),
 ('payment_pending','Payment Pending','orange',8,0,1),
 ('in_progress','In Progress','info',9,0,1),
 ('completed','Completed','success',10,1,1),
 ('cancelled','Cancelled','secondary',11,1,0),
 ('not_interested','Not Interested','secondary',12,1,0),
 ('invalid','Invalid','danger',13,1,0);

INSERT IGNORE INTO booking_statuses (slug, label, color, sort_order, is_closed, technician_can_set) VALUES
 ('pending','Pending','secondary',1,0,0),
 ('confirmed','Confirmed','primary',2,0,0),
 ('technician_assigned','Technician Assigned','indigo',3,0,0),
 ('accepted','Accepted','indigo',4,0,1),
 ('on_the_way','On The Way','info',5,0,1),
 ('arrived','Arrived','info',6,0,1),
 ('inspection','Inspection','teal',7,0,1),
 ('quotation_sent','Quotation Sent','orange',8,0,1),
 ('approved','Customer Approved','teal',9,0,1),
 ('in_progress','In Progress','warning',10,0,1),
 ('payment_pending','Payment Pending','orange',11,0,1),
 ('completed','Completed','success',12,1,1),
 ('rescheduled','Rescheduled','warning',13,0,0),
 ('cancelled','Cancelled','danger',14,1,0);

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
 ('business_name','MACH Home Services','general'),
 ('business_tagline','Home Services','general'),
 ('phone','+91 85028 37831','contact'),
 ('whatsapp','918502837831','contact'),
 ('email','niteshksupport@gmail.com','contact'),
 ('address','Vaishali Nagar, Jaipur, Rajasthan 302021','contact'),
 ('city','Jaipur','contact'),
 ('working_hours','Mon–Sun, 8:00 AM – 9:00 PM','contact'),
 ('google_maps_url','https://maps.google.com/?q=Vaishali+Nagar+Jaipur','contact'),
 ('google_maps_embed','','contact'),
 ('logo',NULL,'branding'),
 ('favicon',NULL,'branding'),
 ('social_facebook','https://facebook.com/','social'),
 ('social_instagram','https://instagram.com/','social'),
 ('social_youtube','','social'),
 ('social_x','','social'),
 ('social_linkedin','','social'),
 ('rating_value','4.8','trust'),
 ('rating_count','1250','trust'),
 ('jobs_completed','12000','trust'),
 ('years_experience','8','trust'),
 ('technicians_count','45','trust'),
 ('booking_time_slots','08:00 AM – 10:00 AM|10:00 AM – 12:00 PM|12:00 PM – 02:00 PM|02:00 PM – 04:00 PM|04:00 PM – 06:00 PM|06:00 PM – 08:00 PM','leads'),
 ('booking_max_days_ahead','30','leads'),
 ('lead_auto_assign','0','leads'),
 ('default_lead_status','new','leads'),
 ('default_lead_source','website','leads'),
 ('notify_email','0','notifications'),
 ('notify_whatsapp','0','notifications'),
 ('notify_email_to','','notifications'),
 ('seo_default_title','AC, Chimney, Geyser & Appliance Repair in Jaipur','seo'),
 ('seo_default_description','Doorstep AC, chimney, geyser, washing machine, refrigerator and RO repair in Jaipur. Verified technicians, transparent pricing, genuine parts and service warranty.','seo');

-- Locations: city + areas
INSERT IGNORE INTO locations (id, parent_id, type, name, slug, sort_order) VALUES (1, NULL, 'city', 'Jaipur', 'jaipur', 1);
INSERT IGNORE INTO locations (parent_id, type, name, slug, pincode, sort_order) VALUES
 (1,'area','Vaishali Nagar','vaishali-nagar','302021',1),
 (1,'area','Mansarovar','mansarovar','302020',2),
 (1,'area','Malviya Nagar','malviya-nagar','302017',3),
 (1,'area','Jagatpura','jagatpura','302017',4),
 (1,'area','C-Scheme','c-scheme','302001',5),
 (1,'area','Raja Park','raja-park','302004',6),
 (1,'area','Tonk Road','tonk-road','302015',7),
 (1,'area','Sodala','sodala','302006',8),
 (1,'area','Pratap Nagar','pratap-nagar','302033',9),
 (1,'area','Bani Park','bani-park','302016',10),
 (1,'area','Jhotwara','jhotwara','302012',11),
 (1,'area','Sanganer','sanganer','302029',12);

INSERT IGNORE INTO service_categories (id, name, slug, icon, short_description, appliance_types, sort_order) VALUES
 (1,'AC Services','ac-services','bi-snow2','Repair, service, gas filling and installation for all AC types.','Split AC, Window AC, Inverter AC, Cassette AC, Tower AC',1),
 (2,'Chimney Services','chimney-services','bi-cloud-haze2','Deep cleaning and repair for kitchen chimneys.','Auto-clean Chimney, Baffle Filter Chimney, Mesh Filter Chimney',2),
 (3,'Geyser Services','geyser-services','bi-thermometer-sun','Electric, gas and instant geyser repair & installation.','Storage Geyser, Instant Geyser, Gas Geyser, Solar Water Heater',3),
 (4,'Washing Machine Services','washing-machine-services','bi-disc','Front load, top load and semi-automatic machines.','Front Load, Top Load, Semi-Automatic, Washer Dryer',4),
 (5,'Refrigerator Services','refrigerator-services','bi-snow','Cooling, gas, compressor and door-seal problems fixed.','Single Door, Double Door, Side-by-Side, Deep Freezer',5),
 (6,'RO Services','ro-services','bi-droplet-half','Water purifier service, filter change and repair.','RO, RO + UV, UV, Under-sink RO',6),
 (7,'Microwave Services','microwave-services','bi-grid-1x2','Solo, grill and convection microwave repair.','Solo, Grill, Convection, OTG',7),
 (8,'Cooler Services','cooler-services','bi-fan','Air cooler motor, pump and pad replacement.','Desert Cooler, Tower Cooler, Personal Cooler',8),
 (9,'TV Services','tv-services','bi-tv','LED, LCD and Smart TV repair and wall mounting.','LED TV, Smart TV, LCD TV, Android TV',9),
 (10,'Electrical Services','electrical-services','bi-lightning-charge','Small appliance and home electrical repairs.','Iron, Mixer Grinder, Induction, Fan, Inverter, Other',10);

INSERT IGNORE INTO services
 (category_id, name, slug, icon, short_description, description, starting_price, price_note, duration, warranty, is_featured, is_emergency, sort_order, seo_title, seo_description, seo_keywords)
SELECT c.id, d.name, d.slug, d.icon, d.short_description, d.description, d.price, d.price_note, d.duration, d.warranty, d.featured, d.emergency, d.sort_order, d.seo_title, d.seo_description, d.seo_keywords
FROM service_categories c JOIN (
  SELECT 'ac-services' cat, 'AC Repair' name, 'ac-repair' slug, 'bi-snow2' icon,
   'Not cooling, leaking, tripping or noisy? Same-day AC repair by certified technicians.' short_description,
   'Our AC repair technicians diagnose and fix all split, window and inverter AC problems at your doorstep. We carry common spare parts so most repairs are completed in a single visit.\n\n## What we check\n- Gas pressure and leaks\n- PCB and sensors\n- Compressor, capacitor and fan motor\n- Drain line and water leakage\n\nYou get the price before any work starts. All repairs come with a service warranty.' description,
   399 price, 'visit + inspection' price_note, '60–90 min' duration, '30 days' warranty, 1 featured, 1 emergency, 1 sort_order,
   'AC Repair in Jaipur | Same-Day Doorstep Service' seo_title,
   'Expert AC repair in Jaipur for split, window & inverter AC. Gas leak, cooling, PCB and noise issues fixed same day. Starting ₹399 with 30-day warranty.' seo_description,
   'ac repair jaipur, split ac repair, ac not cooling, ac mechanic near me' seo_keywords
  UNION ALL SELECT 'ac-services','AC Service','ac-service','bi-wind',
   'Deep jet-pump cleaning for better cooling and lower electricity bills.',
   'A complete AC service with foam and jet-pump cleaning of indoor and outdoor units. Regular servicing improves cooling, reduces power consumption and prevents breakdowns.\n\n## Included\n- Filter, coil and blower cleaning\n- Outdoor unit wash\n- Drain pipe flush\n- Gas pressure and performance check',
   499,NULL,'45–60 min','15 days',1,0,2,
   'AC Service in Jaipur | Jet Pump Deep Cleaning','Professional AC servicing in Jaipur with jet-pump cleaning of indoor and outdoor units. Better cooling, lower bills. Book online.','ac service jaipur, ac cleaning, split ac service'
  UNION ALL SELECT 'ac-services','AC Installation','ac-installation','bi-tools',
   'Split and window AC installation and uninstallation with proper vacuuming.',
   'Safe and neat AC installation by trained technicians, including bracket fitting, copper piping, vacuuming and testing.\n\n## Included\n- Indoor and outdoor unit mounting\n- Vacuuming and leak test\n- Drain pipe routing\n- Performance test after installation',
   1199,'standard installation','90–120 min','90 days',1,0,3,
   'AC Installation in Jaipur | Split & Window AC','Split and window AC installation in Jaipur with vacuuming and leak test. Transparent charges and 90-day installation warranty.','ac installation jaipur, split ac installation, ac fitting'
  UNION ALL SELECT 'ac-services','AC Gas Filling','ac-gas-filling','bi-speedometer2',
   'Leak detection, repair and complete gas top-up or refill.',
   'Low gas is the most common reason an AC stops cooling. We first find and fix the leak, then refill with the correct refrigerant (R32, R410A, R22) to the manufacturer pressure.\n\n## Process\n- Leak detection and repair\n- Vacuuming\n- Gas refill by weight and pressure\n- Cooling performance test',
   2499,'including leak check','90–120 min','30 days',1,0,4,
   'AC Gas Filling in Jaipur | R32, R410A, R22 Refill','AC gas refilling in Jaipur with leak detection and repair. R32, R410A and R22 refrigerants. Transparent pricing and warranty.','ac gas filling jaipur, ac gas refill, ac gas leak'
  UNION ALL SELECT 'ac-services','Split AC Repair','split-ac-repair','bi-snow2',
   'All split AC issues including inverter PCB, sensor and fan problems.',
   'Specialised repair for split ACs of all brands. From error codes to PCB faults and water leaking from the indoor unit, we fix it at your home.',
   399,'visit + inspection','60–90 min','30 days',0,0,5,
   'Split AC Repair in Jaipur | All Brands','Split AC repair in Jaipur for LG, Voltas, Daikin, Samsung and more. Error codes, PCB and leakage fixed at home.','split ac repair jaipur, inverter ac repair'
  UNION ALL SELECT 'ac-services','Window AC Repair','window-ac-repair','bi-snow2',
   'Window AC cooling, noise, compressor and switch problems fixed.',
   'Window AC repair covering cooling issues, compressor and capacitor faults, noisy fans and switch problems.',
   349,'visit + inspection','60 min','30 days',0,0,6,
   'Window AC Repair in Jaipur','Window AC repair at your doorstep in Jaipur. Cooling, compressor, fan and noise issues fixed by experts.','window ac repair jaipur'
  UNION ALL SELECT 'chimney-services','Chimney Repair','chimney-repair','bi-cloud-haze2',
   'Suction, motor, auto-clean and control panel repairs.',
   'Low suction, loud noise, motor failure or touch panel not working? Our technicians repair all chimney brands at home.\n\n## Common repairs\n- Motor and capacitor replacement\n- Control panel and switch repair\n- Auto-clean function repair\n- Light and wiring faults',
   349,'visit + inspection','45–60 min','30 days',1,0,1,
   'Chimney Repair in Jaipur | All Brands','Kitchen chimney repair in Jaipur for motor, suction, auto-clean and panel faults. Same-day doorstep service.','chimney repair jaipur, kitchen chimney service'
  UNION ALL SELECT 'chimney-services','Chimney Cleaning','chimney-cleaning','bi-stars',
   'Deep degreasing of filters, blower and hood for strong suction.',
   'Oil and grease build-up reduces suction and is a fire risk. Our deep cleaning removes grease from filters, blower, motor housing and hood.\n\n## Included\n- Filter degreasing\n- Blower and motor cleaning\n- Hood and panel cleaning\n- Suction test',
   899,'deep cleaning','60–90 min','15 days',1,0,2,
   'Chimney Cleaning in Jaipur | Deep Degreasing','Professional kitchen chimney deep cleaning in Jaipur. Filters, blower and hood degreased for better suction.','chimney cleaning jaipur, chimney deep cleaning'
  UNION ALL SELECT 'geyser-services','Geyser Repair','geyser-repair','bi-thermometer-sun',
   'No hot water, tripping or leakage? Geyser repair at home.',
   'We repair storage, instant and gas geysers — heating element, thermostat, leakage and tripping problems.\n\n## Common repairs\n- Heating element replacement\n- Thermostat and cut-out\n- Tank or valve leakage\n- Descaling',
   299,'visit + inspection','45–60 min','30 days',1,1,1,
   'Geyser Repair in Jaipur | Same-Day Service','Geyser repair in Jaipur for no hot water, leakage, tripping and thermostat faults. All brands, doorstep service.','geyser repair jaipur, water heater repair'
  UNION ALL SELECT 'geyser-services','Geyser Installation','geyser-installation','bi-tools',
   'Safe geyser installation and uninstallation with fittings.',
   'Professional geyser installation with wall mounting, inlet/outlet connections, pressure valve and electrical safety check.',
   499,'standard installation','60 min','90 days',0,0,2,
   'Geyser Installation in Jaipur','Geyser installation and uninstallation in Jaipur with proper fittings and safety check.','geyser installation jaipur'
  UNION ALL SELECT 'washing-machine-services','Washing Machine Repair','washing-machine-repair','bi-disc',
   'Not spinning, draining or starting? Front & top load repair.',
   'Repair for fully automatic and semi-automatic washing machines of all brands.\n\n## Common repairs\n- Drum not spinning\n- Water not draining or filling\n- Noise and vibration\n- PCB and error codes\n- Door lock problems',
   349,'visit + inspection','60–90 min','30 days',1,0,1,
   'Washing Machine Repair in Jaipur | Front & Top Load','Washing machine repair in Jaipur for front load, top load and semi-automatic machines. Drain, spin and PCB faults fixed.','washing machine repair jaipur'
  UNION ALL SELECT 'refrigerator-services','Refrigerator Repair','refrigerator-repair','bi-snow',
   'Cooling, gas leak, compressor and thermostat repair.',
   'Single door, double door and side-by-side refrigerator repair at home.\n\n## Common repairs\n- Not cooling or over-cooling\n- Gas leak and refill\n- Compressor and relay\n- Thermostat and defrost faults\n- Door gasket replacement',
   349,'visit + inspection','60–90 min','30 days',1,1,1,
   'Refrigerator Repair in Jaipur | Fridge Repair at Home','Refrigerator repair in Jaipur for cooling, gas, compressor and thermostat problems. All brands, same-day service.','fridge repair jaipur, refrigerator repair'
  UNION ALL SELECT 'ro-services','RO Repair','ro-repair','bi-droplet-half',
   'RO service, filter and membrane change, leakage repair.',
   'Keep your drinking water safe. We service and repair all RO and UV water purifiers.\n\n## Included\n- Filter and membrane replacement\n- Pump and SMPS repair\n- Leakage fixing\n- TDS check',
   299,'visit + inspection','45–60 min','30 days',1,0,1,
   'RO Repair & Service in Jaipur','RO water purifier repair and service in Jaipur. Filter change, membrane, pump and leakage repair at home.','ro repair jaipur, water purifier service'
  UNION ALL SELECT 'microwave-services','Microwave Repair','microwave-repair','bi-grid-1x2',
   'Not heating, sparking or turntable issues fixed.',
   'Solo, grill and convection microwave repair — magnetron, fuse, door switch and touch panel problems.',
   299,'visit + inspection','45–60 min','30 days',0,0,1,
   'Microwave Repair in Jaipur','Microwave oven repair in Jaipur for heating, sparking, panel and turntable problems.','microwave repair jaipur'
  UNION ALL SELECT 'cooler-services','Cooler Repair','cooler-repair','bi-fan',
   'Motor, pump, pad and wiring repair for air coolers.',
   'Air cooler repair including motor rewinding, pump replacement, honeycomb pad change and wiring.',
   249,'visit + inspection','45 min','15 days',0,0,1,
   'Air Cooler Repair in Jaipur','Air cooler repair in Jaipur — motor, pump, pads and wiring. Quick doorstep service.','cooler repair jaipur'
  UNION ALL SELECT 'tv-services','TV Repair','tv-repair','bi-tv',
   'LED and Smart TV display, sound and power problems.',
   'LED, LCD and Smart TV repair for no display, lines on screen, no sound, power and board faults. Wall mounting also available.',
   399,'visit + inspection','60 min','30 days',0,0,1,
   'TV Repair in Jaipur | LED & Smart TV','LED and Smart TV repair in Jaipur. Display, backlight, sound and board repair at home.','tv repair jaipur, led tv repair'
  UNION ALL SELECT 'electrical-services','Electrical Appliance Repair','electrical-appliance-repair','bi-lightning-charge',
   'Iron, mixer, induction, fan, inverter and other appliances.',
   'Repairs for everyday electrical appliances and home electrical faults by qualified electricians.',
   199,'visit + inspection','30–60 min','15 days',0,0,1,
   'Electrical Appliance Repair in Jaipur','Mixer, iron, induction, fan, inverter and other appliance repair in Jaipur. Doorstep electrician service.','appliance repair jaipur, electrician jaipur'
) d ON d.cat = c.slug;

INSERT IGNORE INTO service_problems (service_id, name, sort_order)
SELECT s.id, p.name, p.sort_order FROM services s JOIN (
            SELECT 'ac-repair' slug, 'Not cooling' name, 1 sort_order
  UNION ALL SELECT 'ac-repair','Water leakage',2 UNION ALL SELECT 'ac-repair','Gas leak',3
  UNION ALL SELECT 'ac-repair','Making noise',4 UNION ALL SELECT 'ac-repair','Not turning on',5
  UNION ALL SELECT 'ac-repair','Error code on display',6
  UNION ALL SELECT 'ac-service','Routine servicing',1 UNION ALL SELECT 'ac-service','Bad smell',2 UNION ALL SELECT 'ac-service','Low cooling',3
  UNION ALL SELECT 'ac-installation','New installation',1 UNION ALL SELECT 'ac-installation','Uninstallation',2 UNION ALL SELECT 'ac-installation','Re-installation / shifting',3
  UNION ALL SELECT 'ac-gas-filling','Gas top-up',1 UNION ALL SELECT 'ac-gas-filling','Full gas refill',2 UNION ALL SELECT 'ac-gas-filling','Leak repair',3
  UNION ALL SELECT 'split-ac-repair','Not cooling',1 UNION ALL SELECT 'split-ac-repair','Indoor unit leaking',2 UNION ALL SELECT 'split-ac-repair','PCB / error code',3
  UNION ALL SELECT 'window-ac-repair','Not cooling',1 UNION ALL SELECT 'window-ac-repair','Compressor not starting',2 UNION ALL SELECT 'window-ac-repair','Noise / vibration',3
  UNION ALL SELECT 'chimney-repair','Low suction',1 UNION ALL SELECT 'chimney-repair','Motor not working',2 UNION ALL SELECT 'chimney-repair','Loud noise',3
  UNION ALL SELECT 'chimney-repair','Panel / buttons not working',4 UNION ALL SELECT 'chimney-repair','Light not working',5
  UNION ALL SELECT 'chimney-cleaning','Deep cleaning',1 UNION ALL SELECT 'chimney-cleaning','Oil dripping',2 UNION ALL SELECT 'chimney-cleaning','Filter cleaning',3
  UNION ALL SELECT 'geyser-repair','No hot water',1 UNION ALL SELECT 'geyser-repair','Water leakage',2 UNION ALL SELECT 'geyser-repair','MCB tripping',3
  UNION ALL SELECT 'geyser-repair','Water too hot',4 UNION ALL SELECT 'geyser-repair','Noise from tank',5
  UNION ALL SELECT 'geyser-installation','New installation',1 UNION ALL SELECT 'geyser-installation','Uninstallation',2
  UNION ALL SELECT 'washing-machine-repair','Not spinning',1 UNION ALL SELECT 'washing-machine-repair','Not draining',2 UNION ALL SELECT 'washing-machine-repair','Water not filling',3
  UNION ALL SELECT 'washing-machine-repair','Noise / vibration',4 UNION ALL SELECT 'washing-machine-repair','Not starting',5 UNION ALL SELECT 'washing-machine-repair','Door lock issue',6
  UNION ALL SELECT 'refrigerator-repair','Not cooling',1 UNION ALL SELECT 'refrigerator-repair','Over cooling / ice build-up',2 UNION ALL SELECT 'refrigerator-repair','Gas leak',3
  UNION ALL SELECT 'refrigerator-repair','Compressor noise',4 UNION ALL SELECT 'refrigerator-repair','Water leakage',5 UNION ALL SELECT 'refrigerator-repair','Door seal broken',6
  UNION ALL SELECT 'ro-repair','Filter change',1 UNION ALL SELECT 'ro-repair','Water leakage',2 UNION ALL SELECT 'ro-repair','Low water flow',3
  UNION ALL SELECT 'ro-repair','Bad taste / high TDS',4 UNION ALL SELECT 'ro-repair','Not starting',5
  UNION ALL SELECT 'microwave-repair','Not heating',1 UNION ALL SELECT 'microwave-repair','Sparking',2 UNION ALL SELECT 'microwave-repair','Turntable not rotating',3 UNION ALL SELECT 'microwave-repair','Buttons not working',4
  UNION ALL SELECT 'cooler-repair','Motor not working',1 UNION ALL SELECT 'cooler-repair','Pump not working',2 UNION ALL SELECT 'cooler-repair','Pad replacement',3
  UNION ALL SELECT 'tv-repair','No display',1 UNION ALL SELECT 'tv-repair','Lines on screen',2 UNION ALL SELECT 'tv-repair','No sound',3 UNION ALL SELECT 'tv-repair','Wall mounting',4
  UNION ALL SELECT 'electrical-appliance-repair','Mixer / grinder',1 UNION ALL SELECT 'electrical-appliance-repair','Iron',2 UNION ALL SELECT 'electrical-appliance-repair','Induction cooktop',3
  UNION ALL SELECT 'electrical-appliance-repair','Fan',4 UNION ALL SELECT 'electrical-appliance-repair','Inverter',5
) p ON p.slug = s.slug;

INSERT IGNORE INTO service_prices (service_id, label, price, price_type, note, sort_order)
SELECT s.id, p.label, p.price, p.price_type, p.note, p.sort_order FROM services s JOIN (
            SELECT 'ac-repair' slug, 'Visit & inspection' label, 399 price, 'fixed' price_type, 'Adjusted in final bill if repaired' note, 1 sort_order
  UNION ALL SELECT 'ac-repair','Capacitor replacement',650,'starting','Part included',2
  UNION ALL SELECT 'ac-repair','PCB repair',1499,'starting','Depends on model',3
  UNION ALL SELECT 'ac-gas-filling','Split AC gas refill (1–1.5 ton)',2499,'starting','Includes leak check',1
  UNION ALL SELECT 'ac-gas-filling','Split AC gas refill (2 ton)',2999,'starting','Includes leak check',2
  UNION ALL SELECT 'ac-service','Split AC jet service',499,'fixed',NULL,1
  UNION ALL SELECT 'ac-service','Window AC jet service',449,'fixed',NULL,2
  UNION ALL SELECT 'ac-installation','Split AC installation',1199,'starting','Copper pipe extra per ft',1
  UNION ALL SELECT 'ac-installation','Split AC uninstallation',599,'fixed',NULL,2
) p ON p.slug = s.slug;

INSERT IGNORE INTO testimonials (id, customer_name, location, rating, review, sort_order) VALUES
 (1,'Rohit Sharma','Vaishali Nagar',5,'AC stopped cooling in peak summer. Technician came within 2 hours, found a gas leak, fixed it and explained everything. Very professional.',1),
 (2,'Neha Agarwal','Mansarovar',5,'Chimney deep cleaning was excellent — suction is like new. On time, neat work and fair price.',2),
 (3,'Vikram Singh','Malviya Nagar',5,'Booked geyser repair online at night and got a call next morning. Heating element replaced in 40 minutes.',3),
 (4,'Priya Mehta','Jagatpura',4,'Washing machine drain issue fixed quickly. Price was told before starting, no hidden charges.',4),
 (5,'Anil Gupta','C-Scheme',5,'Fridge was not cooling. Technician diagnosed a relay problem and fixed it the same day. Recommended.',5),
 (6,'Sunita Jain','Raja Park',5,'Regular AC servicing for 3 units — they were quick, clean and polite. Will book again.',6);

INSERT IGNORE INTO faqs (question, answer, sort_order) VALUES
 ('How quickly can a technician reach me?','In most Jaipur areas we reach within 60–120 minutes for urgent repairs. You can also pick a preferred date and time slot while booking.',1),
 ('Is there a visiting or inspection charge?','Yes, a small visit and inspection charge applies. If you go ahead with the repair, it is adjusted in the final bill.',2),
 ('Will I know the price before the repair?','Always. The technician inspects the appliance and shares the exact quotation. Work starts only after your approval.',3),
 ('Do you give a warranty on repairs?','Yes. Every repair includes a service warranty (usually 30 days) and spare parts carry their own warranty.',4),
 ('Do you use genuine spare parts?','We use genuine or high-quality OEM-equivalent parts and show you the old part after replacement.',5),
 ('Which brands do you repair?','All major brands including LG, Samsung, Voltas, Daikin, Whirlpool, Godrej, IFB, Bosch, Haier, Blue Star, Kent, Faber, Elica and more.',6),
 ('How can I pay?','Cash, UPI, card or bank transfer after the job is completed to your satisfaction.',7),
 ('Which areas do you cover?','We cover Jaipur including Vaishali Nagar, Mansarovar, Malviya Nagar, Jagatpura, C-Scheme, Raja Park, Tonk Road and nearby areas.',8);

INSERT IGNORE INTO pages (slug, title, content, seo_title, seo_description) VALUES
 ('privacy-policy','Privacy Policy',
  'This policy explains how we collect and use your information when you use our website or book a service.\n\n## Information we collect\n- Name, phone number, email and address you share in forms\n- Appliance and problem details\n- Technical data such as IP address, browser and the page you came from, used to prevent spam and measure our advertising\n\n## How we use it\n- To contact you about your enquiry and schedule the service\n- To send booking updates and service reminders\n- To improve our website and services\n\nWe do not sell your personal information. We share it only with the technician assigned to your job and with service providers who help us run our business.\n\n## Your choices\nYou can ask us to update or delete your information at any time by contacting us.',
  'Privacy Policy','How we collect, use and protect your personal information.'),
 ('terms','Terms & Conditions',
  'By booking a service with us you agree to the following terms.\n\n## Bookings\n- Preferred date and time are subject to technician availability. We will confirm by phone or WhatsApp.\n- A visit and inspection charge applies and is adjusted in the final bill if you proceed with the repair.\n\n## Pricing\n- The technician shares the final quotation after inspection. Work begins only after your approval.\n- Spare parts are charged separately at actual cost.\n\n## Warranty\n- Service warranty covers the same problem on the same part for the stated period.\n- Warranty does not cover physical damage, voltage fluctuation or repairs by others.',
  'Terms & Conditions','Terms and conditions for booking home appliance services.'),
 ('refund-policy','Refund Policy',
  'We want you to be satisfied with every service.\n\n## Cancellations\n- You can cancel or reschedule free of charge before the technician leaves for your location.\n\n## Refunds\n- If an advance was paid and the service could not be delivered, the full amount is refunded within 5–7 working days.\n- If a repaired problem returns within the warranty period, we fix it again free of charge. If it cannot be fixed, the service charge is refunded.\n\nTo request a refund, contact us with your booking number.',
  'Refund Policy','Cancellation and refund policy for our services.');

INSERT IGNORE INTO coupons (code, description, discount_type, value, min_amount, max_discount, is_active) VALUES
 ('WELCOME100','₹100 off on first service','flat',100,499,NULL,1);

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

-- Service quality tables and settings (feedback, complaints)
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
