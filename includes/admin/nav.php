<?php
/**
 * Admin sidebar definition. Items are shown only when the user has the permission.
 * 'ready' => false renders the item as "Soon" until its phase ships.
 */
return [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => 'admin/dashboard', 'perm' => 'dashboard.view', 'ready' => true],

    ['section' => 'CRM'],
    ['key' => 'leads', 'label' => 'Leads', 'icon' => 'bi-person-lines-fill', 'perm' => 'leads.view', 'ready' => true, 'badge' => 'new_leads', 'children' => [
        ['key' => 'leads.all', 'label' => 'All Leads', 'url' => 'admin/leads'],
        ['key' => 'leads.new', 'label' => 'New Leads', 'url' => 'admin/leads?status=new'],
        ['key' => 'leads.followups', 'label' => 'Follow Ups', 'url' => 'admin/leads/followups'],
        ['key' => 'leads.scheduled', 'label' => 'Scheduled', 'url' => 'admin/leads?status=scheduled'],
        ['key' => 'leads.completed', 'label' => 'Completed', 'url' => 'admin/leads?status=completed'],
        ['key' => 'leads.cancelled', 'label' => 'Cancelled', 'url' => 'admin/leads?status=cancelled'],
    ]],
    ['key' => 'complaints', 'label' => 'Complaints', 'icon' => 'bi-exclamation-diamond', 'url' => 'admin/complaints', 'perm' => 'complaints.view', 'ready' => true, 'badge' => 'open_complaints'],
    ['key' => 'feedback', 'label' => 'Feedback & ratings', 'icon' => 'bi-star-half', 'url' => 'admin/feedback', 'perm' => 'complaints.view', 'ready' => true],
    ['key' => 'customers', 'label' => 'Customers', 'icon' => 'bi-people', 'url' => 'admin/customers', 'perm' => 'customers.view', 'ready' => true],
    ['key' => 'bookings', 'label' => 'Bookings', 'icon' => 'bi-calendar2-check', 'url' => 'admin/bookings', 'perm' => 'bookings.view', 'ready' => true],
    ['key' => 'technicians', 'label' => 'Technicians', 'icon' => 'bi-person-gear', 'url' => 'admin/technicians', 'perm' => 'technicians.view', 'ready' => true],
    ['key' => 'payments', 'label' => 'Payments', 'icon' => 'bi-wallet2', 'url' => 'admin/payments', 'perm' => 'payments.view', 'ready' => true],

    ['section' => 'Catalogue & content'],
    ['key' => 'services', 'label' => 'Services', 'icon' => 'bi-tools', 'perm' => 'services.view', 'ready' => true, 'children' => [
        ['key' => 'services.categories', 'label' => 'Categories', 'url' => 'admin/services/categories'],
        ['key' => 'services.list', 'label' => 'Services', 'url' => 'admin/services'],
        ['key' => 'services.pricing', 'label' => 'Pricing', 'url' => 'admin/services/pricing'],
    ]],
    ['key' => 'locations', 'label' => 'Locations', 'icon' => 'bi-geo-alt', 'url' => 'admin/locations', 'perm' => 'content.manage', 'ready' => true],
    ['key' => 'testimonials', 'label' => 'Testimonials', 'icon' => 'bi-chat-quote', 'url' => 'admin/testimonials', 'perm' => 'content.manage', 'ready' => true],
    ['key' => 'faqs', 'label' => 'FAQs', 'icon' => 'bi-question-circle', 'url' => 'admin/faqs', 'perm' => 'content.manage', 'ready' => true],
    ['key' => 'pages', 'label' => 'Pages', 'icon' => 'bi-file-earmark-text', 'url' => 'admin/pages', 'perm' => 'content.manage', 'ready' => true],
    ['key' => 'coupons', 'label' => 'Coupons', 'icon' => 'bi-ticket-perforated', 'url' => 'admin/coupons', 'perm' => 'coupons.manage', 'ready' => true],

    ['section' => 'Insights & admin'],
    ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'url' => 'admin/reports', 'perm' => 'reports.view', 'ready' => true],
    ['key' => 'staff', 'label' => 'Staff', 'icon' => 'bi-person-badge', 'url' => 'admin/staff', 'perm' => 'staff.view', 'ready' => true],
    ['key' => 'settings', 'label' => 'Settings', 'icon' => 'bi-gear', 'url' => 'admin/settings', 'perm' => 'settings.manage', 'ready' => true],
    ['key' => 'audit', 'label' => 'Audit Log', 'icon' => 'bi-journal-text', 'url' => 'admin/audit-log', 'perm' => 'audit.view', 'ready' => true],
];
