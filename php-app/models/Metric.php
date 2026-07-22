<?php
/**
 * Metrics — the list of things the IQAC tracks (Journals, Books, FDP, …).
 *
 * The Admin manages this list on the Settings page. targets.php reads the
 * active names when someone sets a target (see metric_names() in Target.php).
 */

require_once __DIR__ . '/../inc/db.php';

/** Every metric, grouped nicely by category then name. */
function metrics_all(): array
{
    return db()->query('SELECT * FROM metrics ORDER BY category, name')->fetchAll();
}

function metric_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM metrics WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** The categories already in use, so the form can suggest them. */
function metric_categories(): array
{
    $rows = db()->query('SELECT DISTINCT category FROM metrics ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

    return $rows ?: [];
}

/** Add a metric. Returns [ok, message]. */
function metric_create(string $name, string $category, int $proofRequired): array
{
    $name     = trim($name);
    $category = trim($category);

    if ($name === '' || $category === '') {
        return [false, 'Name and category are both required.'];
    }

    $stmt = db()->prepare('SELECT id FROM metrics WHERE name = ?');
    $stmt->execute([$name]);

    if ($stmt->fetch()) {
        return [false, 'A metric with that name already exists.'];
    }

    $stmt = db()->prepare('INSERT INTO metrics (name, category, proof_required) VALUES (?, ?, ?)');
    $stmt->execute([$name, $category, $proofRequired]);

    return [true, 'Metric added.'];
}

/** Edit a metric. Returns [ok, message]. */
function metric_update(int $id, string $name, string $category, int $proofRequired, int $status): array
{
    $name     = trim($name);
    $category = trim($category);

    if ($name === '' || $category === '') {
        return [false, 'Name and category are both required.'];
    }

    if (!metric_find($id)) {
        return [false, 'Metric not found.'];
    }

    $stmt = db()->prepare('SELECT id FROM metrics WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $id]);

    if ($stmt->fetch()) {
        return [false, 'Another metric already uses that name.'];
    }

    $stmt = db()->prepare('UPDATE metrics SET name = ?, category = ?, proof_required = ?, status = ? WHERE id = ?');
    $stmt->execute([$name, $category, $proofRequired, $status, $id]);

    return [true, 'Metric updated.'];
}

/**
 * Remove a metric. Targets already created with this metric keep their own
 * copy of the name, so nothing on the Targets page disappears.
 */
function metric_delete(int $id): array
{
    if (!metric_find($id)) {
        return [false, 'Metric not found.'];
    }

    $stmt = db()->prepare('DELETE FROM metrics WHERE id = ?');
    $stmt->execute([$id]);

    return [true, 'Metric deleted.'];
}
