<?php
/**
 * Secure Proof Attachment Access Controller (FEAT-10).
 *
 * Enforces session authentication, record-level authorization, and department isolation.
 * Prevents path traversal and arbitrary file access.
 * Serves verified proof files with appropriate MIME headers (inline or download).
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

$type      = trim((string) input('type', ''));
$recordId  = (int) input('id', 0);
$fileParam = trim((string) input('file', ''));
$isDownload = input('download') === '1';

$record = null;
$recordTypeKey = null;
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
            $recordTypeKey = $type;
        }
    } catch (\PDOException $e) {
        http_response_code(500);
        exit('Database error while locating record.');
    }
} elseif ($fileParam !== '') {
    $cleanFileName = basename($fileParam);
    if ($cleanFileName !== '') {
        foreach ($types as $tKey => $tInfo) {
            $tbl = $tInfo['table'];
            try {
                $stmt = db()->prepare("SELECT * FROM `{$tbl}` WHERE `proof_file` = ? LIMIT 1");
                $stmt->execute([$cleanFileName]);
                $found = $stmt->fetch();
                if ($found) {
                    $record = $found;
                    $recordTypeKey = $tKey;
                    break;
                }
            } catch (\PDOException $e) {
                continue;
            }
        }
    }
}

if (!$record) {
    http_response_code(404);
    exit('Record or proof attachment not found.');
}

// ---- Authorization Enforcement -------------------------------------------
// 1. Oversight roles (Admin, Dean, Principal, Director) can view proofs college-wide.
// 2. Department roles (HoD, Coordinator) can only view proofs within their own department.
// 3. Faculty can view proofs within their department or records they created.
$userRole   = $user['role'] ?? '';
$userDept   = trim((string) ($user['department'] ?? ''));
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

// ---- File Resolution & Path Traversal Prevention -------------------------
$storedName = basename(trim((string) ($record['proof_file'] ?? '')));
if ($storedName === '') {
    http_response_code(404);
    exit('No proof file is associated with this record.');
}

$baseDir = realpath(UPLOAD_DIR);
if (!$baseDir || !is_dir($baseDir)) {
    http_response_code(500);
    exit('Server upload directory is unconfigured or unreachable.');
}

// Search both standard upload dir and proofs/ subfolder
$candidatePaths = [
    $baseDir . DIRECTORY_SEPARATOR . 'proofs' . DIRECTORY_SEPARATOR . $storedName,
    $baseDir . DIRECTORY_SEPARATOR . $storedName,
];

$filePath = null;
foreach ($candidatePaths as $candidate) {
    $real = realpath($candidate);
    if ($real && is_file($real) && strpos($real, $baseDir) === 0) {
        $filePath = $real;
        break;
    }
}

if (!$filePath || !is_file($filePath)) {
    http_response_code(404);
    exit('The requested proof document is missing from the server storage.');
}

// ---- MIME Type Detection --------------------------------------------------
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
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

// Safe download name
$cleanRecTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr((string)($record['paper_title'] ?? $record['title'] ?? $record['event_title'] ?? $record['course_title'] ?? 'proof'), 0, 30));
$clientFilename = "Proof_{$recordTypeKey}_{$record['id']}_{$cleanRecTitle}.{$ext}";

// Stream file
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
$disposition = $isDownload ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($clientFilename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($filePath);
exit;
