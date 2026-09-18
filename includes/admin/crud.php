<?php
/**
 * Small, schema-driven CRUD for simple admin content (FAQs, testimonials, locations, coupons, …).
 * A page defines a config array and calls crud_run($config). Everything goes through prepared
 * statements, CSRF-checked POSTs, permission checks and the audit log.
 *
 * Field types: text, email, url, int, decimal, textarea, select, checkbox, date, image, slug.
 * Field options: label, type, required, max, min, options (array|callable), help, col (Bootstrap cols),
 *                default, unique, from (slug source), folder (image), rows, placeholder, nullable.
 */

function crud_options(array $f): array
{
    $opts = $f['options'] ?? [];
    return is_callable($opts) ? $opts() : $opts;
}

/** Validate POST input against the field schema. */
function crud_validate(array $cfg, array $in, ?array $row): array
{
    $data = [];
    $errors = [];
    foreach ($cfg['fields'] as $name => $f) {
        $type = $f['type'] ?? 'text';
        if ($type === 'image') continue;                          // handled separately
        $label = $f['label'];
        $raw = $in[$name] ?? null;

        if ($type === 'checkbox') {
            $data[$name] = !empty($raw) ? 1 : 0;
            continue;
        }
        $v = is_string($raw) ? trim($raw) : '';
        if ($type === 'slug' && $v === '') {
            $v = slugify((string) ($in[$f['from'] ?? 'name'] ?? ''));
        }
        if ($v === '') {
            if (!empty($f['required'])) $errors[$name] = "$label is required.";
            $data[$name] = array_key_exists('default', $f) && !empty($f['required']) ? $f['default'] : ($f['nullable'] ?? true ? null : '');
            continue;
        }
        switch ($type) {
            case 'int':
            case 'decimal':
                if (!is_numeric($v)) { $errors[$name] = "$label must be a number."; break; }
                $num = $type === 'int' ? (int) $v : round((float) $v, 2);
                if (isset($f['min']) && $num < $f['min']) $errors[$name] = "$label must be at least {$f['min']}.";
                if (isset($f['max']) && $num > $f['max']) $errors[$name] = "$label must be at most {$f['max']}.";
                $data[$name] = $num;
                break;
            case 'email':
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$name] = "Enter a valid email.";
                $data[$name] = mb_substr($v, 0, $f['max'] ?? 150);
                break;
            case 'url':
                if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v)) $errors[$name] = 'Enter a full link starting with https://';
                $data[$name] = mb_substr($v, 0, $f['max'] ?? 255);
                break;
            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) $errors[$name] = 'Invalid date.';
                $data[$name] = $v;
                break;
            case 'select':
                $opts = crud_options($f);
                if (!array_key_exists($v, $opts)) $errors[$name] = "Choose a valid $label.";
                $data[$name] = $v;
                break;
            case 'slug':
                $v = slugify($v);
                $data[$name] = mb_substr($v, 0, $f['max'] ?? 140);
                break;
            default:
                $data[$name] = clean_str($v, $f['max'] ?? ($type === 'textarea' ? 5000 : 255));
        }
        if (!isset($errors[$name]) && !empty($f['unique'])) {
            $dup = db_value("SELECT 1 FROM `{$cfg['table']}` WHERE `$name` = ? AND id <> ?", [$data[$name], $row['id'] ?? 0]);
            if ($dup) $errors[$name] = "This $label is already used.";
        }
    }
    if (!empty($cfg['validate'])) {
        [$data, $errors] = $cfg['validate']($data, $errors, $row);
    }
    return [$data, $errors];
}

