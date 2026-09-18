-- Phase 5: remember when an overdue follow-up was notified, so it is only notified once.
USE mach_crm;
ALTER TABLE lead_followups ADD COLUMN IF NOT EXISTS overdue_notified_at DATETIME NULL AFTER completed_by;
