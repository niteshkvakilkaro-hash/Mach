# MACH Home Services — Architecture (Phase 1)

Home service website + lead generation + CRM + bookings + technicians + payments + admin panel.
Stack: PHP 8.2+, MySQL/MariaDB (InnoDB, utf8mb4), Bootstrap 5, vanilla JS (fetch/AJAX). No framework.

---

## 1. Folder structure

```
/mach
├── .htaccess                 Pretty URLs, blocks private folders, 404 fallback
├── index.php                 Home
├── about.php  services.php  service.php (/services/{slug})
├── book-service.php  contact.php  thank-you.php
├── page.php                  CMS pages: /privacy-policy /terms /refund-policy
├── sitemap.php (/sitemap.xml)  robots.php (/robots.txt)  404.php
│
├── config/                   (web access denied)
│   ├── app.php               env, base URL, timezone, upload limits
│   └── database.php          DB credentials
│
├── includes/                 (web access denied)
│   ├── bootstrap.php         Single entry: config, errors, session, headers, autoload
│   ├── db.php                PDO singleton + query helpers (prepared statements only)
│   ├── errors.php            Logging, dev/prod error display
│   ├── functions.php         Escaping, URLs, settings, phone, numbering, rate limit, notify, audit
│   ├── csrf.php              CSRF token helpers
│   ├── auth.php              (Phase 3) login, session guard, permission checks
│   ├── header.php footer.php Public layout
│   ├── partials/             Reusable UI: booking form, callback modal, service card
│   └── models/               All DB logic lives here (no SQL in page templates)
│       ├── content.php       services, categories, faqs, testimonials, locations, pages
│       └── leads.php         lead validation, duplicate check, creation, auto-assign
│
├── api/                      JSON endpoints ({success, message, data, errors})
│   ├── leads/create.php      Public: booking / callback / contact forms
│   ├── leads/…               (Phase 5) status, assign, notes, follow-ups
│   ├── bookings/  payments/  notifications/   (later phases)
│
├── admin/                    (Phase 3+) login, dashboard, leads/, customers/, technicians/,
│                             services/, bookings/, payments/, reports/, settings/, staff/
├── technician/               (Phase 7) technician job app (own jobs only)
│
├── assets/css/site.css  assets/js/site.js  assets/images/
├── uploads/                  User uploads (PHP execution disabled)
├── storage/logs/             Error logs (web access denied)
├── database/schema.sql       Full schema + seed data
└── docs/                     This document
```

## 2. ER diagram (text)

```
roles 1───* users *───1 (optional) technicians
roles *───* permissions            (role_permissions)

service_categories 1───* services 1───* service_problems
                                  1───* service_prices
locations (city) 1───* locations (area)          [self reference]

customers 1───* leads *───1 services
                leads *───1 lead_statuses
                leads *───1 users (assigned_to)
                leads *───1 technicians
                leads *───1 leads (parent_lead_id → re-enquiry chain)
                leads 1───* lead_enquiries        (every form submission)
                leads 1───* lead_status_history
                leads 1───* lead_assignments
                leads 1───* lead_notes
                leads 1───* lead_followups

leads 1───* bookings *───1 customers
            bookings *───1 technicians
            bookings *───1 services
            bookings *───1 booking_statuses
            bookings *───1 coupons
            bookings 1───* booking_status_history
            bookings 1───* payments *───1 customers

technicians *───* services   (technician_services)
technicians *───* locations  (technician_locations)

users 1───* notifications,  users 1───* audit_logs
testimonials, faqs (optional service_id), pages, settings, sequences, rate_limits,
contact_submissions (→ lead)
```

## 3. Tables

| Group | Tables |
|---|---|
| Access | `users`, `roles`, `permissions`, `role_permissions`, `rate_limits` |
| Catalogue | `service_categories`, `services`, `service_problems`, `service_prices`, `locations` |
| CRM | `leads`, `lead_statuses`, `lead_enquiries`, `lead_status_history`, `lead_assignments`, `lead_notes`, `lead_followups`, `customers`, `contact_submissions` |
| Operations | `technicians`, `technician_services`, `technician_locations`, `bookings`, `booking_statuses`, `booking_status_history`, `payments`, `coupons` |
| Content | `testimonials`, `faqs`, `pages`, `settings` |
| System | `notifications`, `audit_logs`, `sequences` |

## 4. Key relationships and rules

- **Lead ≠ customer.** A lead is an enquiry. A `customers` row is created (or reused, matched by
  phone — `customers.phone` is UNIQUE) when a lead is converted / a booking is made.
- **Duplicate logic.** On submit we look for an *open* lead (status where `is_closed = 0`) with the
  same phone and same service. If found, no new lead: the submission is stored in `lead_enquiries`,
  `enquiry_count` +1, admins notified "repeat enquiry". Otherwise a new lead is created and
  `is_repeat_customer` is set if the phone has history. Admin can later spawn a re-enquiry lead
  (`parent_lead_id`) from a closed one.
- **History is append-only.** Status changes → `*_status_history`; assignments → `lead_assignments`.
  Current value is also cached on the parent row for fast filtering.
