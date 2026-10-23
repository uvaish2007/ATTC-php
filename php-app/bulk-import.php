<?php
/**
 * ATTS-SMARTBULK — Backend Controller & Endpoint
 *
 * Handles template downloads, file uploads, spreadsheet validation,
 * interactive previews, transactional imports, error reports, and history.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/models/BulkImport.php';

$user = require_role(['Faculty', 'Coordinator', 'HoD', 'Dean', 'Admin']);
$activeYear = active_academic_year();

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$module = trim((string)($_POST['module'] ?? $_GET['module'] ?? BulkImport::MODULE_INTERNSHIP));

// Normalize module aliases
if ($module === 'student_certifications' || $module === 'inst_student_certifications') {
    $module = BulkImport::MODULE_STUDENT_CERTIFICATION;
} elseif ($module === 'spoken_tutorials' || $module === 'inst_spoken_tutorials') {
    $module = BulkImport::MODULE_SPOKEN_TUTORIAL;
}

$validModules = [
    BulkImport::MODULE_INTERNSHIP,
    BulkImport::MODULE_SUMMER_TRAINING,
    BulkImport::MODULE_SOCIETY_FACULTY,
    BulkImport::MODULE_SOCIETY_STUDENT,
    BulkImport::MODULE_STUDENT_CERTIFICATION,
    BulkImport::MODULE_SPOKEN_TUTORIAL,
];
if (!in_array($module, $validModules, true)) {
    $module = BulkImport::MODULE_INTERNSHIP;
}

function get_bulk_redirect_url(string $mod, ?string $previewBatch = null): string {
    if ($mod === BulkImport::MODULE_SOCIETY_FACULTY || $mod === BulkImport::MODULE_SOCIETY_STUDENT) {
        $cat = ($mod === BulkImport::MODULE_SOCIETY_FACULTY) ? 'Faculty' : 'Student';
        $url = '/upload.php?section=society_memberships&type=inst_society_memberships&mode=bulk&category=' . $cat;
        if ($previewBatch) {
            $url .= '&bulk_preview=' . urlencode($previewBatch);
        }
        return $url;
    }
    if ($mod === BulkImport::MODULE_STUDENT_CERTIFICATION) {
        $url = '/upload.php?section=student_certifications&type=inst_student_certifications&mode=bulk';
        if ($previewBatch) {
            $url .= '&bulk_preview=' . urlencode($previewBatch);
        }
        return $url;
    }
    if ($mod === BulkImport::MODULE_SPOKEN_TUTORIAL) {
        $url = '/upload.php?section=spoken_tutorials&type=inst_spoken_tutorials&mode=bulk';
        if ($previewBatch) {
            $url .= '&bulk_preview=' . urlencode($previewBatch);
        }
        return $url;
    }
    $url = '/upload.php?type=' . urlencode($mod);
    if ($previewBatch) {
        $url .= '&bulk_preview=' . urlencode($previewBatch);
    }
    return $url;
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1')
    || (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'));

// ---------------------------------------------------------------------------
// 1. Download Official Excel Template
// ---------------------------------------------------------------------------
if ($action === 'template') {
    $cfg = BulkImport::getModuleConfig($module);
    $userDept = in_array($user['role'], ['Faculty', 'Coordinator', 'HoD'], true) ? ($user['department'] ?? null) : null;

    try {
        $xlsxContent = BulkImport::generateTemplate($module, $activeYear, $userDept);
        $fileName = $cfg['template_file'] ?? 'ATTS_Bulk_Import_Template.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($xlsxContent));
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');

        echo $xlsxContent;
        exit;
    } catch (\Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        flash('error', 'Failed to generate template: ' . $e->getMessage());
        redirect(get_bulk_redirect_url($module));
    }
}

// ---------------------------------------------------------------------------
// 2. Upload and Preview Excel Data
// ---------------------------------------------------------------------------
if ($action === 'upload_preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Check if system is locked for non-admins
    if ($user['role'] !== 'Admin' && function_exists('academic_year_is_locked') && academic_year_is_locked($activeYear)) {
        $msg = "Academic year {$activeYear} cycle is locked by Administrator. Submissions are frozen.";
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        redirect(get_bulk_redirect_url($module));
    }

    $file = $_FILES['excel_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = 'Please select a valid Excel (.xlsx) file to upload.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        redirect(get_bulk_redirect_url($module));
    }

    // Size limit: 10 MB
    if ($file['size'] > 10 * 1024 * 1024) {
        $msg = 'The uploaded file exceeds the 10 MB limit.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        redirect(get_bulk_redirect_url($module));
    }

    // Extension check
    $clientExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($clientExt, ['xlsx', 'csv'], true)) {
        $msg = 'Unsupported file format. Please upload an Excel workbook (.xlsx) or CSV file.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        redirect(get_bulk_redirect_url($module));
    }

    // Optional Proofs Archive (.zip) - preserved for backward compatibility
    $zipFile = $_FILES['proof_zip'] ?? null;
    $zipPath = null;
    if ($zipFile && ($zipFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $zipExt = strtolower(pathinfo($zipFile['name'] ?? '', PATHINFO_EXTENSION));
        if ($zipExt === 'zip') {
            $zipPath = $zipFile['tmp_name'];
        }
    }

    try {
        $previewData = BulkImport::validateUpload($module, $file['tmp_name'], $user, $activeYear, $zipPath);
        $previewData['file_name'] = basename($file['name'] ?? 'upload.xlsx');
        $previewData['file_size'] = (int)$file['size'];
        $previewData['zip_name']  = ($zipFile && !empty($zipFile['name'])) ? basename($zipFile['name']) : null;

        // Cache in session
        if (!isset($_SESSION['atts_bulk_previews'])) {
            $_SESSION['atts_bulk_previews'] = [];
        }
        $_SESSION['atts_bulk_previews'][$previewData['batch_id']] = $previewData;

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data'    => $previewData,
            ]);
            exit;
        }

        // Standard redirect with preview token
        redirect(get_bulk_redirect_url($module, $previewData['batch_id']));
    } catch (\Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        flash('error', $e->getMessage());
        redirect(get_bulk_redirect_url($module));
    }
}

// ---------------------------------------------------------------------------
// 3. Confirm and Commit Import
// ---------------------------------------------------------------------------
if ($action === 'confirm_import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $batchId = trim((string)($_POST['batch_id'] ?? ''));
    if ($batchId === '' || empty($_SESSION['atts_bulk_previews'][$batchId])) {
        $msg = 'Preview session expired or invalid. Please re-upload your Excel file.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        redirect(get_bulk_redirect_url($module));
    }

    $previewData = $_SESSION['atts_bulk_previews'][$batchId];

    // Security check: uploader mismatch
    if ((int)$previewData['uploader_id'] !== (int)$user['id']) {
        $msg = 'Unauthorized: Only the uploader who initiated the preview can commit this import.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        redirect(get_bulk_redirect_url($module));
    }

    try {
        $result = BulkImport::commitImport($previewData, $user);

        // Keep errors in session if user wants to download error report after partial import
        if (!empty($previewData['errors'])) {
            $_SESSION['atts_bulk_last_errors'][$batchId] = [
                'module' => $module,
                'errors' => $previewData['errors'],
            ];
        }

        // Clear preview from active session
        unset($_SESSION['atts_bulk_previews'][$batchId]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'summary' => $result,
            ]);
            exit;
        }

        flash('success', "SmartBulk Import Successful: {$result['imported_records']} records imported for {$result['module_label']}.");
        redirect(get_bulk_redirect_url($module));
    } catch (\Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        flash('error', 'Import failed: ' . $e->getMessage());
        redirect(get_bulk_redirect_url($module));
    }
}

// ---------------------------------------------------------------------------
// 4. Cancel Pending Preview
// ---------------------------------------------------------------------------
if ($action === 'cancel_preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $batchId = trim((string)($_POST['batch_id'] ?? ''));
    if ($batchId !== '' && isset($_SESSION['atts_bulk_previews'][$batchId])) {
        $p = $_SESSION['atts_bulk_previews'][$batchId];
        if (!empty($p['proofs_dir']) && is_dir($p['proofs_dir'])) {
            BulkImport::deleteDirectory($p['proofs_dir']);
        }
        unset($_SESSION['atts_bulk_previews'][$batchId]);
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    flash('info', 'Bulk upload cancelled.');
    redirect('/upload.php?type=' . urlencode($module));
}

// ---------------------------------------------------------------------------
// 5. Download Validation Error Report
// ---------------------------------------------------------------------------
if ($action === 'download_errors') {
    $batchId = trim((string)($_GET['batch_id'] ?? ''));
    $errors = [];

    if ($batchId !== '' && isset($_SESSION['atts_bulk_previews'][$batchId]['errors'])) {
        $errors = $_SESSION['atts_bulk_previews'][$batchId]['errors'];
    } elseif ($batchId !== '' && isset($_SESSION['atts_bulk_last_errors'][$batchId])) {
        $errors = $_SESSION['atts_bulk_last_errors'][$batchId]['errors'];
    } else {
        // Fallback: load from bulk_import_history table
        try {
            $stmt = db()->prepare("SELECT errors_json FROM bulk_import_history WHERE batch_id = ?");
            $stmt->execute([$batchId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['errors_json'])) {
                $errors = json_decode((string)$row['errors_json'], true) ?: [];
            }
        } catch (\PDOException $e) {
        }
    }

    if (empty($errors)) {
        flash('info', 'No validation errors found for this import batch.');
        redirect(get_bulk_redirect_url($module));
    }

    try {
        $cfg = BulkImport::getModuleConfig($module);
        $xlsxContent = BulkImport::generateErrorReport($errors, $module);
        $safeBatch = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $batchId);
        $fileName = ($safeBatch ? $safeBatch . '_' : '') . ($cfg['error_file'] ?? 'Validation_Errors.xlsx');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($xlsxContent));
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');

        echo $xlsxContent;
        exit;
    } catch (\Throwable $e) {
        flash('error', 'Failed to generate error report: ' . $e->getMessage());
        redirect(get_bulk_redirect_url($module));
    }
}

// ---------------------------------------------------------------------------
// 6. Recent Import History (JSON)
// ---------------------------------------------------------------------------
if ($action === 'history') {
    $scope = trim((string)($_GET['scope'] ?? ''));
    $userDept = in_array($user['role'], ['Faculty', 'Coordinator', 'HoD'], true) ? ($user['department'] ?? null) : null;
    $targetDept = ($scope === 'all' || !$userDept) ? null : $userDept;

    $history = BulkImport::getHistory($module, $targetDept, 50, (int)$user['id']);
    $allCount = BulkImport::countHistory($module, null);
    $deptCount = $userDept ? BulkImport::countHistory($module, $userDept) : $allCount;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'history' => $history,
        'user_department' => $userDept,
        'active_scope' => $targetDept ? 'dept' : 'all',
        'dept_count' => $deptCount,
        'all_count' => $allCount,
    ]);
    exit;
}

// Fallback
redirect(get_bulk_redirect_url($module));
