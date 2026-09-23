<?php
require_once __DIR__ . '/../inc/db.php';

function template_columns(): array
{
    return db()->query('SELECT * FROM report_columns ORDER BY sort_order, id')->fetchAll();
}

function template_label_columns(): array
{
    return array_values(array_filter(template_columns(), fn($c) => ($c['source'] ?? 'label') === 'label'));
}

function target_data_fields(): array
{
    return [
        'fixed_text'     => 'Fixed (text, e.g. "10 Lakhs")',
        'target_value'   => 'Fixed (number)',
        'achieved_p1'    => 'Achieved — period 1',
        'achieved_p2'    => 'Achieved — period 2',
        'achieved_value' => 'Achieved (number)',
        'coordinator'    => 'Coordinator',
        'remarks'        => 'Progress / Remarks',
    ];
}

function template_rows(): array
{
    $rows = db()->query('SELECT * FROM report_rows ORDER BY sort_order, id')->fetchAll();
    foreach ($rows as &$r) {
        $decoded    = json_decode((string) ($r['cells'] ?? ''), true);
        $r['cells'] = is_array($decoded) ? $decoded : [];
    }
    return $rows;
}

function template_make_key(string $label): string
{
    $base = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($label)));
    $base = trim((string) $base, '_') ?: 'col';

    $existing = db()->query('SELECT col_key FROM report_columns')->fetchAll(PDO::FETCH_COLUMN);
    $key = $base;
    $n   = 2;
    while (in_array($key, $existing, true)) {
        $key = $base . '_' . $n++;
    }
    return $key;
}

function template_norm_source(string $source, ?string $field): array
{
    $source = $source === 'data' ? 'data' : 'label';
    if ($source === 'data') {
        $field = array_key_exists((string) $field, target_data_fields()) ? (string) $field : 'fixed_text';
    } else {
        $field = null;
    }
    return [$source, $field];
}

function template_column_add(string $label, int $width, string $align, string $source = 'label', ?string $field = null): array
{
    $label = trim($label);
    if ($label === '') {
        return [false, 'A column needs a name.'];
    }
    $align = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $width = max(3, min(60, $width ?: 12));
    [$source, $field] = template_norm_source($source, $field);
    $next  = (int) db()->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM report_columns')->fetchColumn();

    $stmt = db()->prepare('INSERT INTO report_columns (col_key, label, width, align, source, field, sort_order) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([template_make_key($label), $label, $width, $align, $source, $field, $next]);
    return [true, 'Column added.'];
}

function template_column_update(int $id, string $label, int $width, string $align, string $source = 'label', ?string $field = null): array
{
    $label = trim($label);
    if ($label === '') {
        return [false, 'A column needs a name.'];
    }
    $align = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $width = max(3, min(60, $width ?: 12));
    [$source, $field] = template_norm_source($source, $field);

    $stmt = db()->prepare('UPDATE report_columns SET label=?, width=?, align=?, source=?, field=? WHERE id=?');
    $stmt->execute([$label, $width, $align, $source, $field, $id]);
    return [true, 'Column updated.'];
}

function template_column_delete(int $id): array
{
    if ((int) db()->query('SELECT COUNT(*) FROM report_columns')->fetchColumn() <= 1) {
        return [false, 'The report needs at least one column.'];
    }
    db()->prepare('DELETE FROM report_columns WHERE id=?')->execute([$id]);
    return [true, 'Column removed.'];
}

function template_move(string $table, int $id, string $dir): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, sort_order FROM `$table` WHERE id=?");
    $stmt->execute([$id]);
    $cur = $stmt->fetch();
    if (!$cur) {
        return [false, 'Not found.'];
    }

    $op   = $dir === 'up' ? '<' : '>';
    $ord  = $dir === 'up' ? 'DESC' : 'ASC';
    $find = $pdo->prepare("SELECT id, sort_order FROM `$table` WHERE sort_order $op ? ORDER BY sort_order $ord, id $ord LIMIT 1");
    $find->execute([$cur['sort_order']]);
    $swap = $find->fetch();
    if (!$swap) {
        return [true, ''];   
    }

    $upd = $pdo->prepare("UPDATE `$table` SET sort_order=? WHERE id=?");
    $upd->execute([$swap['sort_order'], $cur['id']]);
    $upd->execute([$cur['sort_order'], $swap['id']]);
    return [true, ''];
}

function template_reorder(string $table, array $ids): array
{
    if (empty($ids)) {
        return [false, 'No items to reorder.'];
    }
    $table = ($table === 'report_rows') ? 'report_rows' : 'report_columns';
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE `$table` SET sort_order = ? WHERE id = ?");
    $pdo->beginTransaction();
    try {
        $order = 1;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $stmt->execute([$order++, $id]);
            }
        }
        $pdo->commit();
        return [true, 'Order updated.'];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to update order: ' . $e->getMessage()];
    }
}

function template_row_add(array $cells): array
{
    $next = (int) db()->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM report_rows')->fetchColumn();
    $stmt = db()->prepare('INSERT INTO report_rows (sort_order, cells) VALUES (?,?)');
    $stmt->execute([$next, json_encode(template_clean_cells($cells), JSON_UNESCAPED_UNICODE)]);
    return [true, 'Row added.'];
}

function template_row_update(int $id, array $cells): array
{
    $stmt = db()->prepare('UPDATE report_rows SET cells=? WHERE id=?');
    $stmt->execute([json_encode(template_clean_cells($cells), JSON_UNESCAPED_UNICODE), $id]);
    return [true, 'Row saved.'];
}

function template_row_delete(int $id): array
{
    db()->prepare('DELETE FROM report_rows WHERE id=?')->execute([$id]);
    return [true, 'Row removed.'];
}

function template_clean_cells(array $cells): array
{
    $clean = [];
    foreach (template_label_columns() as $c) {
        $k = $c['col_key'];
        if (isset($cells[$k]) && trim((string) $cells[$k]) !== '') {
            $clean[$k] = (string) $cells[$k];
        }
    }
    return $clean;
}
