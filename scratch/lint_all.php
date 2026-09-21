<?php
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../php-app'));
$errors = 0;
$total = 0;
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $total++;
        $path = $file->getRealPath();
        $output = [];
        $ret = 0;
        exec("php -l " . escapeshellarg($path), $output, $ret);
        if ($ret !== 0) {
            echo "ERROR: {$path}\n" . implode("\n", $output) . "\n";
            $errors++;
        }
    }
}
echo "Checked {$total} PHP files. Errors: {$errors}\n";
