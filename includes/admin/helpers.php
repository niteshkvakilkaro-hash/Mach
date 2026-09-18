<?php
/** Small view helpers shared by admin pages. */

/** Current admin URL with some query params replaced (null removes a param). */
function admin_query_url(string $path, array $params, array $replace = []): string
{
    $q = array_filter(array_merge($params, $replace), fn($v) => $v !== null && $v !== '');
    return url($path) . ($q ? '?' . http_build_query($q) : '');
}

function status_badge(string $label, string $color): string
{
    return '<span class="status-badge sb-' . e($color) . '">' . e($label) . '</span>';
}

function priority_badge(string $priority): string
{
    if ($priority === 'normal') {
        return '';
    }
    $icon = ['urgent' => 'bi-exclamation-octagon-fill', 'high' => 'bi-lightning-fill', 'low' => 'bi-arrow-down'][$priority] ?? 'bi-dot';
    return '<span class="prio prio-' . e($priority) . '"><i class="bi ' . $icon . '"></i> ' . e(LEAD_PRIORITIES[$priority] ?? $priority) . '</span>';
}

/** <option> list from [value => label]. */
function select_options(array $options, $selected, string $placeholder = null): string
{
    $html = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function admin_datetime(?string $dt, bool $withTime = true): string
{
    if (!$dt) {
        return '—';
    }
    return date($withTime ? 'd M Y, h:i A' : 'd M Y', strtotime($dt));
}

/** Bootstrap pagination that keeps the current filters. */
function admin_pagination(string $path, array $params, int $page, int $total, int $perPage): string
{
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $link = function (int $p, string $label, bool $disabled = false, bool $active = false) use ($path, $params) {
        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
        $href = $disabled ? '#' : e(admin_query_url($path, $params, ['page' => $p > 1 ? $p : null]));
        return '<li class="' . $cls . '"><a class="page-link" href="' . $href . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
    };
    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
    $html .= $link($page - 1, '<i class="bi bi-chevron-left"></i><span class="visually-hidden">Previous</span>', $page <= 1);
    $window = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($p) => $p >= 1 && $p <= $pages));
    sort($window);
    $prev = 0;
    foreach ($window as $p) {
        if ($p - $prev > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= $link($p, (string) $p, false, $p === $page);
        $prev = $p;
    }
    $html .= $link($page + 1, '<i class="bi bi-chevron-right"></i><span class="visually-hidden">Next</span>', $page >= $pages);
    return $html . '</ul></nav>';
}

/** Link to a lead's page. */
function lead_url(string $number): string
{
    return url('admin/leads/view?number=' . rawurlencode($number));
}
