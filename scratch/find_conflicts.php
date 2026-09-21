<?php
$dir = new RecursiveDirectoryIterator(__DIR__ . '/../php-app');
$iterator = new RecursiveIteratorIterator($dir);

$conflicts = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getRealPath();
        $lines = file($path);
        foreach ($lines as $i => $line) {
            if (strpos($line, '<<<<<<<') !== false) {
                $conflicts[$path][] = ($i + 1);
            }
        }
    }
}

// Also check index.php in root if exists
$rootIndex = realpath(__DIR__ . '/../index.php');
if ($rootIndex && file_exists($rootIndex)) {
    $lines = file($rootIndex);
    foreach ($lines as $i => $line) {
        if (strpos($line, '<<<<<<<') !== false) {
            $conflicts[$rootIndex][] = ($i + 1);
        }
    }
}

foreach ($conflicts as $path => $lineNumbers) {
    echo $path . ": lines " . implode(', ', $lineNumbers) . "\n";
}
if (empty($conflicts)) {
    echo "NO CONFLICTS FOUND!\n";
}
