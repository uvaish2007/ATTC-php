<?php
/**
 * Department data access (Admin-managed). Mirrors the React departmentService
 * + Express departmentController.
 */

require_once __DIR__ . '/../inc/db.php';

function departments_all(): array
{
    return db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();
}

function department_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM departments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Create a department. Returns [ok, message].
 */
function department_create(string $name, string $code): array
{
    $name = trim($name);
    $code = trim($code);

    if ($name === '' || $code === '') {
        return [false, 'Name and code are both required.'];
    }

    // Code must be unique (matches the Express check).
    $stmt = db()->prepare('SELECT id FROM departments WHERE code = ?');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        return [false, 'A department with that code already exists.'];
    }

    $stmt = db()->prepare('SELECT id FROM departments WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        return [false, 'A department with that name already exists.'];
    }

    $stmt = db()->prepare('INSERT INTO departments (name, code) VALUES (?, ?)');
    $stmt->execute([$name, $code]);

    return [true, 'Department added.'];
}

/**
 * Update a department's name/code. Returns [ok, message].
 */
function department_update(int $id, string $name, string $code): array
{
    $name = trim($name);
    $code = trim($code);

    if ($name === '' || $code === '') {
        return [false, 'Name and code are both required.'];
    }

    if (!department_find($id)) {
        return [false, 'Department not found.'];
    }

    // Code/name unique across OTHER departments.
    $stmt = db()->prepare('SELECT id FROM departments WHERE code = ? AND id <> ?');
    $stmt->execute([$code, $id]);
    if ($stmt->fetch()) {
        return [false, 'Another department already uses that code.'];
    }

    $stmt = db()->prepare('SELECT id FROM departments WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $id]);
    if ($stmt->fetch()) {
        return [false, 'Another department already uses that name.'];
    }

    $stmt = db()->prepare('UPDATE departments SET name = ?, code = ? WHERE id = ?');
    $stmt->execute([$name, $code, $id]);

    return [true, 'Department updated.'];
}

/**
 * Delete a department. Academic records keep their free-text department, so
 * this only removes it from the managed list. Returns [ok, message].
 */
function department_delete(int $id): array
{
    if (!department_find($id)) {
        return [false, 'Department not found.'];
    }

    $stmt = db()->prepare('DELETE FROM departments WHERE id = ?');
    $stmt->execute([$id]);

    return [true, 'Department deleted.'];
}
