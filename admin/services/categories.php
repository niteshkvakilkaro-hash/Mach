<?php
/** Service categories (AC Services, Chimney Services…). Order here = order on the website. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

crud_run([
    'path' => 'admin/services/categories', 'table' => 'service_categories', 'module' => 'service_categories', 'nav' => 'services.categories', 'icon' => 'bi-grid',
    'title' => 'Service categories', 'singular' => 'Category',
    'perm' => ['view' => 'services.view', 'create' => 'services.create', 'edit' => 'services.edit', 'delete' => 'services.delete'],
    'fields' => [
        'name'              => ['label' => 'Name', 'required' => true, 'max' => 100, 'col' => 6, 'placeholder' => 'e.g. AC Services'],
        'slug'              => ['label' => 'URL slug', 'type' => 'slug', 'from' => 'name', 'unique' => true, 'max' => 120, 'col' => 6, 'help' => 'Leave empty to create from the name.'],
        'icon'              => ['label' => 'Icon', 'max' => 60, 'col' => 6, 'placeholder' => 'bi-snow2', 'help' => 'A Bootstrap Icons name, e.g. bi-snow2, bi-fire, bi-droplet — see icons.getbootstrap.com'],
        'short_description' => ['label' => 'Short description', 'max' => 300, 'col' => 6],
        'appliance_types'   => ['label' => 'Appliance types (comma separated)', 'max' => 500, 'placeholder' => 'Split AC, Window AC, Inverter AC',
                                'help' => 'Shown in the “Appliance type” dropdown of the booking form.'],
        'is_active'         => ['label' => 'Active (shown on website)', 'type' => 'checkbox', 'default' => 1],
    ],
    'validate' => function ($d, $errors) {
        if ($d['icon'] !== null && !preg_match('/^bi-[a-z0-9-]+$/', $d['icon'])) $errors['icon'] = 'Use an icon name like bi-snow2.';
        return [$d, $errors];
    },
    'columns' => ['name' => 'Category', 'services' => 'Services', 'appliance_types' => 'Appliance types'],
    'render' => [
        'name'            => fn($r) => '<span class="icon-tile icon-tile-xs"><i class="bi ' . e($r['icon'] ?: 'bi-tools') . '"></i></span> <strong>' . e($r['name']) . '</strong>',
        'services'        => fn($r) => '<a href="' . e(url('admin/services?category=' . $r['id'])) . '">' . (int) db_value('SELECT COUNT(*) FROM services WHERE category_id = ?', [$r['id']]) . ' services</a>',
        'appliance_types' => fn($r) => '<small class="text-muted-2">' . e($r['appliance_types']) . '</small>',
    ],
    'describe' => fn($r) => $r['name'] ?? '',
    'search' => ['name'], 'order' => 'sort_order, name', 'sortable' => true, 'toggle' => 'is_active',
    'can_delete' => fn($r) => db_value('SELECT 1 FROM services WHERE category_id = ? LIMIT 1', [$r['id']]) ? 'Move or delete this category’s services first.' : null,
]);
