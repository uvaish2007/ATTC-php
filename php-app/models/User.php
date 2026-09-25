<?php
require_once __DIR__ . '/../inc/db.php';

function users_all(?string $roleFilter = null, ?string $deptFilter = null, ?string $search = null): array
{
    $sql = 'SELECT id, name, email, role, department, phone, status, created_at FROM users WHERE 1=1';
    $params = [];

    if ($roleFilter) {
        if (in_array($roleFilter, ['Principal', 'Director'], true)) {
            $sql .= " AND role IN ('Principal', 'Director')";
        } else {
            $sql .= ' AND role = ?';
            $params[] = $roleFilter;
        }
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

    $validRoles = ['Admin', 'Principal', 'Director', 'Dean', 'HoD', 'Coordinator', 'Faculty'];
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

    $sql .= " ORDER BY FIELD(role, 'HoD', 'Coordinator', 'Faculty'), name";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

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

const USER_PHOTO_MAX_BYTES = 2097152; 

function user_photo_supported(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'photo'");
            $has = (bool) $stmt->fetch();
        } catch (\PDOException $e) {
            $has = false;
        }
    }
    return $has;
}

function user_photo_dir(): string
{
    $dir = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/\\') . '/photos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function user_photo_filename(int $id): ?string
{
    if (!user_photo_supported()) {
        return null;
    }

    $cache = &user_photo_cache();
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    $stmt = db()->prepare('SELECT photo FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $name = basename(trim((string) $stmt->fetchColumn()));

    return $cache[$id] = ($name !== '' ? $name : null);
}

function &user_photo_cache(): array
{
    static $cache = [];
    return $cache;
}

// One query for a whole page of people instead of one per row: a staff list of
// sixty was firing sixty SELECTs just to decide whether to draw an avatar.
function user_photos_preload(array $ids): void
{
    if (!user_photo_supported()) {
        return;
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return;
    }

    $cache = &user_photo_cache();
    $need  = array_values(array_filter($ids, static fn($id) => !array_key_exists($id, $cache)));
    if (!$need) {
        return;
    }

    $in   = implode(',', array_fill(0, count($need), '?'));
    $stmt = db()->prepare("SELECT id, photo FROM users WHERE id IN ($in)");
    $stmt->execute($need);

    foreach ($need as $id) {
        $cache[$id] = null;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = basename(trim((string) $row['photo']));
        $cache[(int) $row['id']] = $name !== '' ? $name : null;
    }
}

function user_photo_path(?string $filename): ?string
{
    $filename = basename(trim((string) $filename));
    if ($filename === '') {
        return null;
    }
    $path = user_photo_dir() . DIRECTORY_SEPARATOR . $filename;
    return (is_file($path) && is_readable($path)) ? $path : null;
}

function user_photo_url(int $id): ?string
{
    return user_photo_filename($id) !== null ? url('photo.php?user=' . $id) : null;
}

function user_photo_data_uri(?string $filename): ?string
{
    $path = user_photo_path($filename);
    if ($path === null || filesize($path) > USER_PHOTO_MAX_BYTES) {
        return null;
    }
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
    $raw  = @file_get_contents($path);
    return $raw === false ? null : 'data:' . $mime . ';base64,' . base64_encode($raw);
}

function user_save_photo(int $id, ?array $file): array
{
    if (!user_photo_supported()) {
        return [false, 'Photo storage is not set up yet. Ask the Admin to run sql/user_photo.sql.'];
    }
    if (!user_find($id)) {
        return [false, 'User not found.'];
    }
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [false, 'Choose a photo to upload.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? 'That photo is too large to upload.'
            : 'The photo could not be uploaded. Please try again.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, 'The photo could not be uploaded (invalid temporary file).'];
    }
    if ((int) $file['size'] > USER_PHOTO_MAX_BYTES) {
        return [false, 'The photo is larger than 2 MB. Please upload a smaller one.'];
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return [false, 'The photo must be a JPG, PNG or WEBP image.'];
    }

    $info    = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        return [false, 'That file is not a readable JPG, PNG or WEBP image.'];
    }
    [$width, $height] = $info;
    if ($width < 150 || $height < 150) {
        return [false, 'The photo is too small. Use at least 150 x 150 pixels (passport size is 35 x 45 mm).'];
    }

    $stored = 'photo_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$info[2]];
    $dest   = user_photo_dir() . DIRECTORY_SEPARATOR . $stored;

    if (!move_uploaded_file($file['tmp_name'], $dest) || !is_file($dest)) {
        return [false, 'The photo could not be saved to the upload directory.'];
    }

    $previous = user_photo_filename($id);

    $stmt = db()->prepare('UPDATE users SET photo = ? WHERE id = ?');
    if (!$stmt->execute([$stored, $id])) {
        @unlink($dest);
        return [false, 'The photo could not be saved. Please try again.'];
    }

    if ($previous !== null && $previous !== $stored) {
        $old = user_photo_path($previous);
        if ($old !== null) {
            @unlink($old);
        }
    }

    return [true, 'Profile photo updated.'];
}

function user_delete_photo(int $id): array
{
    if (!user_photo_supported()) {
        return [false, 'Photo storage is not set up yet.'];
    }
    $existing = user_photo_filename($id);
    if ($existing === null) {
        return [false, 'There is no photo to remove.'];
    }

    $stmt = db()->prepare('UPDATE users SET photo = NULL WHERE id = ?');
    $stmt->execute([$id]);

    $path = user_photo_path($existing);
    if ($path !== null) {
        @unlink($path);
    }

    return [true, 'Profile photo removed.'];
}
