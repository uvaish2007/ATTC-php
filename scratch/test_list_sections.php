<?php
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
foreach (upload_approved_sections() as $k => $v) {
    echo $v['order'] . " | " . $k . " | " . $v['title'] . " | " . $v['group'] . PHP_EOL;
}
