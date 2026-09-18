<?php
/**
 * Generate real physical exports for manual inspection and verification.
 */
echo "Generating export files...\n";

// Helper
function exportToFile(string $script, array $params, string $filename) {
    $dest = __DIR__ . '/' . $filename;
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/run_export_sim.php') . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode(json_encode($params))) . ' ' . escapeshellarg($dest);
    shell_exec($cmd);
    if (file_exists($dest)) {
        echo " [OK] Generated $filename (" . filesize($dest) . " bytes)\n";
    } else {
        echo " [ERROR] Failed to generate $filename\n";
    }
}

// 1. Record report PDF / Word / Excel
exportToFile('record-report.php', ['type' => 'journal', 'format' => 'pdf'], 'real_journal_report.pdf.html');
exportToFile('record-report.php', ['type' => 'journal', 'format' => 'word'], 'real_journal_report.doc');
exportToFile('record-report.php', ['type' => 'journal', 'format' => 'excel'], 'real_journal_report.xlsx');

// 2. Consolidated report Word / Excel
exportToFile('consolidated-report.php', ['format' => 'word'], 'real_consolidated_report.doc');
exportToFile('consolidated-report.php', ['format' => 'excel'], 'real_consolidated_report.xlsx');

// 3. Individual Faculty report Word / Excel
exportToFile('export-individual-faculty-report.php', ['id' => 5, 'format' => 'word'], 'real_faculty_report.doc');
exportToFile('export-individual-faculty-report.php', ['id' => 5, 'format' => 'excel'], 'real_faculty_report.xlsx');

// 4. Export.php (Academic records hub) Word / Excel
exportToFile('export.php', ['format' => 'word'], 'real_academic_records.doc');
exportToFile('export.php', ['format' => 'excel'], 'real_academic_records.xlsx');

echo "All physical export files generated successfully.\n";
