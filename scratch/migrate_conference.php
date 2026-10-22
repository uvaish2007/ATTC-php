<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

$pdo = db();

echo "Checking conference_publications columns...\n";
$cols = $pdo->query('DESCRIBE conference_publications')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('main_author_id', $cols, true)) {
    echo "Adding main_author_id column...\n";
    $pdo->exec("ALTER TABLE conference_publications ADD COLUMN main_author_id INT NULL AFTER academic_year");
} else {
    echo "main_author_id column already exists.\n";
}

if (!in_array('publication_year', $cols, true)) {
    echo "Adding publication_year column...\n";
    $pdo->exec("ALTER TABLE conference_publications ADD COLUMN publication_year VARCHAR(10) NULL AFTER conference_date");
} else {
    echo "publication_year column already exists.\n";
}

if (!in_array('doi', $cols, true)) {
    echo "Adding doi column...\n";
    $pdo->exec("ALTER TABLE conference_publications ADD COLUMN doi VARCHAR(255) NULL AFTER isbn");
} else {
    echo "doi column already exists.\n";
}

// Backfill existing publication_year
$pdo->exec("UPDATE conference_publications SET publication_year = SUBSTRING(conference_date, 1, 4) WHERE (publication_year IS NULL OR publication_year = '') AND conference_date REGEXP '^[0-9]{4}'");
$pdo->exec("UPDATE conference_publications SET publication_year = SUBSTRING_INDEX(conference_date, '/', -1) WHERE (publication_year IS NULL OR publication_year = '') AND conference_date REGEXP '[0-9]{4}$'");
$pdo->exec("UPDATE conference_publications SET publication_year = SUBSTRING(academic_year, 1, 4) WHERE (publication_year IS NULL OR publication_year = '') AND academic_year REGEXP '^[0-9]{4}'");

// Backfill main_author_id from matching faculty_name in users
$pdo->exec("UPDATE conference_publications cp JOIN users u ON cp.faculty_name = u.name SET cp.main_author_id = u.id WHERE cp.main_author_id IS NULL");

echo "Done! Updated conference_publications columns:\n";
$updatedCols = $pdo->query('DESCRIBE conference_publications')->fetchAll(PDO::FETCH_ASSOC);
foreach ($updatedCols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
