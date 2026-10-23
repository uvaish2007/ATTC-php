<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();

$cols = $pdo->query("SHOW COLUMNS FROM `metrics`")->fetchAll(PDO::FETCH_ASSOC);
echo "Columns in metrics: " . implode(', ', array_column($cols, 'Field')) . PHP_EOL;

$stmt = $pdo->query("SELECT id, metric_key, name, display_order, default_type, supported_types, subcategories_config FROM metrics ORDER BY display_order ASC");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$r['id']} | Key: {$r['metric_key']} | Order: {$r['display_order']} | Name: {$r['name']} | Def: {$r['default_type']}" . PHP_EOL;
    if (!empty($r['subcategories_config'])) echo "  Subcats: {$r['subcategories_config']}" . PHP_EOL;
    if (!empty($r['supported_types'])) echo "  Supported: {$r['supported_types']}" . PHP_EOL;
}

echo PHP_EOL . "=== TABLES RELATING TO INTER-INSTITUTE ===" . PHP_EOL;
$tables = ['inst_inter_inst_within', 'inst_inter_inst_outside', 'inst_inter_inst_awards', 'co_curricular', 'extra_curricular', 'student_achievements', 'student_participations'];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "Table: $t (count: $count)" . PHP_EOL;
        $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        echo "  Columns: " . implode(', ', array_column($cols, 'Field')) . PHP_EOL;
    } catch (Throwable $e) {
        echo "Table: $t -> ERROR: " . $e->getMessage() . PHP_EOL;
    }
}