function crud_field_html(string $name, array $f, $value, ?string $error): string
{
    $type = $f['type'] ?? 'text';
    $id = 'f_' . $name;
    $cls = $error ? ' is-invalid' : '';
    $req = !empty($f['required']) ? ' required' : '';
    $label = '<label class="form-label" for="' . $id . '">' . e($f['label']) . (!empty($f['required']) ? ' *' : '') . '</label>';
    $help = !empty($f['help']) ? '<div class="form-text">' . e($f['help']) . '</div>' : '';
    $err = $error ? '<div class="invalid-feedback d-block">' . e($error) . '</div>' : '';
    $ph = !empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '';
    $max = !empty($f['max']) && in_array($type, ['text', 'email', 'url', 'textarea', 'slug'], true) ? ' maxlength="' . (int) $f['max'] . '"' : '';

    switch ($type) {
        case 'checkbox':
            return '<div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $name . '" value="1"' . ((int) $value ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $id . '">' . e($f['label']) . '</label></div>' . $help . $err;
        case 'password':
            // Never echo a stored secret back into the page
            return $label . '<input class="form-control' . $cls . '" type="password" id="' . $id . '" name="' . $name . '" value="" autocomplete="new-password"' . $max
                . ' placeholder="' . ($value !== '' && $value !== null ? '•••••••• saved — leave blank to keep' : 'Enter password') . '">' . $help . $err;
        case 'textarea':
            return $label . '<textarea class="form-control' . $cls . '" id="' . $id . '" name="' . $name . '" rows="' . (int) ($f['rows'] ?? 4) . '"' . $req . $max . $ph . '>' . e($value) . '</textarea>' . $help . $err;
        case 'select':
            return $label . '<select class="form-select' . $cls . '" id="' . $id . '" name="' . $name . '"' . $req . '>' . select_options(crud_options($f), $value, empty($f['required']) ? '—' : null) . '</select>' . $help . $err;
        case 'image':
            $preview = $value ? '<img class="crud-thumb" src="' . e(url($value)) . '" alt="">' : '';
            return $label . '<div class="d-flex gap-3 align-items-center">' . $preview . '<div class="flex-grow-1"><input class="form-control' . $cls . '" type="file" id="' . $id . '" name="' . $name . '" accept="image/jpeg,image/png,image/webp">'
                . ($value ? '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="' . $id . '_rm" name="' . $name . '_remove" value="1"><label class="form-check-label small" for="' . $id . '_rm">Remove image</label></div>' : '')
                . '</div></div>' . $help . $err;
        default:
            $html = ['int' => 'number', 'decimal' => 'text', 'email' => 'email', 'url' => 'url', 'date' => 'date'][$type] ?? 'text';
            $mode = in_array($type, ['int', 'decimal'], true) ? ' inputmode="decimal"' : '';
            $val = $type === 'decimal' && $value !== null && $value !== '' ? rtrim(rtrim((string) $value, '0'), '.') : $value;
            return $label . '<input class="form-control' . $cls . '" type="' . $html . '" id="' . $id . '" name="' . $name . '" value="' . e($val) . '"' . $req . $max . $ph . $mode . '>' . $help . $err;
    }
}

