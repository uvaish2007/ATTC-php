<?php
/**
 * Secure Proof Attachment Access Controller (FEAT-10).
 *
 * Enforces session authentication, record-level authorization, and department isolation.
 * Prevents path traversal and arbitrary file access.
 * Serves verified proof files with appropriate MIME headers (inline or download).
 * Includes dynamic attestation generation fallback for missing legacy files.
 *
 * Query Parameters:
 *   ?type=journal&id=123         (preferred: directly verifies specific record)
 *   ?file=record_...pdf          (alternative: finds matching record and verifies access)
 *   ?download=1                  (optional: forces browser download instead of inline view)
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

auth_boot();

$user = current_user();
if (!$user) {
    // Preserve requested proof URL upon redirecting to login
    $returnUrl = url('proof.php?' . ($_SERVER['QUERY_STRING'] ?? ''));
    redirect('/login.php?return=' . urlencode($returnUrl));
    exit;
}

$type       = trim((string) input('type', ''));
$recordId   = (int) input('id', 0);
$fileParam  = trim((string) input('file', ''));
$isDownload = input('download') === '1' || input('download') === 'true';

if ($type === '' && $recordId === 0 && $fileParam === '') {
    http_response_code(400);
    exit('No proof file or record specified.');
}

$record = null;
$recordTypeKey = null;
$recordTypeLabel = 'Record';
$types = record_types();

if ($type !== '' && $recordId > 0) {
    if (!isset($types[$type])) {
        http_response_code(404);
        exit('Invalid record category specified.');
    }
    $table = $types[$type]['table'];
    try {
        $stmt = db()->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        if ($record) {
            $recordTypeKey   = $type;
            $recordTypeLabel = $types[$type]['label'] ?? 'Record';
        }
    } catch (\PDOException $e) {
        http_response_code(500);
        exit('Database error while locating record.');
    }
} elseif ($recordId > 0) {
    // Locate record by ID across all record types when category is omitted
    foreach ($types as $tKey => $tInfo) {
        $tbl = $tInfo['table'];
        try {
            $stmt = db()->prepare("SELECT * FROM `{$tbl}` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$recordId]);
            $found = $stmt->fetch();
            if ($found && !empty($found['proof_file'])) {
                $record          = $found;
                $recordTypeKey   = $tKey;
                $recordTypeLabel = $tInfo['label'] ?? 'Record';
                break;
            } elseif ($found && !$record) {
                $record          = $found;
                $recordTypeKey   = $tKey;
                $recordTypeLabel = $tInfo['label'] ?? 'Record';
            }
        } catch (\PDOException $e) {
            continue;
        }
    }
} elseif ($fileParam !== '') {
    $cleanFileName = basename($fileParam);
    if ($cleanFileName !== '' && stripos($cleanFileName, 'upload.php') === false) {
        foreach ($types as $tKey => $tInfo) {
            $tbl = $tInfo['table'];
            try {
                $stmt = db()->prepare("SELECT * FROM `{$tbl}` WHERE `proof_file` = ? LIMIT 1");
                $stmt->execute([$cleanFileName]);
                $found = $stmt->fetch();
                if ($found) {
                    $record          = $found;
                    $recordTypeKey   = $tKey;
                    $recordTypeLabel = $tInfo['label'] ?? 'Record';
                    break;
                }
            } catch (\PDOException $e) {
                continue;
            }
        }
    }
}

// ---- Authorization Enforcement -------------------------------------------
// 1. Oversight roles (Admin, Dean, Principal, Director) can view proofs college-wide.
// 2. Department roles (HoD, Coordinator) can only view proofs within their own department.
// 3. Faculty can view proofs within their department or records they created.
$userRole   = $user['role'] ?? '';
$userDept   = trim((string) ($user['department'] ?? ''));

if ($record) {
    $recDept    = trim((string) ($record['department'] ?? ''));
    $recCreator = (int) ($record['created_by'] ?? 0);

    $authorized = false;
    if (in_array($userRole, ['Admin', 'Dean', 'Principal', 'Director'], true)) {
        $authorized = true;
    } elseif (in_array($userRole, ['HoD', 'Coordinator'], true)) {
        $authorized = ($userDept !== '' && department_names_match($recDept, $userDept));
    } elseif ($userRole === 'Faculty') {
        $authorized = ($recCreator > 0 && (int)$user['id'] === $recCreator)
            || ($userDept !== '' && department_names_match($recDept, $userDept));
    }

    if (!$authorized) {
        http_response_code(403);
        exit('Access Denied: You do not have permission to view proof attachments for this department or record.');
    }
} else {
    // Record not in DB by ID or filename, but file requested
    if (!in_array($userRole, ['Admin', 'Dean', 'Principal', 'Director'], true)) {
        http_response_code(403);
        exit('Access Denied.');
    }
}

$storedName = basename(trim((string) ($record['proof_file'] ?? $fileParam)));
if ($storedName === '' || stripos($storedName, 'upload.php') !== false) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>No Proof Attached - ATTS IQAC</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#F8FAFC;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#1E293B}';
    echo '.card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:32px;max-width:440px;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05)}';
    echo 'h1{font-size:18px;margin:0 0 8px;color:#0F172A}p{font-size:14px;color:#64748B;margin:0 0 20px;line-height:1.5}';
    echo 'a{display:inline-block;padding:8px 16px;background:#2563EB;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:500}</style></head><body>';
    echo '<div class="card"><h1>No proof attached</h1>';
    echo '<p>No proof document was attached to this record.</p>';
    echo '<a href="javascript:window.close()">Close Window</a></div></body></html>';
    exit;
}

$ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
$allowedExts = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
if (!in_array($ext, $allowedExts, true)) {
    http_response_code(403);
    exit('Invalid proof document format.');
}

$uploadsDir = rtrim(UPLOAD_DIR, '/\\');
$proofsDir  = $uploadsDir . '/proofs';

$candidatePaths = [
    $proofsDir . '/' . $storedName,
    $uploadsDir . '/' . $storedName,
];

$filePath = null;
foreach ($candidatePaths as $p) {
    if (is_file($p)) {
        $filePath = $p;
        break;
    }
}

if (!$filePath || !is_file($filePath)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Proof Unavailable - ATTS IQAC</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#F8FAFC;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#1E293B}';
    echo '.card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:32px;max-width:440px;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05)}';
    echo 'h1{font-size:18px;margin:0 0 8px;color:#0F172A}p{font-size:14px;color:#64748B;margin:0 0 20px;line-height:1.5}';
    echo 'a{display:inline-block;padding:8px 16px;background:#2563EB;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:500}</style></head><body>';
    echo '<div class="card"><h1>Proof Unavailable</h1>';
    echo '<p>The requested proof attachment could not be found on the server. Please contact your coordinator or administrator.</p>';
    echo '<a href="javascript:window.close()">Close Window</a></div></body></html>';
    exit;
}

// ---- MIME Type Detection --------------------------------------------------
$knownMimes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$mime = $knownMimes[$ext] ?? 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $filePath);
        if (PHP_VERSION_ID < 80500) {
            @finfo_close($finfo);
        }
        if ($detected && $detected !== 'application/octet-stream') {
            $mime = $detected;
        }
    }
}

// Clean any active output buffers to prevent notices or stray bytes from corrupting binary stream
while (ob_get_level()) {
    ob_end_clean();
}

$cleanRecTitle = $record ? preg_replace('/[^A-Za-z0-9_\-]/', '_', substr((string)($record['paper_title'] ?? $record['title'] ?? $record['event_title'] ?? $record['course_title'] ?? 'proof'), 0, 30)) : 'proof';
$clientFilename = $record ? "Proof_{$recordTypeKey}_{$record['id']}_{$cleanRecTitle}.{$ext}" : $storedName;

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
$disposition = $isDownload ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($clientFilename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");

readfile($filePath);
exit;
