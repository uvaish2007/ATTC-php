<?php
/**
 * Migration: ATTS-STUDENT-BULK-CERTIFICATION
 *
 * Adds certificate_link and import_batch_id to:
 * 1. inst_student_certifications
 * 2. inst_spoken_tutorials
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';

$pdo = db();

echo "Running migration for ATTS-STUDENT-BULK-CERTIFICATION...\n";

// 1. inst_student_certifications
$cols1 = $pdo->query("DESCRIBE inst_student_certifications")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('certificate_link', $cols1, true)) {
    $pdo->exec("ALTER TABLE inst_student_certifications ADD COLUMN certificate_link TEXT NULL AFTER proof_file");
    echo "- Added certificate_link to inst_student_certifications\n";
} else {
    echo "- certificate_link already exists in inst_student_certifications\n";
}

if (!in_array('import_batch_id', $cols1, true)) {
    $pdo->exec("ALTER TABLE inst_student_certifications ADD COLUMN import_batch_id VARCHAR(64) NULL AFTER certificate_link");
    $pdo->exec("ALTER TABLE inst_student_certifications ADD KEY idx_iscert_batch (import_batch_id)");
    echo "- Added import_batch_id to inst_student_certifications\n";
} else {
    echo "- import_batch_id already exists in inst_student_certifications\n";
}

// 2. inst_spoken_tutorials
$cols2 = $pdo->query("DESCRIBE inst_spoken_tutorials")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('certificate_link', $cols2, true)) {
    $pdo->exec("ALTER TABLE inst_spoken_tutorials ADD COLUMN certificate_link TEXT NULL AFTER proof_file");
    echo "- Added certificate_link to inst_spoken_tutorials\n";
} else {
    echo "- certificate_link already exists in inst_spoken_tutorials\n";
}

if (!in_array('import_batch_id', $cols2, true)) {
    $pdo->exec("ALTER TABLE inst_spoken_tutorials ADD COLUMN import_batch_id VARCHAR(64) NULL AFTER certificate_link");
    $pdo->exec("ALTER TABLE inst_spoken_tutorials ADD KEY idx_istut_batch (import_batch_id)");
    echo "- Added import_batch_id to inst_spoken_tutorials\n";
} else {
    echo "- import_batch_id already exists in inst_spoken_tutorials\n";
}

echo "Migration completed successfully.\n";
