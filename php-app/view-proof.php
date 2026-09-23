<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/models/Record.php';

$user = require_login();

$rawFile = (string) ($_GET['file'] ?? '');
$file = basename($rawFile);
$file = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $file);

if ($file === '' || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(400);
    exit('Invalid or missing file parameter.');
}

$userRole = $user['role'] ?? 'Faculty';
$userDept = $user['department'] ?? '';
$userId   = (int) ($user['id'] ?? 0);

$isOversight = in_array($userRole, ['Admin', 'Dean', 'Director', 'Principal'], true);
$notFoundReason = null;

if (!$isOversight) {
    $type = trim((string) ($_GET['type'] ?? ''));
    $id   = (int) ($_GET['id'] ?? 0);
    $record = null;
    $types = record_types();

    if ($type !== '' && isset($types[$type]) && $id > 0) {
        try {
            $stmt = db()->prepare("SELECT * FROM `{$types[$type]['table']}` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && ($row['proof_file'] ?? '') === $file) {
                $record = $row;
            }
        } catch (\Exception $e) {}
    }

    if (!$record) {
        foreach ($types as $k => $t) {
            try {
                $stmt = db()->prepare("SELECT * FROM `{$t['table']}` WHERE proof_file = ? LIMIT 1");
                $stmt->execute([$file]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $record = $row;
                    break;
                }
            } catch (\Exception $e) {}
        }
    }

    if ($record) {
        $recordDept    = $record['department'] ?? '';
        $recordCreated = (int) ($record['created_by'] ?? 0);

        if (in_array($userRole, ['HoD', 'Coordinator'], true)) {
            if ($userDept !== '' && $recordDept !== '' && !department_names_match($userDept, $recordDept) && $recordCreated !== $userId) {
                http_response_code(403);
                exit('You do not have access to view this document.');
            }
        } elseif ($userRole === 'Faculty') {
            $isOwner = ($recordCreated === $userId);
            $isSameDeptApproved = ($userDept !== '' && department_names_match($userDept, $recordDept) && ($record['status'] ?? '') === 'Approved');
            if (!$isOwner && !$isSameDeptApproved) {
                http_response_code(403);
                exit('You do not have access to view this document.');
            }
        }
    } else {
        $notFoundReason = 'The requested proof record could not be found.';
    }
}

$targetPath = proof_file_path($file);
if ($notFoundReason || !$targetPath || !is_file($targetPath)) {
    http_response_code(404);
    $msg = $notFoundReason ?: 'The requested proof attachment could not be found on the server. Please contact your coordinator or administrator.';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Proof Unavailable - ATTS IQAC</title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; max-width: 440px; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        h1 { font-size: 18px; margin: 0 0 8px; color: #0f172a; }
        p { font-size: 14px; color: #64748b; margin: 0 0 20px; line-height: 1.5; }
        a { display: inline-block; padding: 8px 16px; background: #ff4f01; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500; }
      </style>
    </head>
    <body>
      <div class="card">
        <h1>Proof Unavailable</h1>
        <p><?= htmlspecialchars($msg) ?></p>
        <a href="javascript:window.close()">Close Window</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$isDownload = !empty($_GET['download']) || (isset($_GET['action']) && $_GET['action'] === 'download');
$disposition = $isDownload ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($targetPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($targetPath);
exit;