- **Public numbers** (`LEAD-2026-000001`, `BK-2026-000001`, `PAY-…`, `CUS-…`) come from the
  `sequences` table (atomic, per year). Numeric IDs are never shown publicly.
- **Statuses are lookup tables** (`lead_statuses`, `booking_statuses`) so labels/colours can change
  without code changes. Foreign keys protect integrity.
- **Soft delete** (`deleted_at`) on leads, customers, bookings, technicians — audit-friendly.
- **Extensible later without redesign:** AMC/subscriptions (`amc_plans`, `customer_subscriptions`),
  spare parts & invoice lines (`booking_items`), GST (`invoices`, tax columns), wallet/referral
  (`wallet_transactions`, `referrals`), gateway fields on `payments`, `technicians.commission_percent`,
  `leads.gclid/fbclid` for ad-platform tracking.

## 5. Authentication architecture (Phase 3)

- Single `users` table for all staff; technicians who log in have `technicians.user_id`.
- `password_hash()` / `password_verify()`, rehash on login if needed.
- Login: CSRF check → rate limit (5 fails / 15 min per IP+email, `rate_limits`) →
  verify → `session_regenerate_id(true)` → store user id + role + permission list in session.
- Session cookie: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode; idle timeout 2h.
- Every admin page calls `require_permission('leads.view')`; every API calls the same + CSRF.
- Super Admin bypasses permission checks; `leads.view_all` decides if a user sees all leads or only
  leads assigned to them. Technicians only reach `/technician/*` and only their own bookings.
- Logout: clear session array, expire cookie, destroy session. Important actions → `audit_logs`.

## 6. Lead lifecycle

```
New → Contacted → Follow Up → Scheduled → Technician Assigned → Visit Completed
    → Quotation Sent → Payment Pending → In Progress → Completed
Any open state → Cancelled / Not Interested / Invalid   (closed states)
```
"Scheduled" creates a booking (and the customer record if missing).

## 7. Booking lifecycle (technician job flow)

```
Pending → Confirmed → Technician Assigned → Accepted → On The Way → Arrived → Inspection
        → Quotation Sent → Customer Approved → In Progress → Payment Pending → Completed
Side exits: Rescheduled (returns to Confirmed), Cancelled
```
`booking_statuses.technician_can_set` marks the steps a technician may set from the job app.

## 8. Roles

| Role | Scope |
|---|---|
| Super Admin | Everything, including roles & permissions |
| Admin | Everything except permission management |
| Manager | All leads, bookings, technicians, reports, content |
| Sales | Own/assigned leads, create bookings, follow-ups |
| Support | Leads & bookings edit, follow-ups, customers view |
| Technician | Own jobs only (view, update status, record collection) |
| Accountant | Payments, reports, bookings/customers read-only |

## 9. Customer website pages

`/` · `/about` · `/services` · `/services/{slug}` (17 seeded) · `/book-service` · `/contact` ·
`/thank-you` · `/privacy-policy` · `/terms` · `/refund-policy` · `/sitemap.xml` · `/robots.txt`

Conversion features: hero search + booking form, exit-intent "Before you go" callback popup,
sticky mobile Call/WhatsApp/Book bar, floating WhatsApp, emergency CTA, service areas, reviews,
FAQ, trust badges. SEO: per-page title/description/canonical/OG, JSON-LD (LocalBusiness,
Service, FAQPage, BreadcrumbList).

## 10. Admin panel pages (Phases 3–9)

```
/admin/login  /admin/dashboard
/admin/leads (all | new | follow-ups | scheduled | completed | cancelled)  /admin/leads/view?id=
/admin/customers  /admin/technicians  /admin/bookings  /admin/payments
/admin/services (categories | services | pricing | problems)  /admin/locations
/admin/testimonials  /admin/faqs  /admin/pages  /admin/coupons
/admin/complaints (open | overdue | mine | resolved)  /admin/complaints/view?number=  /admin/feedback
/admin/reports  /admin/staff  /admin/roles  /admin/settings  /admin/audit-log
/technician/ (jobs today, job detail, status update)
```

## Design system

Dark premium theme (per reference screenshots): background `#07051A`, surfaces `#100C26`,
gradient `#B78BFF → #F472B6 → #FDBA74`, violet `#8B7CF6`, text `#ECE9F8`, muted `#A39EBF`.
Font: Plus Jakarta Sans. Glass cards, 16–28px radii, soft glows, reduced-motion respected.

## Build phases

Done: 1 Architecture ✔ · 2 SQL schema ✔ · Customer website ✔ · 3 Auth + admin layout + dashboard ✔ · 5 Lead CRM ✔ ·
6 Bookings + customers ✔ · 7 Technicians + job app ✔ · 8 Payments ✔ · 4 Services & content admin ✔ · 9 Reports + staff + audit log ✔ · 10 Security review ✔ + deployment guide ✔ · Email (SMTP queue) ✔ ·
11 Service quality ✔ — post-job feedback link (/feedback?t=token, 4–5★ → Google review, low rating → auto complaint) and
complaint / warranty tickets (/complaint, CMP- numbers, SLA deadline + overdue alerts, owner, history, free warranty revisit booking)

Next: go live on Hostinger (docs/DEPLOYMENT.md)