/** Reorder: move a row up/down within its scope and renumber sort_order 1..n. */
function crud_move(array $cfg, array $row, string $dir): void
{
    $scope = $cfg['sort_scope'] ?? null;
    $where = $scope ? "`$scope` <=> ?" : '1=1';
    $params = $scope ? [$row[$scope]] : [];
    $ids = array_map('intval', array_column(db_all("SELECT id FROM `{$cfg['table']}` WHERE $where ORDER BY sort_order, id", $params), 'id'));
    $i = array_search((int) $row['id'], $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i === false || $j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    foreach ($ids as $pos => $id) {
        db_query("UPDATE `{$cfg['table']}` SET sort_order = ? WHERE id = ?", [$pos + 1, $id]);
    }
}

function crud_run(array $cfg): void
{
    $perm = $cfg['perm'];                        // ['view' => …, 'create' => …, 'edit' => …, 'delete' => …]
    $me = require_permission($perm['view']);
    $path = $cfg['path'];
    $table = $cfg['table'];
    $action = $_GET['action'] ?? 'list';
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $row = $id ? db_one("SELECT * FROM `$table` WHERE id = ?", [$id]) : null;
    $singular = $cfg['singular'];
    $describe = $cfg['describe'] ?? fn($r) => $r['name'] ?? ('#' . $r['id']);
    $errors = [];
    $values = $row ?: array_map(fn($f) => $f['default'] ?? '', $cfg['fields']);

    // ------------------------------------------------------------ POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = $_POST['op'] ?? '';
        if (!csrf_verify()) {
            flash_set('error', 'Your session expired. Please try again.');
            redirect(url($path));
        }
        if (in_array($op, ['delete', 'toggle', 'move'], true) && !$row) {
            flash_set('error', "$singular not found.");
            redirect(url($path));
        }

        if ($op === 'delete') {
            require_permission($perm['delete'] ?? $perm['edit']);
            $reason = !empty($cfg['can_delete']) ? $cfg['can_delete']($row) : null;
            if ($reason) {
                flash_set('error', $reason);
            } else {
                db_query("DELETE FROM `$table` WHERE id = ?", [$row['id']]);
                foreach ($cfg['fields'] as $name => $f) {
                    if (($f['type'] ?? '') === 'image') upload_delete($row[$name] ?? null);
                }
                audit_log((int) $me['id'], 'deleted', $cfg['module'], (int) $row['id'], "Deleted $singular: " . $describe($row), $row);
                flash_set('success', "$singular deleted.");
            }
            redirect(url($path) . (!empty($cfg['keep_query']) ? $cfg['keep_query']($row) : ''));
        }
        if ($op === 'toggle' && !empty($cfg['toggle'])) {
            require_permission($perm['edit']);
            $col = $cfg['toggle'];
            db_query("UPDATE `$table` SET `$col` = 1 - `$col` WHERE id = ?", [$row['id']]);
            audit_log((int) $me['id'], 'updated', $cfg['module'], (int) $row['id'], ((int) $row[$col] ? 'Hid ' : 'Showed ') . "$singular: " . $describe($row));
            flash_set('success', (int) $row[$col] ? "$singular hidden." : "$singular is now live.");
            redirect(url($path) . (!empty($cfg['keep_query']) ? $cfg['keep_query']($row) : ''));
        }
        if ($op === 'move' && !empty($cfg['sortable'])) {
            require_permission($perm['edit']);
            crud_move($cfg, $row, $_POST['dir'] === 'up' ? 'up' : 'down');
            redirect(url($path) . (!empty($cfg['keep_query']) ? $cfg['keep_query']($row) : ''));
        }
        if ($op === 'save') {
            require_permission($row ? $perm['edit'] : $perm['create']);
            [$data, $errors] = crud_validate($cfg, $_POST, $row);
            $uploads = [];
            if (!$errors) {
                foreach ($cfg['fields'] as $name => $f) {
                    if (($f['type'] ?? '') !== 'image') continue;
                    [$file, $err] = upload_image($_FILES[$name] ?? null, $f['folder'], $f['max_side'] ?? 1000);
                    if ($err) { $errors[$name] = $err; continue; }
                    if ($file) { $data[$name] = $file; $uploads[] = $file; }
                    elseif (!empty($_POST[$name . '_remove'])) { $data[$name] = null; }
                }
            }
            if ($errors) {
                foreach ($uploads as $u) upload_delete($u);
                $values = array_merge($values, $data);
                $action = 'edit';
            } else {
                if ($row) {
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                    db_query("UPDATE `$table` SET $sets WHERE id = ?", array_merge(array_values($data), [$row['id']]));
                    foreach ($cfg['fields'] as $name => $f) {
                        if (($f['type'] ?? '') === 'image' && array_key_exists($name, $data) && $row[$name] && $row[$name] !== $data[$name]) upload_delete($row[$name]);
                    }
                    audit_log((int) $me['id'], 'updated', $cfg['module'], (int) $row['id'], "Edited $singular: " . $describe($data + $row), array_intersect_key($row, $data), $data);
                    $savedId = (int) $row['id'];
                } else {
                    if (!empty($cfg['sortable']) && !isset($data['sort_order'])) {
                        $scope = $cfg['sort_scope'] ?? null;
                        $data['sort_order'] = (int) db_value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `$table`" . ($scope ? " WHERE `$scope` <=> ?" : ''), $scope ? [$data[$scope] ?? null] : []);
                    }
                    $savedId = db_insert($table, $data);
                    audit_log((int) $me['id'], 'created', $cfg['module'], $savedId, "Created $singular: " . $describe($data));
                }
                if (!empty($cfg['after_save'])) $cfg['after_save']($savedId, $data);
                flash_set('success', "$singular saved.");
                $saved = db_one("SELECT * FROM `$table` WHERE id = ?", [$savedId]);
                redirect(url($path) . (!empty($cfg['keep_query']) ? $cfg['keep_query']($saved) : ''));
            }
        }
    }

    // ------------------------------------------------------------ form view
    if ($action === 'edit' || $action === 'new') {
        require_permission($row ? $perm['edit'] : $perm['create']);
        if ($action === 'new' && !$_POST) {
            foreach ($cfg['fields'] as $name => $f) {
                if (isset($_GET[$name]) && !isset($f['type'])) $values[$name] = $_GET[$name];
                if (isset($_GET[$name]) && ($f['type'] ?? '') === 'select') $values[$name] = $_GET[$name];
            }
        }
        $admin = ['title' => ($row ? 'Edit ' : 'New ') . strtolower($singular), 'subtitle' => $row ? $describe($row) : ($cfg['new_hint'] ?? ''), 'active' => $cfg['nav']];
        require ROOT_PATH . '/includes/admin/header.php';
        if ($errors) echo '<div class="alert alert-danger">Please fix the highlighted fields.</div>';
        echo '<form method="post" enctype="multipart/form-data" class="panel" style="max-width:' . ($cfg['form_width'] ?? '900px') . '" novalidate>';
        echo csrf_field() . '<input type="hidden" name="op" value="save">' . ($row ? '<input type="hidden" name="id" value="' . (int) $row['id'] . '">' : '');
        echo '<div class="panel-body row g-3">';
        foreach ($cfg['fields'] as $name => $f) {
            echo '<div class="col-md-' . (int) ($f['col'] ?? 12) . '">' . crud_field_html($name, $f, $values[$name] ?? '', $errors[$name] ?? null) . '</div>';
        }
        echo '</div><div class="panel-foot"><a class="btn btn-ghost" href="' . e(url($path) . (!empty($cfg['keep_query']) && $row ? $cfg['keep_query']($row) : '')) . '">Cancel</a>'
            . '<button class="btn btn-grad" type="submit">Save ' . e(strtolower($singular)) . '</button></div></form>';
        require ROOT_PATH . '/includes/admin/footer.php';
        return;
    }

    // ------------------------------------------------------------ list view
    [$where, $params] = !empty($cfg['list_where']) ? $cfg['list_where']() : ['1=1', []];
    $q = clean_str($_GET['q'] ?? '', 100);
    if ($q !== '' && !empty($cfg['search'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where .= ' AND (' . implode(' OR ', array_map(fn($c) => "`$c` LIKE ?", $cfg['search'])) . ')';
        $params = array_merge($params, array_fill(0, count($cfg['search']), $like));
    }
    $rows = db_all("SELECT * FROM `$table` WHERE $where ORDER BY " . ($cfg['order'] ?? 'id DESC') . ' LIMIT 500', $params);
    $newLink = url($path) . '?action=new' . (!empty($cfg['new_query']) ? $cfg['new_query']() : '');
    $admin = [
        'title'    => $cfg['title'],
        'subtitle' => $cfg['subtitle'] ?? (count($rows) . ' ' . strtolower(count($rows) === 1 ? $singular : $cfg['title'])),
        'active'   => $cfg['nav'],
        'actions'  => can($perm['create']) && empty($cfg['no_create']) ? '<a class="btn btn-grad btn-sm" href="' . e($newLink) . '"><i class="bi bi-plus-lg"></i> Add ' . e(strtolower($singular)) . '</a>' : '',
    ];
    require ROOT_PATH . '/includes/admin/header.php';
    if (!empty($cfg['before_list'])) $cfg['before_list']();
    if (!empty($cfg['search'])) {
        echo '<form class="filter-search mb-3" style="max-width:420px" method="get" action="' . e(url($path)) . '">' . (!empty($cfg['hidden_query']) ? $cfg['hidden_query']() : '')
            . '<i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="' . e($q) . '" placeholder="Search" aria-label="Search"></form>';
    }
    echo '<section class="panel">';
    if (!$rows) {
        echo '<div class="empty-state"><i class="bi ' . e($cfg['icon'] ?? 'bi-inbox') . '"></i><h2 class="h5">Nothing here yet</h2>'
            . (can($perm['create']) && empty($cfg['no_create']) ? '<a class="btn btn-grad" href="' . e($newLink) . '">Add ' . e(strtolower($singular)) . '</a>' : '') . '</div></section>';
        require ROOT_PATH . '/includes/admin/footer.php';
        return;
    }
    $canEdit = can($perm['edit']);
    $canDelete = can($perm['delete'] ?? $perm['edit']) && empty($cfg['no_delete']);
    echo '<div class="table-responsive"><table class="table admin-table mb-0"><thead><tr>';
    if (!empty($cfg['sortable']) && $canEdit && $q === '') echo '<th style="width:70px">Order</th>';
    foreach ($cfg['columns'] as $label) echo '<th>' . e($label) . '</th>';
    if (!empty($cfg['toggle'])) echo '<th>Visible</th>';
    echo '<th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $csrf = csrf_field();
    $mini = fn(array $r, string $op, string $inner, string $extra = '', string $cls = 'icon-btn icon-btn-sm', string $confirm = '')
        => '<form method="post" class="d-inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $csrf . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">' . $extra
            . '<button class="' . $cls . '" type="submit">' . $inner . '</button></form>';
    foreach ($rows as $r) {
        echo '<tr>';
        if (!empty($cfg['sortable']) && $canEdit && $q === '') {
            echo '<td class="text-nowrap">' . $mini($r, 'move', '<i class="bi bi-arrow-up"></i><span class="visually-hidden">Move up</span>', '<input type="hidden" name="dir" value="up">')
                . $mini($r, 'move', '<i class="bi bi-arrow-down"></i><span class="visually-hidden">Move down</span>', '<input type="hidden" name="dir" value="down">') . '</td>';
        }
        foreach ($cfg['columns'] as $key => $label) {
            echo '<td>' . (isset($cfg['render'][$key]) ? $cfg['render'][$key]($r) : e($r[$key] ?? '')) . '</td>';
        }
        if (!empty($cfg['toggle'])) {
            $on = (int) $r[$cfg['toggle']];
            echo '<td>' . ($canEdit ? $mini($r, 'toggle', $on ? '<i class="bi bi-toggle-on"></i> Live' : '<i class="bi bi-toggle-off"></i> Hidden', '', 'toggle-btn' . ($on ? ' is-on' : '')) : ($on ? 'Live' : 'Hidden')) . '</td>';
        }
        echo '<td class="text-end text-nowrap">';
        if (!empty($cfg['row_actions'])) echo $cfg['row_actions']($r);
        if ($canEdit) echo '<a class="icon-btn icon-btn-sm" href="' . e(url($path) . '?action=edit&id=' . (int) $r['id']) . '" aria-label="Edit"><i class="bi bi-pencil"></i></a> ';
        if ($canDelete) echo $mini($r, 'delete', '<i class="bi bi-trash"></i><span class="visually-hidden">Delete</span>', '', 'icon-btn icon-btn-sm text-danger', 'Delete this ' . strtolower($singular) . '? This cannot be undone.');
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';
    require ROOT_PATH . '/includes/admin/footer.php';
}
