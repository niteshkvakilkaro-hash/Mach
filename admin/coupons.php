<?php
/** Discount coupons. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

crud_run([
    'path' => 'admin/coupons', 'table' => 'coupons', 'module' => 'coupons', 'nav' => 'coupons', 'icon' => 'bi-ticket-perforated',
    'title' => 'Coupons', 'singular' => 'Coupon',
    'perm' => ['view' => 'coupons.manage', 'create' => 'coupons.manage', 'edit' => 'coupons.manage', 'delete' => 'coupons.manage'],
    'describe' => fn($r) => $r['code'] ?? '',
    'fields' => [
        'code'          => ['label' => 'Code', 'required' => true, 'max' => 30, 'unique' => true, 'col' => 6, 'placeholder' => 'e.g. MONSOON200'],
        'description'   => ['label' => 'Description', 'max' => 255, 'col' => 6],
        'discount_type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'flat', 'col' => 4, 'options' => ['flat' => 'Flat ₹ off', 'percent' => '% off']],
        'value'         => ['label' => 'Value', 'type' => 'decimal', 'required' => true, 'min' => 1, 'max' => 100000, 'col' => 4],
        'max_discount'  => ['label' => 'Max discount (₹)', 'type' => 'decimal', 'min' => 0, 'col' => 4, 'help' => 'For % coupons.'],
        'min_amount'    => ['label' => 'Minimum bill (₹)', 'type' => 'decimal', 'min' => 0, 'default' => '0', 'required' => true, 'col' => 4],
        'valid_from'    => ['label' => 'Valid from', 'type' => 'date', 'col' => 4],
        'valid_to'      => ['label' => 'Valid until', 'type' => 'date', 'col' => 4],
        'usage_limit'   => ['label' => 'Usage limit', 'type' => 'int', 'min' => 1, 'col' => 4, 'help' => 'Empty = unlimited.'],
        'is_active'     => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1, 'col' => 4],
    ],
    'validate' => function ($d, $errors) {
        $d['code'] = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $d['code']));
        if ($d['code'] === '' && !isset($errors['code'])) $errors['code'] = 'Use letters and numbers only.';
        if ($d['discount_type'] === 'percent' && $d['value'] > 100) $errors['value'] = 'Percent cannot be more than 100.';
        if ($d['valid_from'] && $d['valid_to'] && $d['valid_to'] < $d['valid_from']) $errors['valid_to'] = 'End date is before the start date.';
        return [$d, $errors];
    },
    'columns' => ['code' => 'Code', 'value' => 'Discount', 'valid_to' => 'Validity', 'used_count' => 'Used'],
    'render' => [
        'code'       => fn($r) => '<span class="mono fw-semibold">' . e($r['code']) . '</span><small class="d-block text-muted-2">' . e($r['description']) . '</small>',
        'value'      => fn($r) => $r['discount_type'] === 'percent'
            ? e(rtrim(rtrim($r['value'], '0'), '.')) . '%' . ($r['max_discount'] ? ' (max ' . e(format_inr($r['max_discount'])) . ')' : '')
            : e(format_inr($r['value'])) . ' off',
        'valid_to'   => fn($r) => ($r['valid_from'] ? e(date('d M', strtotime($r['valid_from']))) : 'Now') . ' → ' . ($r['valid_to'] ? e(date('d M Y', strtotime($r['valid_to']))) : 'no end')
            . ($r['valid_to'] && $r['valid_to'] < date('Y-m-d') ? ' <span class="status-badge sb-danger">Expired</span>' : ''),
        'used_count' => fn($r) => (int) $r['used_count'] . ($r['usage_limit'] ? ' / ' . (int) $r['usage_limit'] : ''),
    ],
    'search' => ['code', 'description'], 'order' => 'is_active DESC, id DESC', 'toggle' => 'is_active',
    'can_delete' => fn($r) => db_value('SELECT 1 FROM bookings WHERE coupon_id = ? LIMIT 1', [$r['id']]) ? 'This coupon is used on bookings. Deactivate it instead.' : null,
]);
