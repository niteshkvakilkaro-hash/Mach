-- =====================================================================
-- Remove ALL demo / test data before going live.
-- Run ONCE on the live database (phpMyAdmin → select your database → SQL tab → paste → Go).
--
-- DELETES: every lead, enquiry, note, follow-up, booking, payment, customer, notification,
--          audit log entry, contact message and number counter (next lead = LEAD-<year>-000001),
--          the 4 demo technicians and the demo logins neha@mach.local / ramesh@mach.local.
-- KEEPS:   services, categories, prices, problems, locations, FAQs, testimonials, pages,
--          coupons, settings, roles, permissions and your admin account.
--
-- Do NOT run this after real customers start using the site — it would delete their data too.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE payments;
TRUNCATE TABLE booking_status_history;
TRUNCATE TABLE bookings;
TRUNCATE TABLE lead_enquiries;
TRUNCATE TABLE lead_status_history;
TRUNCATE TABLE lead_assignments;
TRUNCATE TABLE lead_notes;
TRUNCATE TABLE lead_followups;
TRUNCATE TABLE contact_submissions;
TRUNCATE TABLE leads;
TRUNCATE TABLE customers;
TRUNCATE TABLE notifications;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE rate_limits;
TRUNCATE TABLE sequences;
TRUNCATE TABLE email_queue;
TRUNCATE TABLE complaint_updates;
TRUNCATE TABLE complaints;
TRUNCATE TABLE feedback;

-- Demo technicians (phones 7000100001–7000100004) and their links
DELETE FROM technician_services  WHERE technician_id IN (SELECT id FROM technicians WHERE phone LIKE '70001000%');
DELETE FROM technician_locations WHERE technician_id IN (SELECT id FROM technicians WHERE phone LIKE '70001000%');
DELETE FROM technicians WHERE phone LIKE '70001000%';

-- Demo logins created while testing
DELETE FROM users WHERE email IN ('neha@mach.local', 'ramesh@mach.local');

SET FOREIGN_KEY_CHECKS = 1;

-- Check: all of these should be 0
SELECT (SELECT COUNT(*) FROM leads) AS leads, (SELECT COUNT(*) FROM bookings) AS bookings,
       (SELECT COUNT(*) FROM payments) AS payments, (SELECT COUNT(*) FROM customers) AS customers,
       (SELECT COUNT(*) FROM technicians) AS technicians;
