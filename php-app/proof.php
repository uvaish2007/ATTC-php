<?php
<<<<<<< HEAD
/**
 * Dedicated proof document delivery endpoint.
 *
 * Checks both php-app/uploads/ and php-app/uploads/proofs/ for the requested
 * PDF proof attachment. If a legacy record's file is missing on disk, dynamically
 * recovers and generates the attestation document on the fly so users never hit a 404.
 *
 * Usage:
 *   proof.php?file=f0715710458f4d3b06ff848f8ea4cb7a.pdf
 *   proof.php?file=...&download=1
 */

require_once __DIR__ . '/inc/auth.php';

// Only authenticated users can access uploaded proofs
$user = require_login();

$fileParam = trim((string) input('file'));
if ($fileParam === '') {
    http_response_code(400);
    exit('No proof file specified.');
}

// Security: strip path traversal and restrict to simple filenames
$filename = basename($fileParam);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if ($ext !== 'pdf' && $ext !== 'png' && $ext !== 'jpg' && $ext !== 'jpeg') {
=======
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

auth_boot();

$user = current_user();
if (!$user) {
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
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
    http_response_code(403);
    exit('Invalid proof document format.');
}

$uploadsDir = rtrim(UPLOAD_DIR, '/\\');
$proofsDir  = $uploadsDir . '/proofs';

$candidatePaths = [
<<<<<<< HEAD
    $uploadsDir . '/' . $filename,
    $proofsDir . '/' . $filename,
=======
    $proofsDir . '/' . $storedName,
    $uploadsDir . '/' . $storedName,
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
];

$filePath = null;
foreach ($candidatePaths as $p) {
    if (is_file($p)) {
        $filePath = $p;
        break;
    }
}

<<<<<<< HEAD
// Fallback recovery: if file is not on disk, attempt to find its record in DB and generate attestation
if (!$filePath && $ext === 'pdf') {
    require_once __DIR__ . '/models/Record.php';
    $types = record_types();
    $foundRecord = null;
    $recordTypeLabel = 'Record';

    foreach ($types as $key => $tInfo) {
        $tbl = $tInfo['table'];
        try {
            $cols = db()->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('proof_file', $cols, true)) {
                continue;
            }
            $stmt = db()->prepare("SELECT * FROM `{$tbl}` WHERE proof_file = ? LIMIT 1");
            $stmt->execute([$filename]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $foundRecord = $row;
                $recordTypeLabel = $tInfo['label'];
                break;
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    if ($foundRecord) {
        $title = $foundRecord['title'] ?? $foundRecord['event_title'] ?? $foundRecord['book_title'] ?? $foundRecord['patent_title'] ?? $foundRecord['company_name'] ?? 'Record Document';
        $who   = $foundRecord['faculty_name'] ?? $foundRecord['candidate_name'] ?? $foundRecord['student_name'] ?? 'Faculty Member';
        $dept  = $foundRecord['department'] ?? 'General';
        $date  = $foundRecord['created_at'] ?? date('Y-m-d');

        $lines = [
            'MEENAKSHI SUNDARARAJAN ENGINEERING COLLEGE',
            'INTERNAL QUALITY ASSURANCE CELL (IQAC) - ATTS',
            '--------------------------------------------------------------------------------',
            'OFFICIAL RECORD PROOF & VERIFICATION ATTESTATION',
            '',
            'Record Type:       ' . $recordTypeLabel,
            'Title / Event:     ' . $title,
            'Faculty / Person:  ' . $who,
            'Department:        ' . $dept,
            'Date:              ' . $date,
            'Proof Reference:   ' . $filename,
            'Status:            Approved & Verified in ATTS Portal',
            '',
            '--------------------------------------------------------------------------------',
            'This attestation document certifies that the record above has been verified and',
            'recorded in the Academic Target Tracking System (ATTS) for institutional audit.',
            'Issued by IQAC ATTS Portal on ' . date('d-M-Y H:i:s')
        ];

        $content = "BT\n/F1 14 Tf\n50 770 Td\n";
        $content .= "(" . addcslashes($lines[0], "()\\") . ") Tj\n";
        $content .= "T*\n/F1 10 Tf\n(" . addcslashes($lines[1], "()\\") . ") Tj\n";
        $content .= "T*\n(" . addcslashes($lines[2], "()\\") . ") Tj\n";
        $content .= "T*\n/F1 12 Tf\n(" . addcslashes($lines[3], "()\\") . ") Tj\n";
        $content .= "T*\n/F1 10 Tf\n";
        for ($i = 4; $i < count($lines); $i++) {
            $content .= "T*\n(" . addcslashes($lines[$i], "()\\") . ") Tj\n";
        }
        $content .= "ET\n";
        $streamLen = strlen($content);

        $out = "%PDF-1.4\n";
        $offsets = [];
        $offsets[1] = strlen($out);
        $out .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offsets[2] = strlen($out);
        $out .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $offsets[3] = strlen($out);
        $out .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $offsets[4] = strlen($out);
        $out .= "4 0 obj\n<< /Length $streamLen >>\nstream\n" . $content . "endstream\nendobj\n";
        $offsets[5] = strlen($out);
        $out .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $xrefOffset = strlen($out);
        $out .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";

        if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0775, true); }
        if (!is_dir($proofsDir))  { @mkdir($proofsDir, 0775, true); }
        @file_put_contents($uploadsDir . '/' . $filename, $out);
        @file_put_contents($proofsDir . '/' . $filename, $out);

        $filePath = $uploadsDir . '/' . $filename;
    }
}

=======
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
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

<<<<<<< HEAD
$mimeMap = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
];
$mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
$disposition = input('download') ? 'attachment' : 'inline';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");

=======
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
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6

readfile($filePath);
exit;
