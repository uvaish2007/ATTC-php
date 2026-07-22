<?php
/**
 * User data access (Admin-managed). CRUD operations for the users table.
 */

require_once __DIR__ . '/../inc/db.php';

function users_all(?string $roleFilter = null, ?string $deptFilter = null, ?string $search = null): array
{
    $sql = 'SELECT id, name, email, role, department, phone, status, created_at FROM users WHERE 1=1';
    $params = [];

    if ($roleFilter) {
        $sql .= ' AND role = ?';
        $params[] = $roleFilter;
    }
    if ($deptFilter) {
        $sql .= ' AND department = ?';
        $params[] = $deptFilter;
    }
    if ($search) {
        $sql .= ' AND (name LIKE ? OR email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= ' ORDER BY name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function user_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function user_create(string $name, string $email, string $password, string $role, ?string $department, ?string $phone): array
{
    $name = trim($name);
    $email = trim($email);

    if ($name === '' || $email === '' || $password === '') {
        return [false, 'Name, email and password are required.'];
    }

    $validRoles = ['Admin', 'Director', 'HoD', 'Coordinator', 'Faculty'];
    if (!in_array($role, $validRoles, true)) {
        return [false, 'Invalid role.'];
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [false, 'A user with that email already exists.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare('INSERT INTO users (name, email, password, role, department, phone) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $email, $hash, $role, $department ?: null, $phone ?: null]);

    return [true, 'User created.'];
}

function user_update(int $id, string $name, string $email, string $role, ?string $department, ?string $phone, int $status): array
{
    $name = trim($name);
    $email = trim($email);

    if ($name === '' || $email === '') {
        return [false, 'Name and email are required.'];
    }

    if (!user_find($id)) {
        return [false, 'User not found.'];
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) {
        return [false, 'Another user already uses that email.'];
    }

    $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, role = ?, department = ?, phone = ?, status = ? WHERE id = ?');
    $stmt->execute([$name, $email, $role, $department ?: null, $phone ?: null, $status, $id]);

    return [true, 'User updated.'];
}

function user_delete(int $id): array
{
    if (!user_find($id)) {
        return [false, 'User not found.'];
    }

    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);

    return [true, 'User deleted.'];
}

/**
 * Everyone who belongs to one department, newest role first.
 * Used by faculty.php, where an HoD looks at their own department's staff.
 */
function users_in_department(string $department, ?string $search = null): array
{
    $sql = 'SELECT id, name, email, role, department, phone, status, created_at
            FROM users WHERE department = ?';
    $params = [$department];

    if ($search) {
        $sql .= ' AND (name LIKE ? OR email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // HoD first, then Coordinators, then Faculty; alphabetical inside each.
    $sql .= " ORDER BY FIELD(role, 'HoD', 'Coordinator', 'Faculty'), name";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * A user editing their OWN details (profile.php).
 * Role, department and status are deliberately left out — only an Admin
 * changes those, on the Users page.
 */
function user_update_profile(int $id, string $name, ?string $phone): array
{
    $name = trim($name);

    if ($name === '') {
        return [false, 'Name is required.'];
    }

    if (!user_find($id)) {
        return [false, 'User not found.'];
    }

    $stmt = db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?');
    $stmt->execute([$name, $phone ?: null, $id]);

    return [true, 'Profile updated.'];
}

/**
 * A user changing their own password. Unlike the Admin reset below, this
 * asks for the current password first. Returns [ok, message].
 */
function user_change_password(int $id, string $current, string $new, string $confirm): array
{
    $user = user_find($id);

    if (!$user) {
        return [false, 'User not found.'];
    }

    if (!password_verify($current, $user['password'])) {
        return [false, 'Your current password is not correct.'];
    }

    if (strlen($new) < 6) {
        return [false, 'The new password must be at least 6 characters.'];
    }

    if ($new !== $confirm) {
        return [false, 'The two new passwords do not match.'];
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $stmt = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hash, $id]);

    return [true, 'Password changed.'];
}

function user_reset_password(int $id, string $newPassword): array
{
    if ($newPassword === '') {
        return [false, 'Password cannot be empty.'];
    }
    if (!user_find($id)) {
        return [false, 'User not found.'];
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hash, $id]);

    return [true, 'Password reset.'];
}
