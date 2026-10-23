<?php
require_once __DIR__ . '/../php-app/inc/db.php';
$pdo = db();

foreach (['inst_sports_state' => 'iss', 'inst_sports_national' => 'isn'] as $tbl => $prefix) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('participation_id', $cols, true)) {
        echo "Adding participation_id to $tbl...\n";
        $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `participation_id` INT NULL DEFAULT NULL AFTER `award`");
    } else {
        echo "participation_id already exists in $tbl\n";
    }

    $indexes = $pdo->query("SHOW INDEX FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
    $idxNames = array_column($indexes, 'Key_name');
    $idxTarget = "idx_{$prefix}_part_id";
    if (!in_array($idxTarget, $idxNames, true)) {
        echo "Adding index $idxTarget to $tbl...\n";
        $pdo->exec("ALTER TABLE `$tbl` ADD INDEX `$idxTarget` (`participation_id`)");
    } else {
        echo "Index $idxTarget already exists in $tbl\n";
    }
}

$pdo->exec("UPDATE `metrics` SET `subcategories_config` = NULL, `metric_type` = 'specialized' WHERE `metric_key` = 'sports'");
echo "Updated metrics table: sports subcategories cleared, metric_type set to specialized.\n";

echo "\nVerification:\n";
foreach (['inst_sports_state', 'inst_sports_national'] as $tbl) {
    echo "Columns in $tbl:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (in_array($c['Field'], ['participation_id', 'award', 'participation_position', 'level_secured', 'proof_file'], true)) {
            echo "  - {$c['Field']} ({$c['Type']})\n";
        }
    }
}
