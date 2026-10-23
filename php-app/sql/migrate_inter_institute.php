<?php
/**
 * Migration Script: Unified Inter-Institute Events
 * - Adds event_level, achievement_type, state_name, district_name, award_medal, certificate_link, team fields
 * - Migrates any legacy records from inst_inter_inst_outside and inst_inter_inst_awards into inst_inter_inst_within
 * - Idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../inc/db.php';

function run_inter_institute_migration(): array
{
    $pdo = db();
    $results = ['columns_added' => [], 'migrated_outside' => 0, 'migrated_awards' => 0, 'metrics_updated' => false];

    // 1. Add missing columns to inst_inter_inst_within
    $existingCols = $pdo->query("SHOW COLUMNS FROM `inst_inter_inst_within`")->fetchAll(PDO::FETCH_COLUMN);

    $columnsToAdd = [
        'event_level'        => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `event_level` VARCHAR(50) NOT NULL DEFAULT 'State — Within State' AFTER `event_date`",
        'achievement_type'   => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `achievement_type` VARCHAR(100) NOT NULL DEFAULT 'Participation' AFTER `participation_type`",
        'state_name'         => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `state_name` VARCHAR(100) NULL AFTER `location`",
        'district_name'      => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `district_name` VARCHAR(100) NULL AFTER `state_name`",
        'city'               => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `city` VARCHAR(100) NULL AFTER `district_name`",
        'award_medal'        => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `award_medal` VARCHAR(190) NULL AFTER `position_award`",
        'certificate_link'   => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `certificate_link` VARCHAR(500) NULL AFTER `proof_file`",
        'team_individual'    => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `team_individual` VARCHAR(20) NOT NULL DEFAULT 'Individual' AFTER `student_name`",
        'team_members'       => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `team_members` TEXT NULL AFTER `team_individual`",
        'other_details'      => "ALTER TABLE `inst_inter_inst_within` ADD COLUMN `other_details` TEXT NULL AFTER `review_remark`",
    ];

    foreach ($columnsToAdd as $col => $sql) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec($sql);
            $results['columns_added'][] = $col;
        }
    }

    // Ensure status column accommodates modern workflow statuses (VARCHAR(50))
    try {
        $pdo->exec("ALTER TABLE `inst_inter_inst_within` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Draft'");
    } catch (\Throwable $e) {}

    // 2. Migrate records from inst_inter_inst_outside if any exist
    try {
        $chkOutside = $pdo->query("SELECT * FROM `inst_inter_inst_outside`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chkOutside as $out) {
            // Check if already in within table
            $dupStmt = $pdo->prepare("SELECT id FROM `inst_inter_inst_within` WHERE academic_year = ? AND department = ? AND reg_no = ? AND event_name = ? AND event_date = ? LIMIT 1");
            $dupStmt->execute([$out['academic_year'], $out['department'], $out['reg_no'], $out['event_name'], $out['event_date']]);
            if (!$dupStmt->fetch()) {
                $ins = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
                    (academic_year, department, exam_session, student_name, reg_no, event_name, host_institution, location, state_name, city, event_date, event_level, participation_type, position_award, achievement_type, proof_file, status, review_remark, created_by, approved_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'State — Outside State', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $pType = $out['participation_type'] ?? 'Participant';
                $achType = in_array($pType, ['Winner', 'Runner-up', 'Winner / First Place', 'Second Runner', 'Third Runner'], true) ? 'Medal' : 'Participation';

                $ins->execute([
                    $out['academic_year'],
                    $out['department'],
                    $out['exam_session'] ?? null,
                    $out['student_name'],
                    $out['reg_no'],
                    $out['event_name'],
                    $out['host_institution'],
                    $out['location'] ?? ($out['city'] ?? ''),
                    $out['state_name'] ?? ($out['state'] ?? ''),
                    $out['city'] ?? null,
                    $out['event_date'],
                    $pType,
                    $out['position_award'] ?? null,
                    $achType,
                    $out['proof_file'] ?? null,
                    $out['status'] ?? 'Draft',
                    $out['review_remark'] ?? null,
                    $out['created_by'] ?? null,
                    $out['approved_by'] ?? null,
                    $out['created_at'] ?? date('Y-m-d H:i:s'),
                    $out['updated_at'] ?? date('Y-m-d H:i:s'),
                ]);
                $results['migrated_outside']++;
            }
        }
    } catch (\Throwable $e) {
        $results['outside_migration_err'] = $e->getMessage();
    }

    // 3. Migrate records from inst_inter_inst_awards if any exist
    try {
        $chkAwards = $pdo->query("SELECT * FROM `inst_inter_inst_awards`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chkAwards as $aw) {
            $dupStmt = $pdo->prepare("SELECT id FROM `inst_inter_inst_within` WHERE academic_year = ? AND department = ? AND reg_no = ? AND event_name = ? AND event_date = ? LIMIT 1");
            $dupStmt->execute([$aw['academic_year'], $aw['department'], $aw['reg_no'], $aw['event_name'], $aw['event_date']]);
            if (!$dupStmt->fetch()) {
                $ins = $pdo->prepare("INSERT INTO `inst_inter_inst_within`
                    (academic_year, department, exam_session, student_name, reg_no, event_name, host_institution, location, state_name, event_date, event_level, participation_type, position_award, award_medal, achievement_type, proof_file, status, review_remark, created_by, approved_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Medal', ?, ?, ?, ?, ?, ?, ?)");
                
                $pos = $aw['position_secured'] ?? ($aw['position'] ?? 'Winner');
                $pType = 'Winner / First Place';
                if (stripos($pos, '2nd') !== false || stripos($pos, 'second') !== false || stripos($pos, 'runner') !== false) {
                    $pType = 'Second Runner';
                } elseif (stripos($pos, '3rd') !== false || stripos($pos, 'third') !== false) {
                    $pType = 'Third Runner';
                }

                $evLevel = !empty($aw['state_name']) ? 'State — Outside State' : 'State — Within State';

                $ins->execute([
                    $aw['academic_year'],
                    $aw['department'],
                    $aw['exam_session'] ?? null,
                    $aw['student_name'],
                    $aw['reg_no'],
                    $aw['event_name'],
                    $aw['host_institution'] ?? '',
                    $aw['location'] ?? '',
                    $aw['state_name'] ?? null,
                    $aw['event_date'],
                    $evLevel,
                    $pType,
                    $pos,
                    $aw['award_medal'] ?? ($aw['award'] ?? null),
                    $aw['proof_file'] ?? null,
                    $aw['status'] ?? 'Draft',
                    $aw['review_remark'] ?? null,
                    $aw['created_by'] ?? null,
                    $aw['approved_by'] ?? null,
                    $aw['created_at'] ?? date('Y-m-d H:i:s'),
                    $aw['updated_at'] ?? date('Y-m-d H:i:s'),
                ]);
                $results['migrated_awards']++;
            }
        }
    } catch (\Throwable $e) {
        $results['awards_migration_err'] = $e->getMessage();
    }

    // 4. Update metrics table for inter_institute:
    // Remove subcategories_config so no pills/tabs appear, set Certificate attachment label
    try {
        $attConfig = json_encode([
            'enabled'       => true,
            'label'         => 'Certificate',
            'required'      => true,
            'allowed_types' => ['pdf'],
            'max_file_mb'   => 2,
            'help_text'     => 'PDF document strictly 2 MB or less.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $upd = $pdo->prepare("UPDATE `metrics` SET `subcategories_config` = NULL, `attachment_config` = ? WHERE `metric_key` = 'inter_institute'");
        $upd->execute([$attConfig]);
        $results['metrics_updated'] = true;
    } catch (\Throwable $e) {
        $results['metric_update_err'] = $e->getMessage();
    }

    return $results;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $res = run_inter_institute_migration();
    echo json_encode($res, JSON_PRETTY_PRINT) . PHP_EOL;
}
