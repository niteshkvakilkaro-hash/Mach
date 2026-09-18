<?php
/**
 * Service locations: cities, and areas inside each city (?city=ID).
 * Used by the booking form, service-area sections, technician areas and local SEO.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

$cityId = (int) ($_GET['city'] ?? $_POST['parent_id'] ?? 0);
$city = $cityId ? db_one("SELECT * FROM locations WHERE id = ? AND type = 'city'", [$cityId]) : null;
if (!$city && ($_GET['action'] ?? '') !== '' && !empty($_GET['id'])) {
    // Editing an area directly: find its city so the form and "back" link stay in context
    $parent = db_value('SELECT parent_id FROM locations WHERE id = ?', [(int) $_GET['id']]);
    $city = $parent ? db_one("SELECT * FROM locations WHERE id = ?", [$parent]) : null;
}
$isAreas = (bool) $city;
$used = fn($id) => db_value('SELECT 1 FROM leads WHERE location_id = ? UNION SELECT 1 FROM bookings WHERE location_id = ? UNION SELECT 1 FROM customers WHERE location_id = ? LIMIT 1', [$id, $id, $id]);

$fields = [
    'name' => ['label' => $isAreas ? 'Area name' : 'City name', 'required' => true, 'max' => 100, 'col' => 6],
    'slug' => ['label' => 'URL slug', 'type' => 'slug', 'from' => 'name', 'unique' => true, 'max' => 120, 'col' => 6, 'help' => 'Leave empty to create from the name.'],
];
if ($isAreas) {
    $fields['pincode'] = ['label' => 'Pincode', 'max' => 10, 'col' => 6];
}
$fields['is_active'] = ['label' => 'Active (shown on website and forms)', 'type' => 'checkbox', 'default' => 1, 'col' => 6];

crud_run([
    'path' => 'admin/locations', 'table' => 'locations', 'module' => 'locations', 'nav' => 'locations', 'icon' => 'bi-geo-alt',
    'title' => $isAreas ? 'Areas in ' . $city['name'] : 'Cities', 'singular' => $isAreas ? 'Area' : 'City',
    'subtitle' => $isAreas ? 'Neighbourhoods customers can pick when booking' : 'Cities you serve — open a city to manage its areas',
    'perm' => ['view' => 'content.manage', 'create' => 'content.manage', 'edit' => 'content.manage', 'delete' => 'content.manage'],
    'fields' => $fields,
    'validate' => function ($d, $errors) use ($isAreas, $city) {
        $d['type'] = $isAreas ? 'area' : 'city';
        $d['parent_id'] = $isAreas ? (int) $city['id'] : null;
        if (isset($d['pincode']) && $d['pincode'] !== null && !preg_match('/^[1-9]\d{5}$/', $d['pincode'])) $errors['pincode'] = 'Enter a valid 6-digit pincode.';
        return [$d, $errors];
    },
    'list_where' => fn() => $isAreas ? ["type = 'area' AND parent_id = ?", [$city['id']]] : ["type = 'city'", []],
    'new_query' => fn() => $isAreas ? '&city=' . (int) $city['id'] : '',
    'keep_query' => fn($r) => $r && $r['type'] === 'area' ? '?city=' . (int) $r['parent_id'] : '',
    'hidden_query' => fn() => $isAreas ? '<input type="hidden" name="city" value="' . (int) $city['id'] . '">' : '',
    'before_list' => function () use ($isAreas) {
        if ($isAreas) echo '<a class="btn btn-ghost btn-sm mb-3" href="' . e(url('admin/locations')) . '"><i class="bi bi-arrow-left"></i> All cities</a>';
    },
    'columns' => $isAreas ? ['name' => 'Area', 'pincode' => 'Pincode', 'slug' => 'Slug'] : ['name' => 'City', 'areas' => 'Areas', 'slug' => 'Slug'],
    'render' => [
        'name'  => fn($r) => $r['type'] === 'city'
            ? '<a class="text-reset fw-semibold" href="' . e(url('admin/locations?city=' . $r['id'])) . '">' . e($r['name']) . '</a>'
            : '<strong>' . e($r['name']) . '</strong>',
        'areas' => fn($r) => '<a href="' . e(url('admin/locations?city=' . $r['id'])) . '">' . (int) db_value('SELECT COUNT(*) FROM locations WHERE parent_id = ?', [$r['id']]) . ' areas <i class="bi bi-chevron-right"></i></a>',
        'slug'  => fn($r) => '<span class="mono small text-muted-2">' . e($r['slug']) . '</span>',
    ],
    'search' => ['name', 'pincode'], 'order' => 'sort_order, name', 'sortable' => true, 'sort_scope' => 'parent_id', 'toggle' => 'is_active',
    'describe' => fn($r) => $r['name'] ?? '',
    'can_delete' => function ($r) use ($used) {
        if ($r['type'] === 'city' && db_value('SELECT 1 FROM locations WHERE parent_id = ? LIMIT 1', [$r['id']])) return 'Delete or move this city’s areas first.';
        if ($used($r['id'])) return 'This location is used by leads, bookings or customers. Deactivate it instead.';
        return null;
    },
]);
