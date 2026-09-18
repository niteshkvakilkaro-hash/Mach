<?php
/** Customer reviews shown on the website. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

crud_run([
    'path' => 'admin/testimonials', 'table' => 'testimonials', 'module' => 'testimonials', 'nav' => 'testimonials', 'icon' => 'bi-chat-quote',
    'title' => 'Testimonials', 'singular' => 'Testimonial',
    'perm' => ['view' => 'content.manage', 'create' => 'content.manage', 'edit' => 'content.manage', 'delete' => 'content.manage'],
    'describe' => fn($r) => $r['customer_name'] ?? '',
    'fields' => [
        'customer_name' => ['label' => 'Customer name', 'required' => true, 'max' => 100, 'col' => 6],
        'location'      => ['label' => 'Area / city', 'max' => 100, 'col' => 6, 'placeholder' => 'e.g. Vaishali Nagar'],
        'rating'        => ['label' => 'Rating', 'type' => 'select', 'required' => true, 'default' => '5', 'col' => 6,
                            'options' => ['5' => '★★★★★  5', '4' => '★★★★  4', '3' => '★★★  3', '2' => '★★  2', '1' => '★  1']],
        'service_id'    => ['label' => 'Service', 'type' => 'select', 'col' => 6, 'options' => fn() => array_column(get_services(), 'name', 'id')],
        'review'        => ['label' => 'Review', 'type' => 'textarea', 'required' => true, 'max' => 1000, 'rows' => 4,
                            'help' => 'Only publish genuine reviews, with the customer’s permission.'],
        'photo'         => ['label' => 'Photo (optional)', 'type' => 'image', 'folder' => 'testimonials', 'max_side' => 300, 'col' => 8],
        'is_active'     => ['label' => 'Visible on website', 'type' => 'checkbox', 'default' => 1, 'col' => 4],
    ],
    'columns' => ['customer_name' => 'Customer', 'rating' => 'Rating', 'review' => 'Review'],
    'render' => [
        'customer_name' => fn($r) => '<strong>' . e($r['customer_name']) . '</strong><small class="d-block text-muted-2">' . e($r['location']) . '</small>',
        'rating'        => fn($r) => '<span class="text-warning">' . str_repeat('★', (int) $r['rating']) . '</span><span class="text-muted-2">' . str_repeat('★', 5 - (int) $r['rating']) . '</span>',
        'review'        => fn($r) => '<span class="d-inline-block text-truncate" style="max-width:460px">' . e($r['review']) . '</span>',
    ],
    'search' => ['customer_name', 'review', 'location'], 'order' => 'sort_order, id DESC', 'sortable' => true, 'toggle' => 'is_active',
]);
