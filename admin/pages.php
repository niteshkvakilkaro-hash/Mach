<?php
/** Website text pages (privacy policy, terms, refund policy). */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

crud_run([
    'path' => 'admin/pages', 'table' => 'pages', 'module' => 'pages', 'nav' => 'pages', 'icon' => 'bi-file-earmark-text',
    'title' => 'Pages', 'singular' => 'Page', 'subtitle' => 'Policy pages linked in the website footer',
    'perm' => ['view' => 'content.manage', 'create' => 'content.manage', 'edit' => 'content.manage', 'delete' => 'content.manage'],
    'no_create' => true, 'no_delete' => true, 'form_width' => '1000px',
    'describe' => fn($r) => $r['title'] ?? '',
    'fields' => [
        'title'           => ['label' => 'Title', 'required' => true, 'max' => 160, 'col' => 8],
        'is_active'       => ['label' => 'Published', 'type' => 'checkbox', 'default' => 1, 'col' => 4],
        'content'         => ['label' => 'Content', 'type' => 'textarea', 'required' => true, 'max' => 50000, 'rows' => 18,
                              'help' => 'Formatting: start a line with "## " for a heading and "- " for a bullet point. Leave an empty line between paragraphs.'],
        'seo_title'       => ['label' => 'SEO title', 'max' => 160, 'col' => 6],
        'seo_description' => ['label' => 'SEO description', 'max' => 320, 'col' => 6],
    ],
    'columns' => ['title' => 'Page', 'updated_at' => 'Last updated'],
    'render' => [
        'title'      => fn($r) => '<strong>' . e($r['title']) . '</strong><small class="d-block"><a href="' . e(url($r['slug'])) . '" target="_blank" rel="noopener">/' . e($r['slug']) . ' <i class="bi bi-box-arrow-up-right"></i></a></small>',
        'updated_at' => fn($r) => e(admin_datetime($r['updated_at'])),
    ],
    'order' => 'id', 'toggle' => 'is_active',
]);
