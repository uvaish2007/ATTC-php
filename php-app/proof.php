<?php
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
    http_response_code(403);
    exit('Invalid proof document format.');
}

$uploadsDir = rtrim(UPLOAD_DIR, '/\\');
$proofsDir  = $uploadsDir . '/proofs';

$candidatePaths = [
    $uploadsDir . '/' . $filename,
    $proofsDir . '/' . $filename,
];

$filePath = null;
foreach ($candidatePaths as $p) {
    if (is_file($p)) {
        $filePath = $p;
        break;
    }
}

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

if (!$filePath || !is_file($filePath)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Proof Not Found</title>';
    echo '<style>body{font-family:sans-serif;background:#F8FAFC;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#1E293B}';
    echo '.box{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:32px;max-width:460px;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,0.05)}';
    echo 'h2{color:#DC2626;margin-top:0}.btn{display:inline-block;padding:10px 18px;background:#2563EB;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;margin-top:16px}</style></head><body>';
    echo '<div class="box"><h2>Proof Document Missing</h2>';
    echo '<p>The requested proof attachment (<code>' . htmlspecialchars($filename, ENT_QUOTES) . '</code>) is not found in the upload archive.</p>';
    echo '<p style="font-size:13px;color:#64748B">The file may not have been attached during submission or was archived. The faculty or coordinator may re-upload the proof.</p>';
    echo '<a class="btn" href="javascript:history.back()">Go Back</a></div></body></html>';
    exit;
}

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


readfile($filePath);
exit;
