<?php
$cmd = 'php ' . escapeshellarg(__DIR__ . '/run_export_sim.php') . ' ' . escapeshellarg('record-report.php') . ' ' . escapeshellarg(base64_encode(json_encode(['type' => 'journal', 'format' => 'excel'])));
$res = shell_exec($cmd);
echo "Len: " . strlen($res) . "\n";
echo "Prefix hex: " . bin2hex(substr($res, 0, 10)) . "\n";
echo "Prefix text: " . substr($res, 0, 10) . "\n";
