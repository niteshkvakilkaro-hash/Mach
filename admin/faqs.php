<?php
/** FAQs — general ones show on the home page, service FAQs on that service's page. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

$serviceOptions = fn() => array_column(get_services(), 'name', 'id');
crud_run([
    'path' => 'admin/faqs', 'table' => 'faqs', 'module' => 'faqs', 'nav' => 'faqs', 'icon' => 'bi-question-circle',
    'title' => 'FAQs', 'singular' => 'FAQ',
    'perm' => ['view' => 'content.manage', 'create' => 'content.manage', 'edit' => 'content.manage', 'delete' => 'content.manage'],
    'describe' => fn($r) => mb_strimwidth($r['question'] ?? '', 0, 60, '…'),
    'fields' => [
        'question'   => ['label' => 'Question', 'required' => true, 'max' => 255, 'unique' => true],
        'answer'     => ['label' => 'Answer', 'type' => 'textarea', 'required' => true, 'max' => 3000, 'rows' => 5],
        'service_id' => ['label' => 'Show on', 'type' => 'select', 'options' => $serviceOptions, 'col' => 6, 'help' => 'Leave empty for a general FAQ (home page + all service pages).'],
        'is_active'  => ['label' => 'Visible on website', 'type' => 'checkbox', 'default' => 1, 'col' => 6],
    ],
    'columns' => ['question' => 'Question', 'service_id' => 'Shown on'],
    'render' => [
        'question'   => fn($r) => '<strong>' . e($r['question']) . '</strong><small class="d-block text-muted-2 text-truncate" style="max-width:520px">' . e($r['answer']) . '</small>',
        'service_id' => fn($r) => $r['service_id'] ? e($serviceOptions()[$r['service_id']] ?? '—') : '<span class="tag">All pages</span>',
    ],
    'search' => ['question', 'answer'], 'order' => 'service_id IS NOT NULL, service_id, sort_order, id',
    'sortable' => true, 'sort_scope' => 'service_id', 'toggle' => 'is_active',
]);
