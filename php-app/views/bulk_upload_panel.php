<?php
/**
 * ATTS-SMARTBULK — Production-Ready Bulk Student Import Interface
 *
 * Dedicated bulk import interface for Internship and Summer/Winter Training.
 * Provides downloadable Excel template, dual dropzone (Excel workbook + Compulsory Proofs ZIP),
 * client/server validation, interactive preview table, error report exporter, and commit workflow.
 *
 * Variables passed in:
 *   $selectedType : 'internship' | 'summer_training'
 *   $user         : array (logged-in user)
 *   $activeYear   : string (e.g. '2025-26')
 */

$bulkConfig = BulkImport::getModuleConfig($selectedType);
if (!$bulkConfig) {
    return;
}

$moduleKey   = $bulkConfig['key'];
$moduleLabel = $bulkConfig['label'];

// Check if there is an active preview in session from non-AJAX upload
$activePreviewId = trim((string)($_GET['bulk_preview'] ?? ''));
$sessionPreview  = (!empty($activePreviewId) && isset($_SESSION['atts_bulk_previews'][$activePreviewId]))
    ? $_SESSION['atts_bulk_previews'][$activePreviewId]
    : null;
?>

<style>
/* ==========================================================================
   ATTS-SMARTBULK THEME & STYLES (Orange #FF4F01 | Navy #131D3B)
   ========================================================================== */
.smartbulk-container {
  margin-bottom: 24px;
  font-family: inherit;
}

/* Dedicated Header Card */
.smartbulk-header-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, #131D3B 0%, #1E293B 100%);
  color: #FFFFFF;
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
  box-shadow: 0 4px 16px rgba(19, 29, 59, 0.08);
  gap: 16px;
  flex-wrap: wrap;
}
.smartbulk-badge-brand {
  background: #FF4F01;
  color: #FFFFFF;
  font-size: 10.5px;
  font-weight: 800;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 6px;
  letter-spacing: 0.05em;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.smartbulk-badge-req {
  background: #DC2626;
  color: #FFFFFF;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 4px;
  letter-spacing: 0.03em;
}

/* Panel Body */
.smartbulk-card {
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  padding: 24px;
  box-shadow: 0 4px 16px rgba(19, 29, 59, 0.03);
  transition: all 0.25s ease;
}

/* Step 1 & 2 Grid */
.smartbulk-steps-grid {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 20px;
  margin-bottom: 24px;
}
@media (max-width: 900px) {
  .smartbulk-steps-grid {
    grid-template-columns: 1fr;
  }
}
.smartbulk-step-box {
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 12px;
  padding: 20px;
  position: relative;
  display: flex;
  flex-direction: column;
}
.smartbulk-step-box.highlight {
  border-color: #FED7AA;
  background: #FFFCF9;
}
.smartbulk-step-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #131D3B;
  color: #FFFFFF;
  font-size: 13px;
  font-weight: 700;
  display: grid;
  place-items: center;
  margin-bottom: 12px;
}
.smartbulk-step-num.brand {
  background: #FF4F01;
}
.smartbulk-step-title {
  font-size: 15px;
  font-weight: 700;
  color: #131D3B;
  margin: 0 0 6px;
}
.smartbulk-step-desc {
  font-size: 12.5px;
  color: #64748B;
  line-height: 1.5;
  margin: 0 0 16px;
}

/* Dropzone */
.smartbulk-dropzone {
  border: 2px dashed #CBD5E1;
  border-radius: 12px;
  padding: 22px 18px;
  text-align: center;
  background: #FFFFFF;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}
.smartbulk-dropzone:hover, .smartbulk-dropzone.dragover {
  border-color: #FF4F01;
  background: #FFFBF9;
}
.smartbulk-dropzone-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: #FFF3EC;
  color: #FF4F01;
  display: grid;
  place-items: center;
  margin: 0 auto 10px;
}
.smartbulk-dropzone.zip-box {
  border-color: #CBD5E1;
  background: #F8FAFC;
}
.smartbulk-dropzone.zip-box:hover, .smartbulk-dropzone.zip-box.dragover {
  border-color: #2563EB;
  background: #EFF6FF;
}
.smartbulk-file-pill {
  display: none;
  align-items: center;
  justify-content: space-between;
  background: #F1F5F9;
  border: 1px solid #CBD5E1;
  border-radius: 8px;
  padding: 10px 14px;
  margin-top: 10px;
}
.smartbulk-file-pill.zip-pill {
  background: #EFF6FF;
  border-color: #BFDBFE;
}

/* Summary metrics */
.smartbulk-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 20px;
}
@media (max-width: 640px) {
  .smartbulk-metrics-grid { grid-template-columns: repeat(2, 1fr); }
}
.smartbulk-metric-card {
  padding: 14px 16px;
  border-radius: 10px;
  border: 1px solid #E2E8F0;
  background: #FFFFFF;
}
.smartbulk-metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #131D3B;
  line-height: 1.2;
}
.smartbulk-metric-lbl {
  font-size: 11.5px;
  color: #64748B;
  text-transform: uppercase;
  font-weight: 600;
  letter-spacing: 0.03em;
  margin-top: 4px;
}
.smartbulk-metric-card.green { border-color: #BBF7D0; background: #F0FDF4; }
.smartbulk-metric-card.green .smartbulk-metric-val { color: #166534; }
.smartbulk-metric-card.yellow { border-color: #FED7AA; background: #FFF7ED; }
.smartbulk-metric-card.yellow .smartbulk-metric-val { color: #9A3412; }
.smartbulk-metric-card.red { border-color: #FECACA; background: #FEF2F2; }
.smartbulk-metric-card.red .smartbulk-metric-val { color: #991B1B; }

/* Table */
.smartbulk-table-wrap {
  overflow-x: auto;
  border: 1px solid #E2E8F0;
  border-radius: 10px;
  margin-bottom: 20px;
  max-height: 440px;
}
.smartbulk-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
  text-align: left;
}
.smartbulk-table th {
  background: #F8FAFC;
  color: #475569;
  font-weight: 700;
  padding: 10px 12px;
  border-bottom: 1px solid #E2E8F0;
  position: sticky;
  top: 0;
  z-index: 2;
  white-space: nowrap;
}
.smartbulk-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #F1F5F9;
  color: #1E293B;
  vertical-align: middle;
}
.smartbulk-table tr:hover td {
  background: #F8FAFC;
}

/* Status Badges */
.smartbulk-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
}
.smartbulk-badge.valid { background: #DCFCE7; color: #166534; }
.smartbulk-badge.duplicate { background: #FFEDD5; color: #9A3412; }
.smartbulk-badge.invalid { background: #FEE2E2; color: #991B1B; }

/* Action Buttons */
.btn-smartbulk {
  background: #FF4F01;
  color: #FFFFFF;
  border: none;
  font-weight: 600;
  border-radius: 8px;
  padding: 11px 22px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s ease;
  font-size: 13.5px;
}
.btn-smartbulk:hover {
  background: #E04400;
  box-shadow: 0 4px 12px rgba(255, 79, 1, 0.25);
}
.btn-smartbulk:disabled {
  opacity: 0.55;
  cursor: not-allowed;
  box-shadow: none;
}
.btn-smartbulk-secondary {
  background: #FFFFFF;
  color: #131D3B;
  border: 1px solid #CBD5E1;
  font-weight: 600;
  border-radius: 8px;
  padding: 9px 18px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s ease;
  font-size: 13px;
  text-decoration: none;
}
.btn-smartbulk-secondary:hover {
  background: #F8FAFC;
  border-color: #94A3B8;
  color: #0F172A;
}

/* Loading Spinner */
.smartbulk-spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: #FFFFFF;
  animation: sbSpin 0.7s linear infinite;
}
@keyframes sbSpin {
  to { transform: rotate(360deg); }
}

/* =========================================================================
   SmartBulk Import History Modal Styles
   ========================================================================= */
.smartbulk-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  animation: sbFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.smartbulk-modal-dialog {
  background: #FFFFFF;
  border-radius: 16px;
  width: 100%;
  max-width: 1080px;
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.4), 0 0 0 1px rgba(226, 232, 240, 0.8);
  overflow: hidden;
  animation: sbScaleIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

.smartbulk-modal-header {
  padding: 18px 24px;
  border-bottom: 1px solid #E2E8F0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #FFFFFF;
}

.smartbulk-modal-icon {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: linear-gradient(135deg, rgba(255, 79, 1, 0.12), rgba(255, 122, 0, 0.18));
  color: #FF4F01;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.smartbulk-btn-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid #E2E8F0;
  background: #F8FAFC;
  color: #64748B;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.15s ease;
}
.smartbulk-btn-icon:hover {
  background: #EEF2F6;
  color: #0F172A;
  border-color: #CBD5E1;
}

.smartbulk-modal-toolbar {
  padding: 12px 24px;
  background: #F8FAFC;
  border-bottom: 1px solid #E2E8F0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.smartbulk-scope-pills {
  display: flex;
  gap: 8px;
  align-items: center;
}

.sb-scope-pill {
  border: 1px solid #CBD5E1;
  background: #FFFFFF;
  color: #475569;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s ease;
}
.sb-scope-pill:hover {
  background: #F1F5F9;
  border-color: #94A3B8;
  color: #0F172A;
}
.sb-scope-pill.active {
  background: #131D3B;
  border-color: #131D3B;
  color: #FFFFFF;
  box-shadow: 0 2px 6px rgba(19, 29, 59, 0.2);
}
.sb-scope-pill .sb-count-badge {
  background: rgba(0, 0, 0, 0.08);
  padding: 1px 7px;
  border-radius: 10px;
  font-size: 11px;
}
.sb-scope-pill.active .sb-count-badge {
  background: rgba(255, 255, 255, 0.2);
  color: #FFFFFF;
}

.smartbulk-input-search {
  width: 100%;
  padding: 8px 12px 8px 34px;
  font-size: 12.5px;
  border: 1px solid #CBD5E1;
  border-radius: 8px;
  background: #FFFFFF;
  color: #0F172A;
  outline: none;
  transition: border-color 0.15s ease;
}
.smartbulk-input-search:focus {
  border-color: #FF4F01;
  box-shadow: 0 0 0 3px rgba(255, 79, 1, 0.12);
}

.smartbulk-modal-body {
  padding: 20px 24px;
  overflow-y: auto;
  flex: 1;
  min-height: 280px;
  max-height: calc(88vh - 160px);
}

.smartbulk-modal-footer {
  padding: 14px 24px;
  border-top: 1px solid #E2E8F0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #F8FAFC;
}

.sb-batch-badge {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 11.5px;
  font-weight: 700;
  color: #1E293B;
  background: #F1F5F9;
  border: 1px solid #E2E8F0;
  padding: 2px 8px;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all 0.15s ease;
}
.sb-batch-badge:hover {
  background: #E2E8F0;
  border-color: #CBD5E1;
}

.sb-history-details-row {
  background: #F8FAFC;
  border-left: 3px solid #FF4F01;
  padding: 14px 18px;
  margin: 4px 0;
  border-radius: 0 8px 8px 0;
  font-size: 12.5px;
}

@keyframes sbFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes sbScaleIn {
  from { opacity: 0; transform: scale(0.97) translateY(8px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
</style>

<div class="smartbulk-container" id="smartbulkRoot" data-module="<?= e($moduleKey) ?>">
  <!-- Dedicated Header Card (Branded) -->
  <div class="smartbulk-header-card">
    <div>
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
        <span class="smartbulk-badge-brand">
          <?= icon('upload', 12) ?> ATTS SMARTBULK
        </span>
        <span style="font-size:12.5px; color:#94A3B8; font-weight:600;">Dedicated Batch Upload Portal</span>
      </div>
      <h2 style="font-size:20px; font-weight:700; color:#FFFFFF; margin:0 0 6px;">
        <?= e($moduleLabel) ?> &mdash; Student Bulk Data Import
      </h2>
      <div style="font-size:12.5px; color:#CBD5E1; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span>Department: <strong style="color:#FFFFFF"><?= e($user['department'] ?: 'All Departments') ?></strong></span>
        <span>&middot;</span>
        <span>Academic Year: <strong style="color:#FFFFFF"><?= e($activeYear) ?></strong></span>
        <span>&middot;</span>
        <span>Proof Mode: <strong style="color:#FF9566;">Compulsory ZIP Archive (1:1 Student Match)</strong></span>
      </div>
    </div>
    <div style="display:flex; gap:10px;">
      <button type="button" class="btn-smartbulk-secondary" onclick="if(typeof switchUploadMode==='function'){switchUploadMode('individual');}else{window.location.href='upload.php?type=<?= urlencode($moduleKey) ?>';}" style="background:rgba(255,255,255,0.08); border-color:rgba(255,255,255,0.2); color:#FFFFFF;">
        <?= icon('user', 14) ?> Single Entry Form
      </button>
      <button type="button" class="btn-smartbulk-secondary" id="sbHeaderHistoryBtn" onclick="smartbulkOpenHistoryModal()" style="background:rgba(255,255,255,0.08); border-color:rgba(255,255,255,0.2); color:#FFFFFF;">
        <?= icon('clock', 14) ?> Import History
      </button>
    </div>
  </div>

  <!-- SmartBulk Panel Card -->
  <div class="smartbulk-card" id="smartbulkPanel">
    <!-- Alert Banner (dynamic messages) -->
    <div id="smartbulkAlert" style="display:none; margin-bottom:16px; border-radius:10px; padding:12px 16px; font-size:13px; line-height:1.5;"></div>

    <!-- Steps 1 & 2: Template & Upload -->
    <div id="smartbulkUploadSection" class="smartbulk-steps-grid">
      <!-- Step 1: Download Official Template -->
      <div class="smartbulk-step-box">
        <div class="smartbulk-step-num">1</div>
        <h4 class="smartbulk-step-title">Download Official Template</h4>
        <p class="smartbulk-step-desc">
          Get the official ATTS multi-sheet Excel workbook (.xlsx). It includes predefined column headers (Student Register Number, Student Name, Duration, etc.) and guidance notes.
        </p>
        <div style="margin-top:auto;">
          <a class="btn-smartbulk-secondary" style="width:100%; justify-content:center; padding:11px;" href="<?= e(url('bulk-import.php?action=template&module=' . urlencode($moduleKey))) ?>">
            <?= icon('download', 16) ?> Download Excel Template (.xlsx)
          </a>
        </div>
      </div>

      <!-- Step 2: Upload Files (Excel + Compulsory ZIP Archive) -->
      <div class="smartbulk-step-box highlight">
        <div class="smartbulk-step-num brand">2</div>
        <h4 class="smartbulk-step-title">Upload Completed Excel &amp; Proofs Archive</h4>
        <p class="smartbulk-step-desc">
          Upload your completed Excel workbook and the companion ZIP archive containing student PDF certificates. Both files are validated and matched 1:1 before import.
        </p>
        
        <form id="smartbulkUploadForm" action="<?= e(url('bulk-import.php')) ?>" method="post" enctype="multipart/form-data" onsubmit="smartbulkHandleUpload(event)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_preview">
          <input type="hidden" name="module" value="<?= e($moduleKey) ?>">
          <input type="hidden" name="is_ajax" value="1">
          
          <!-- File Input 1: Excel Workbook -->
          <input type="file" id="smartbulkFileInput" name="excel_file" accept=".xlsx,.csv" style="display:none;" onchange="smartbulkFileSelected(this)">

          <div style="margin-bottom:14px;">
            <label style="font-size:12.5px; font-weight:700; color:#131D3B; display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
              <span>1. Excel Student Records Spreadsheet <span style="color:#DC2626;">*</span></span>
              <span style="font-size:11px; color:#64748B; font-weight:normal;">.xlsx or .csv (≤ 10 MB)</span>
            </label>
            <div class="smartbulk-dropzone" id="smartbulkDropzone" onclick="document.getElementById('smartbulkFileInput').click()">
              <div class="smartbulk-dropzone-icon">
                <?= icon('file-text', 20) ?>
              </div>
              <div style="font-weight:700; color:#131D3B; font-size:13.5px; margin-bottom:3px;">
                Click to browse or drag &amp; drop Excel file
              </div>
              <div style="font-size:11.5px; color:#64748B;">
                Upload the completed ATTS student records workbook
              </div>
            </div>

            <div class="smartbulk-file-pill" id="smartbulkFilePill">
              <div style="display:flex; align-items:center; gap:8px; overflow:hidden;">
                <span style="color:#FF4F01;"><?= icon('file-text', 16) ?></span>
                <span id="smartbulkFileName" style="font-weight:600; font-size:12.5px; color:#131D3B; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;"></span>
                <span id="smartbulkFileSize" style="font-size:11.5px; color:#64748B;"></span>
              </div>
              <button type="button" style="border:none; background:transparent; color:#EF4444; font-size:12px; font-weight:600; cursor:pointer;" onclick="smartbulkClearFile()">
                Remove
              </button>
            </div>
          </div>

          <!-- File Input 2: Student PDF Proofs ZIP Archive (COMPULSORY) -->
          <input type="file" id="smartbulkZipInput" name="proof_zip" accept=".zip" style="display:none;" onchange="smartbulkZipSelected(this)">
          
          <div style="margin-bottom:16px;">
            <label style="font-size:12.5px; font-weight:700; color:#131D3B; display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
              <span style="display:flex; align-items:center; gap:6px;">
                <span>2. Student Proof Certificates Archive (.zip)</span>
                <span class="smartbulk-badge-req">COMPULSORY</span>
              </span>
              <span style="font-size:11px; color:#64748B; font-weight:normal;">.zip (≤ 50 MB)</span>
            </label>
            <div class="smartbulk-dropzone zip-box" id="smartbulkZipDropzone" onclick="document.getElementById('smartbulkZipInput').click()">
              <div class="smartbulk-dropzone-icon" style="background:#EFF6FF; color:#1D4ED8;">
                <?= icon('paperclip', 20) ?>
              </div>
              <div style="font-weight:700; color:#131D3B; font-size:13.5px; margin-bottom:3px;">
                Click to browse or drag &amp; drop Proofs ZIP archive
              </div>
              <div style="font-size:11.5px; color:#64748B; max-width:480px; margin:0 auto;">
                Bundle all student certificate PDFs in a ZIP. Name each PDF by the student's Register Number (e.g. <code>711322104001.pdf</code>).
              </div>
            </div>

            <div class="smartbulk-file-pill zip-pill" id="smartbulkZipPill">
              <div style="display:flex; align-items:center; gap:8px; overflow:hidden;">
                <span style="color:#1D4ED8;"><?= icon('check', 16) ?></span>
                <span id="smartbulkZipName" style="font-weight:600; font-size:12.5px; color:#1D4ED8; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;"></span>
                <span id="smartbulkZipSize" style="font-size:11.5px; color:#64748B;"></span>
              </div>
              <button type="button" style="border:none; background:transparent; color:#EF4444; font-size:12px; font-weight:600; cursor:pointer;" onclick="smartbulkClearZip()">
                Remove
              </button>
            </div>
          </div>

          <!-- Notice & Validate Button -->
          <div id="smartbulkValidateNotice" style="font-size:11.5px; color:#B45309; background:#FFFBEB; border:1px solid #FDE68A; padding:8px 12px; border-radius:6px; margin-bottom:12px;">
            <?= icon('info', 14) ?> Both the Excel workbook (.xlsx) and the Proofs ZIP archive (.zip) are required to proceed.
          </div>

          <div>
            <button type="submit" id="btnSmartbulkValidate" class="btn-smartbulk" style="width:100%; justify-content:center;" disabled>
              <span id="smartbulkBtnText"><?= icon('check-circle', 16) ?> Validate &amp; Preview Spreadsheet</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Processing Indicator -->
    <div id="smartbulkProcessing" style="display:none; text-align:center; padding:40px 20px;">
      <div style="width:48px; height:48px; border:4px solid #E2E8F0; border-top-color:#FF4F01; border-radius:50%; animation:sbSpin 0.7s linear infinite; margin:0 auto 16px;"></div>
      <div style="font-weight:700; color:#131D3B; font-size:15px; margin-bottom:4px;">Processing Excel Workbook &amp; Proofs Archive...</div>
      <div style="font-size:13px; color:#64748B;">Extracting student rows, validating department rules, and matching proof certificates by register number.</div>
    </div>

    <!-- Step 3: Interactive Preview & Validation Results -->
    <div id="smartbulkPreviewSection" style="display:none;">
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <div>
          <h4 style="font-size:16px; font-weight:700; color:#131D3B; margin:0 0 2px;">
            Validation Summary &amp; Data Preview
          </h4>
          <span style="font-size:12.5px; color:#64748B;">
            Batch ID: <code id="sbPreviewBatchId" style="background:#F1F5F9; padding:2px 6px; border-radius:4px; font-weight:700;"></code>
          </span>
        </div>
        <div style="display:flex; gap:8px;">
          <button type="button" class="btn-smartbulk-secondary" style="font-size:12px;" onclick="smartbulkResetToUpload()">
            <?= icon('x', 14) ?> Upload Different File
          </button>
          <a id="btnDownloadErrors" class="btn-smartbulk-secondary" style="font-size:12px; display:none;" href="#">
            <?= icon('download', 14) ?> Download Error Report (.xlsx)
          </a>
        </div>
      </div>

      <!-- Metrics -->
      <div class="smartbulk-metrics-grid">
        <div class="smartbulk-metric-card">
          <div class="smartbulk-metric-val" id="sbMetricTotal">0</div>
          <div class="smartbulk-metric-lbl">Total Detected</div>
        </div>
        <div class="smartbulk-metric-card green">
          <div class="smartbulk-metric-val" id="sbMetricValid">0</div>
          <div class="smartbulk-metric-lbl">Valid (Ready to Import)</div>
        </div>
        <div class="smartbulk-metric-card yellow">
          <div class="smartbulk-metric-val" id="sbMetricDuplicate">0</div>
          <div class="smartbulk-metric-lbl">Duplicates Detected</div>
        </div>
        <div class="smartbulk-metric-card red">
          <div class="smartbulk-metric-val" id="sbMetricInvalid">0</div>
          <div class="smartbulk-metric-lbl">Invalid Rows (Errors)</div>
        </div>
      </div>

      <!-- Filter Controls for Preview Table -->
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-smartbulk-secondary" style="font-size:11.5px; padding:4px 10px;" onclick="smartbulkFilterTable('all')">All Rows (<span id="sbFilterCountAll">0</span>)</button>
          <button type="button" class="btn-smartbulk-secondary" style="font-size:11.5px; padding:4px 10px;" onclick="smartbulkFilterTable('valid')">Valid Only (<span id="sbFilterCountValid">0</span>)</button>
          <button type="button" class="btn-smartbulk-secondary" style="font-size:11.5px; padding:4px 10px;" onclick="smartbulkFilterTable('duplicate')">Duplicates (<span id="sbFilterCountDup">0</span>)</button>
          <button type="button" class="btn-smartbulk-secondary" style="font-size:11.5px; padding:4px 10px;" onclick="smartbulkFilterTable('invalid')">Errors (<span id="sbFilterCountErr">0</span>)</button>
        </div>
        <div>
          <input type="text" id="sbTableSearch" placeholder="Search preview..." oninput="smartbulkSearchTable(this.value)" style="border:1px solid #CBD5E1; border-radius:6px; padding:5px 10px; font-size:12px; width:200px;">
        </div>
      </div>

      <!-- Preview Table -->
      <div class="smartbulk-table-wrap">
        <table class="smartbulk-table" id="sbPreviewTable">
          <thead>
            <tr>
              <th style="width:50px;">Row</th>
              <th style="width:90px;">Status</th>
              <th>Reg. No</th>
              <th>Student Name</th>
              <th>Department</th>
              <th>Academic Year</th>
              <th>Title</th>
              <th>Organization</th>
              <th>Days</th>
              <th>Proof Certificate (ZIP)</th>
              <th>Validation / Issues</th>
            </tr>
          </thead>
          <tbody id="sbPreviewTableBody">
            <!-- Dynamically populated -->
          </tbody>
        </table>
      </div>

      <!-- Validation Errors Details Accordion (if errors exist) -->
      <div id="sbErrorsContainer" style="display:none; background:#FEF2F2; border:1px solid #FECACA; border-radius:10px; padding:16px; margin-bottom:20px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span style="color:#DC2626;"><?= icon('alert-circle', 18) ?></span>
            <strong style="color:#991B1B; font-size:13.5px;">Validation Error Breakdown (<span id="sbErrorTotalCount">0</span> issues)</strong>
          </div>
          <span style="font-size:12px; color:#B91C1C;">Rows with errors will be isolated from the import.</span>
        </div>
        <div style="max-height:180px; overflow-y:auto; border:1px solid #FCA5A5; border-radius:6px; background:#FFFFFF;">
          <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
              <tr style="background:#FEE2E2; color:#991B1B; text-align:left;">
                <th style="padding:6px 10px;">Row #</th>
                <th style="padding:6px 10px;">Field</th>
                <th style="padding:6px 10px;">Problem</th>
                <th style="padding:6px 10px;">Submitted Value</th>
              </tr>
            </thead>
            <tbody id="sbErrorsTableBody">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- Actions Bar -->
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding-top:10px; border-top:1px solid #F1F5F9;">
        <div style="font-size:12.5px; color:#64748B;">
          Ready to commit <strong id="sbReadyCommitCount" style="color:#166534">0</strong> valid record(s). Existing duplicates and invalid rows will not be inserted.
        </div>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn-smartbulk-secondary" onclick="smartbulkCancelPreview()">
            Cancel
          </button>
          <button type="button" id="btnConfirmImport" class="btn-smartbulk" onclick="smartbulkConfirmImport()">
            <span id="sbConfirmBtnText"><?= icon('check', 16) ?> Confirm &amp; Import Valid Records</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Step 4: Import Complete Success Screen -->
    <div id="smartbulkSuccessSection" style="display:none; text-align:center; padding:40px 20px;">
      <div style="width:56px; height:56px; border-radius:50%; background:#DCFCE7; color:#166534; display:grid; place-items:center; margin:0 auto 16px;">
        <?= icon('check', 28) ?>
      </div>
      <h3 style="font-size:20px; font-weight:700; color:#131D3B; margin:0 0 8px;">
        Bulk Import Completed Successfully!
      </h3>
      <p id="sbSuccessMessage" style="font-size:14px; color:#64748B; margin:0 0 24px; max-width:540px; margin-inline:auto;">
        All valid student records have been written to the database with attached proofs and entered into the verification workflow.
      </p>

      <div style="max-width:440px; margin:0 auto 24px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:16px;">
        <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:8px;">
          <span style="color:#64748B">Records Successfully Imported:</span>
          <strong id="sbSuccessImported" style="color:#166534">0</strong>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:8px;">
          <span style="color:#64748B">Duplicates Skipped:</span>
          <strong id="sbSuccessDuplicates" style="color:#9A3412">0</strong>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px;">
          <span style="color:#64748B">Workflow Status:</span>
          <strong style="color:#1D4ED8">Submitted (Pending Verification)</strong>
        </div>
      </div>

      <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
        <button type="button" class="btn-smartbulk-secondary" onclick="smartbulkResetToUpload()">
          <?= icon('upload', 14) ?> Upload Another File
        </button>
        <a class="btn-smartbulk" href="<?= e(url('approvals.php?type=' . urlencode($moduleKey))) ?>">
          <?= icon('check-circle', 16) ?> View Pending Records in Approvals
        </a>
      </div>
    </div>
  </div>
</div>

<!-- SmartBulk Import History Modal Dialog -->
<div id="smartbulkHistoryModal" class="smartbulk-modal-overlay" style="display:none;" onclick="smartbulkOnModalBackdropClick(event)">
  <div class="smartbulk-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sbHistoryModalTitle">
    <!-- Header -->
    <div class="smartbulk-modal-header">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="smartbulk-modal-icon">
          <?= icon('clock', 22) ?>
        </div>
        <div>
          <h3 id="sbHistoryModalTitle" style="margin:0; font-size:18px; font-weight:700; color:#131D3B;">
            Bulk Import History &amp; Audit Trail
          </h3>
          <p style="margin:2px 0 0; font-size:12.5px; color:#64748B;">
            <?= e($moduleLabel) ?> &bull; Audit past spreadsheets, imported records, and error workbooks
          </p>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:8px;">
        <button type="button" class="smartbulk-btn-icon" onclick="smartbulkLoadHistory(currentHistoryScope)" title="Refresh History">
          <?= icon('refresh', 16) ?>
        </button>
        <button type="button" class="smartbulk-btn-icon" onclick="smartbulkCloseHistoryModal()" title="Close Dialog">
          <?= icon('x', 18) ?>
        </button>
      </div>
    </div>

    <!-- Toolbar: Scope Filter & Search -->
    <div class="smartbulk-modal-toolbar">
      <div class="smartbulk-scope-pills" id="sbHistoryScopePills">
        <!-- Scope pills dynamically populated -->
      </div>
      <div style="position:relative; flex:1; max-width:320px;">
        <span style="position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94A3B8; pointer-events:none; display:flex;">
          <?= icon('search', 14) ?>
        </span>
        <input type="text" id="sbHistorySearchInput" placeholder="Search batch ID, file, uploader..." oninput="smartbulkFilterHistoryTable()" class="smartbulk-input-search">
      </div>
    </div>

    <!-- Body -->
    <div class="smartbulk-modal-body" id="sbHistoryModalBody">
      <div style="padding:48px 20px; text-align:center;">
        <span class="smartbulk-spinner" style="border-color:rgba(255,79,1,0.25); border-top-color:#FF4F01; width:28px; height:28px; margin-bottom:12px;"></span>
        <p style="color:#64748B; font-size:13.5px; margin:0;">Loading import history...</p>
      </div>
    </div>

    <!-- Footer -->
    <div class="smartbulk-modal-footer">
      <div style="font-size:12px; color:#64748B;" id="sbHistoryFooterCount">
        0 batch records
      </div>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn-smartbulk-secondary" style="padding:7px 18px; font-size:12.5px;" onclick="smartbulkCloseHistoryModal()">
          Close
        </button>
      </div>
    </div>
  </div>
</div>

<script>
/**
 * ATTS-SMARTBULK Client-Side Controller
 */
var BULK_ENDPOINT = '<?= e(url("bulk-import.php")) ?>';
var currentBulkState = {
  batchId: null,
  previewData: null,
  activeFilter: 'all',
  searchQuery: '',
};

// Drag & drop support for Excel dropzone
(function initExcelDropzone() {
  var dz = document.getElementById('smartbulkDropzone');
  if (!dz) return;

  ['dragenter', 'dragover'].forEach(function(evt) {
    dz.addEventListener(evt, function(e) {
      e.preventDefault(); e.stopPropagation();
      dz.classList.add('dragover');
    }, false);
  });

  ['dragleave', 'drop'].forEach(function(evt) {
    dz.addEventListener(evt, function(e) {
      e.preventDefault(); e.stopPropagation();
      dz.classList.remove('dragover');
    }, false);
  });

  dz.addEventListener('drop', function(e) {
    var dt = e.dataTransfer;
    var files = dt.files;
    if (files.length > 0) {
      var fileInput = document.getElementById('smartbulkFileInput');
      fileInput.files = files;
      smartbulkFileSelected(fileInput);
    }
  }, false);
})();

// Drag & drop support for ZIP dropzone
(function initZipDropzone() {
  var dz = document.getElementById('smartbulkZipDropzone');
  if (!dz) return;

  ['dragenter', 'dragover'].forEach(function(evt) {
    dz.addEventListener(evt, function(e) {
      e.preventDefault(); e.stopPropagation();
      dz.classList.add('dragover');
    }, false);
  });

  ['dragleave', 'drop'].forEach(function(evt) {
    dz.addEventListener(evt, function(e) {
      e.preventDefault(); e.stopPropagation();
      dz.classList.remove('dragover');
    }, false);
  });

  dz.addEventListener('drop', function(e) {
    var dt = e.dataTransfer;
    var files = dt.files;
    if (files.length > 0) {
      var zipInput = document.getElementById('smartbulkZipInput');
      zipInput.files = files;
      smartbulkZipSelected(zipInput);
    }
  }, false);
})();

function smartbulkUpdateValidationBtnState() {
  var fileIn = document.getElementById('smartbulkFileInput');
  var zipIn  = document.getElementById('smartbulkZipInput');
  var hasExcel = fileIn && fileIn.files && fileIn.files.length > 0;
  var hasZip   = zipIn && zipIn.files && zipIn.files.length > 0;
  var btnVal   = document.getElementById('btnSmartbulkValidate');
  var notice   = document.getElementById('smartbulkValidateNotice');

  if (hasExcel && hasZip) {
    btnVal.disabled = false;
    if (notice) notice.style.display = 'none';
  } else {
    btnVal.disabled = true;
    if (notice) {
      notice.style.display = 'block';
      if (!hasExcel && !hasZip) {
        notice.innerHTML = '<?= icon("info", 14) ?> Both the Excel workbook (.xlsx) and the Proofs ZIP archive (.zip) are required to proceed.';
      } else if (!hasExcel) {
        notice.innerHTML = '<?= icon("info", 14) ?> Please choose the Excel workbook (.xlsx) to continue.';
      } else {
        notice.innerHTML = '<?= icon("alert-circle", 14) ?> <strong>Compulsory:</strong> Please choose the Student Proofs archive (.zip) containing student PDF certificates.';
      }
    }
  }
}

function smartbulkFileSelected(input) {
  if (!input.files || input.files.length === 0) return;
  var f = input.files[0];
  var pill = document.getElementById('smartbulkFilePill');
  var nameEl = document.getElementById('smartbulkFileName');
  var sizeEl = document.getElementById('smartbulkFileSize');

  nameEl.textContent = f.name;
  var sz = f.size < 1048576 ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / 1048576).toFixed(2) + ' MB';
  sizeEl.textContent = '(' + sz + ')';
  pill.style.display = 'flex';
  smartbulkUpdateValidationBtnState();
  smartbulkHideAlert();
}

function smartbulkClearFile() {
  var input = document.getElementById('smartbulkFileInput');
  if (input) input.value = '';
  document.getElementById('smartbulkFilePill').style.display = 'none';
  smartbulkUpdateValidationBtnState();
}

function smartbulkZipSelected(input) {
  if (!input.files || input.files.length === 0) return;
  var f = input.files[0];
  var pill = document.getElementById('smartbulkZipPill');
  var nameEl = document.getElementById('smartbulkZipName');
  var sizeEl = document.getElementById('smartbulkZipSize');
  var sz = f.size < 1048576 ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / 1048576).toFixed(2) + ' MB';
  nameEl.textContent = f.name;
  sizeEl.textContent = '(' + sz + ')';
  pill.style.display = 'flex';
  smartbulkUpdateValidationBtnState();
  smartbulkHideAlert();
}

function smartbulkClearZip() {
  var input = document.getElementById('smartbulkZipInput');
  if (input) input.value = '';
  var pill = document.getElementById('smartbulkZipPill');
  if (pill) pill.style.display = 'none';
  smartbulkUpdateValidationBtnState();
}

function smartbulkShowAlert(msg, type) {
  var el = document.getElementById('smartbulkAlert');
  if (!el) return;
  el.style.display = 'block';
  el.innerHTML = msg;
  if (type === 'error') {
    el.style.background = '#FEF2F2';
    el.style.border = '1px solid #FECACA';
    el.style.color = '#991B1B';
  } else if (type === 'success') {
    el.style.background = '#F0FDF4';
    el.style.border = '1px solid #BBF7D0';
    el.style.color = '#166534';
  } else {
    el.style.background = '#EFF6FF';
    el.style.border = '1px solid #BFDBFE';
    el.style.color = '#1E40AF';
  }
}

function smartbulkHideAlert() {
  var el = document.getElementById('smartbulkAlert');
  if (el) el.style.display = 'none';
}

function smartbulkHandleUpload(e) {
  e.preventDefault();
  var form = document.getElementById('smartbulkUploadForm');
  var fileInput = document.getElementById('smartbulkFileInput');
  var zipInput  = document.getElementById('smartbulkZipInput');

  if (!fileInput.files || fileInput.files.length === 0) {
    smartbulkShowAlert('Please select an Excel (.xlsx) file to upload.', 'error');
    return;
  }
  if (!zipInput.files || zipInput.files.length === 0) {
    smartbulkShowAlert('Student proofs archive (.zip) is compulsory. Please select a .zip file containing PDF certificates for all students.', 'error');
    return;
  }

  // Ensure CSRF token is present
  var csrfInput = document.querySelector('input[name="csrf"]') || document.querySelector('input[name="csrf_token"]');
  var csrfToken = csrfInput ? csrfInput.value : '';

  var formData = new FormData();
  formData.append('action', 'upload_preview');
  formData.append('module', document.getElementById('smartbulkRoot').getAttribute('data-module'));
  formData.append('csrf', csrfToken);
  formData.append('csrf_token', csrfToken);
  formData.append('is_ajax', '1');
  formData.append('excel_file', fileInput.files[0]);
  formData.append('proof_zip', zipInput.files[0]);

  // Show processing indicator
  document.getElementById('smartbulkUploadSection').style.display = 'none';
  document.getElementById('smartbulkProcessing').style.display = 'block';
  smartbulkHideAlert();

  fetch(BULK_ENDPOINT, {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(function(res) {
    return res.json();
  })
  .then(function(json) {
    document.getElementById('smartbulkProcessing').style.display = 'none';
    if (!json.success) {
      document.getElementById('smartbulkUploadSection').style.display = 'grid';
      smartbulkShowAlert(json.error || 'Failed to validate spreadsheet.', 'error');
      return;
    }
    smartbulkRenderPreview(json.data);
  })
  .catch(function(err) {
    document.getElementById('smartbulkProcessing').style.display = 'none';
    document.getElementById('smartbulkUploadSection').style.display = 'grid';
    smartbulkShowAlert('Failed to communicate with the server: ' + err.message, 'error');
  });
}

function smartbulkRenderPreview(data) {
  currentBulkState.batchId = data.batch_id;
  currentBulkState.previewData = data;

  document.getElementById('sbPreviewBatchId').textContent = data.batch_id;
  document.getElementById('sbMetricTotal').textContent = data.total_detected;
  document.getElementById('sbMetricValid').textContent = data.valid_count;
  document.getElementById('sbMetricDuplicate').textContent = data.duplicate_count;
  document.getElementById('sbMetricInvalid').textContent = data.invalid_count;

  document.getElementById('sbFilterCountAll').textContent = data.total_detected;
  document.getElementById('sbFilterCountValid').textContent = data.valid_count;
  document.getElementById('sbFilterCountDup').textContent = data.duplicate_count;
  document.getElementById('sbFilterCountErr').textContent = data.invalid_count;

  document.getElementById('sbReadyCommitCount').textContent = data.valid_count;

  var btnConfirm = document.getElementById('btnConfirmImport');
  btnConfirm.disabled = (data.valid_count === 0);

  // Setup Error report button
  var btnErr = document.getElementById('btnDownloadErrors');
  var errContainer = document.getElementById('sbErrorsContainer');
  if (data.errors && data.errors.length > 0) {
    btnErr.href = BULK_ENDPOINT + '?action=download_errors&batch_id=' + encodeURIComponent(data.batch_id) + '&module=' + encodeURIComponent(data.module);
    btnErr.style.display = 'inline-flex';
    errContainer.style.display = 'block';
    document.getElementById('sbErrorTotalCount').textContent = data.errors.length;

    var errTbody = document.getElementById('sbErrorsTableBody');
    errTbody.innerHTML = '';
    data.errors.forEach(function(err) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td style="padding:6px 10px; font-weight:700;">Row ' + esc(err.row) + '</td>'
        + '<td style="padding:6px 10px; font-weight:600; color:#1E293B;">' + esc(err.field) + '</td>'
        + '<td style="padding:6px 10px; color:#DC2626;">' + esc(err.problem) + '</td>'
        + '<td style="padding:6px 10px; font-family:monospace; color:#475569;">' + esc(err.value || '—') + '</td>';
      errTbody.appendChild(tr);
    });
  } else {
    btnErr.style.display = 'none';
    errContainer.style.display = 'none';
  }

  smartbulkRenderTableRows();
  document.getElementById('smartbulkPreviewSection').style.display = 'block';
}

function smartbulkRenderTableRows() {
  var tbody = document.getElementById('sbPreviewTableBody');
  tbody.innerHTML = '';

  var rows = currentBulkState.previewData.rows || [];
  var filter = currentBulkState.activeFilter;
  var q = currentBulkState.searchQuery.toLowerCase();

  rows.forEach(function(r) {
    if (filter === 'valid' && r.status !== 'valid') return;
    if (filter === 'duplicate' && r.status !== 'duplicate') return;
    if (filter === 'invalid' && r.status !== 'invalid') return;

    if (q !== '') {
      var haystack = ((r.reg_no || '') + ' ' + (r.student_name || '') + ' ' + (r.title || '') + ' ' + (r.industry || '')).toLowerCase();
      if (haystack.indexOf(q) === -1) return;
    }

    var badgeHtml = '';
    if (r.status === 'valid') {
      badgeHtml = '<span class="smartbulk-badge valid"><?= icon("check", 12) ?> Valid</span>';
    } else if (r.status === 'duplicate') {
      badgeHtml = '<span class="smartbulk-badge duplicate"><?= icon("alert-circle", 12) ?> Duplicate</span>';
    } else {
      badgeHtml = '<span class="smartbulk-badge invalid"><?= icon("x", 12) ?> Error</span>';
    }

    var issuesText = '—';
    if (r.errors && r.errors.length > 0) {
      issuesText = r.errors.map(function(e) { return esc(e.field + ': ' + e.problem); }).join('<br>');
    }

    var proofHtml = '';
    if (r.proof_source === 'zip') {
      proofHtml = '<span class="badge" style="background:#DCFCE7; color:#166534; font-size:11px; display:inline-flex; align-items:center; gap:4px;"><?= icon("paperclip", 12) ?> ' + esc(r.proof_display) + '</span>';
    } else {
      proofHtml = '<span class="badge" style="background:#FEE2E2; color:#991B1B; font-size:11px; display:inline-flex; align-items:center; gap:4px;"><?= icon("x", 12) ?> Missing in ZIP</span>';
    }

    var tr = document.createElement('tr');
    tr.innerHTML = '<td style="font-weight:700; color:#64748B;">' + esc(r.excel_row) + '</td>'
      + '<td>' + badgeHtml + '</td>'
      + '<td style="font-weight:600;">' + esc(r.reg_no) + '</td>'
      + '<td>' + esc(r.student_name) + '</td>'
      + '<td><span class="badge badge-neutral" style="font-size:11px;">' + esc(r.department) + '</span></td>'
      + '<td>' + esc(r.academic_year) + '</td>'
      + '<td>' + esc(r.title) + '</td>'
      + '<td>' + esc(r.industry) + '</td>'
      + '<td>' + esc(r.days) + ' days</td>'
      + '<td>' + proofHtml + '</td>'
      + '<td style="font-size:11.5px; color:#DC2626;">' + issuesText + '</td>';
    tbody.appendChild(tr);
  });

  if (tbody.children.length === 0) {
    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:#64748B;">No rows match the selected filter.</td></tr>';
  }
}

function smartbulkFilterTable(f) {
  currentBulkState.activeFilter = f;
  smartbulkRenderTableRows();
}

function smartbulkSearchTable(val) {
  currentBulkState.searchQuery = val;
  smartbulkRenderTableRows();
}

function smartbulkResetToUpload() {
  currentBulkState.batchId = null;
  currentBulkState.previewData = null;
  document.getElementById('smartbulkPreviewSection').style.display = 'none';
  document.getElementById('smartbulkSuccessSection').style.display = 'none';
  document.getElementById('smartbulkUploadSection').style.display = 'grid';
  smartbulkClearFile();
  smartbulkClearZip();
  smartbulkHideAlert();
}

function smartbulkCancelPreview() {
  if (!currentBulkState.batchId) {
    smartbulkResetToUpload();
    return;
  }
  var csrfInput = document.querySelector('input[name="csrf"]') || document.querySelector('input[name="csrf_token"]');
  var csrfToken = csrfInput ? csrfInput.value : '';
  var formData = new FormData();
  formData.append('action', 'cancel_preview');
  formData.append('batch_id', currentBulkState.batchId);
  formData.append('csrf', csrfToken);
  formData.append('csrf_token', csrfToken);
  formData.append('is_ajax', '1');

  fetch(BULK_ENDPOINT, { method: 'POST', body: formData })
    .then(function() { smartbulkResetToUpload(); });
}

function smartbulkConfirmImport() {
  if (!currentBulkState.batchId) return;

  var btn = document.getElementById('btnConfirmImport');
  btn.disabled = true;
  document.getElementById('sbConfirmBtnText').innerHTML = '<span class="smartbulk-spinner"></span> Importing Records...';

  var csrfInput = document.querySelector('input[name="csrf"]') || document.querySelector('input[name="csrf_token"]');
  var csrfToken = csrfInput ? csrfInput.value : '';
  var formData = new FormData();
  formData.append('action', 'confirm_import');
  formData.append('batch_id', currentBulkState.batchId);
  formData.append('csrf', csrfToken);
  formData.append('csrf_token', csrfToken);
  formData.append('is_ajax', '1');

  fetch(BULK_ENDPOINT, {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    btn.disabled = false;
    document.getElementById('sbConfirmBtnText').innerHTML = '<?= icon("check", 16) ?> Confirm &amp; Import Valid Records';

    if (!json.success) {
      smartbulkShowAlert(json.error || 'Import failed. Please try again.', 'error');
      return;
    }

    // Success screen
    document.getElementById('smartbulkPreviewSection').style.display = 'none';
    document.getElementById('sbSuccessImported').textContent = json.summary.imported_records;
    document.getElementById('sbSuccessDuplicates').textContent = json.summary.duplicate_records;
    document.getElementById('sbSuccessMessage').textContent =
      json.summary.imported_records + ' student records for ' + json.summary.module_label + ' have been successfully saved into the database and entered into the verification workflow.';
    document.getElementById('smartbulkSuccessSection').style.display = 'block';
  })
  .catch(function(err) {
    btn.disabled = false;
    document.getElementById('sbConfirmBtnText').innerHTML = '<?= icon("check", 16) ?> Confirm &amp; Import Valid Records';
    smartbulkShowAlert('Error completing import: ' + err.message, 'error');
  });
}

// ---------------------------------------------------------------------------
// Import History Modal Controller
// ---------------------------------------------------------------------------
var currentHistoryScope = 'dept';
var currentHistoryData = [];

function smartbulkOpenHistoryModal(scope) {
  var modal = document.getElementById('smartbulkHistoryModal');
  if (!modal) return;
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  var sInput = document.getElementById('sbHistorySearchInput');
  if (sInput) sInput.value = '';
  smartbulkLoadHistory(scope || currentHistoryScope);
}

function smartbulkCloseHistoryModal() {
  var modal = document.getElementById('smartbulkHistoryModal');
  if (modal) {
    modal.style.display = 'none';
  }
  document.body.style.overflow = '';
}

function smartbulkOnModalBackdropClick(e) {
  if (e.target && e.target.id === 'smartbulkHistoryModal') {
    smartbulkCloseHistoryModal();
  }
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    smartbulkCloseHistoryModal();
  }
});

function smartbulkLoadHistory(scope) {
  if (scope) currentHistoryScope = scope;
  var modal = document.getElementById('smartbulkHistoryModal');
  if (modal && modal.style.display === 'none') {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  var body = document.getElementById('sbHistoryModalBody');
  var pills = document.getElementById('sbHistoryScopePills');
  var fCount = document.getElementById('sbHistoryFooterCount');

  if (body) {
    body.innerHTML = '<div style="padding:48px 20px; text-align:center;">'
      + '<span class="smartbulk-spinner" style="border-color:rgba(255,79,1,0.25); border-top-color:#FF4F01; width:28px; height:28px; margin-bottom:12px;"></span>'
      + '<p style="color:#64748B; font-size:13.5px; margin:0;">Loading import history &amp; audit records...</p>'
      + '</div>';
  }

  var mod = document.getElementById('smartbulkRoot').getAttribute('data-module');
  var url = BULK_ENDPOINT + '?action=history&module=' + encodeURIComponent(mod) + '&scope=' + encodeURIComponent(currentHistoryScope);

  fetch(url)
    .then(function(res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .then(function(json) {
      if (!json.success) throw new Error(json.error || 'Failed to fetch history');

      currentHistoryData = json.history || [];
      var userDept = json.user_department || '';
      var deptCount = json.dept_count || 0;
      var allCount = json.all_count || 0;

      if (pills) {
        if (userDept) {
          pills.innerHTML = '<button type="button" class="sb-scope-pill ' + (currentHistoryScope === 'dept' ? 'active' : '') + '" onclick="smartbulkLoadHistory(\'dept\')">'
            + 'My Dept (' + esc(userDept) + ') <span class="sb-count-badge">' + deptCount + '</span>'
            + '</button>'
            + '<button type="button" class="sb-scope-pill ' + (currentHistoryScope === 'all' ? 'active' : '') + '" onclick="smartbulkLoadHistory(\'all\')">'
            + 'All Campus Batches <span class="sb-count-badge">' + allCount + '</span>'
            + '</button>';
        } else {
          pills.innerHTML = '<span style="font-size:12.5px; font-weight:600; color:#475569;">All Campus Batches (' + allCount + ')</span>';
        }
      }

      smartbulkRenderHistoryContent(currentHistoryData, json);
    })
    .catch(function(err) {
      if (body) {
        body.innerHTML = '<div style="padding:40px 20px; text-align:center;">'
          + '<div style="width:48px; height:48px; border-radius:50%; background:#FEF2F2; color:#EF4444; display:inline-flex; align-items:center; justify-content:center; margin-bottom:12px;">'
          + '<?= icon("alert-triangle", 24) ?>'
          + '</div>'
          + '<h4 style="font-size:15px; font-weight:700; color:#991B1B; margin:0 0 6px;">Failed to Load History</h4>'
          + '<p style="font-size:13px; color:#64748B; margin:0 0 16px;">' + esc(err.message || 'Network error occurred') + '</p>'
          + '<button type="button" class="btn-smartbulk-secondary" onclick="smartbulkLoadHistory(currentHistoryScope)">Try Again</button>'
          + '</div>';
      }
    });
}

function smartbulkRenderHistoryContent(records, meta) {
  var body = document.getElementById('sbHistoryModalBody');
  var fCount = document.getElementById('sbHistoryFooterCount');

  if (!records || records.length === 0) {
    if (fCount) fCount.innerText = '0 batches found';
    var isDeptScope = (meta && meta.active_scope === 'dept');
    var allCount = (meta && meta.all_count) || 0;
    var userDept = (meta && meta.user_department) || '';

    var emptyHtml = '<div style="padding:48px 24px; text-align:center; max-width:540px; margin:0 auto;">'
      + '<div style="width:64px; height:64px; border-radius:16px; background:#F1F5F9; color:#94A3B8; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">'
      + '<?= icon("file-stack", 32) ?>'
      + '</div>'
      + '<h4 style="font-size:17px; font-weight:700; color:#131D3B; margin:0 0 8px;">No Import Records Found</h4>';

    if (isDeptScope && allCount > 0) {
      emptyHtml += '<p style="font-size:13.5px; color:#64748B; margin:0 0 20px; line-height:1.6;">'
        + 'No spreadsheets have been uploaded for <strong>' + esc(userDept) + '</strong> yet in this module. However, there are <strong>' + allCount + '</strong> completed batch(es) across other departments in the institution.'
        + '</p>'
        + '<div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap;">'
        + '<button type="button" class="btn-smartbulk" onclick="smartbulkLoadHistory(\'all\')" style="font-size:13px; padding:9px 18px;">'
        + '<?= icon("eye", 15) ?> View All Campus Batches (' + allCount + ')'
        + '</button>'
        + '<button type="button" class="btn-smartbulk-secondary" onclick="smartbulkCloseHistoryModal();" style="font-size:13px; padding:9px 18px;">'
        + '<?= icon("upload", 15) ?> Upload First Batch'
        + '</button>'
        + '</div>';
    } else {
      emptyHtml += '<p style="font-size:13.5px; color:#64748B; margin:0 0 20px; line-height:1.6;">'
        + 'No bulk spreadsheets have been imported for this module yet. When you upload and commit an Excel workbook with companion ZIP certificates, the audit logs, verified student counts, and downloadable error spreadsheets will appear here.'
        + '</p>'
        + '<button type="button" class="btn-smartbulk" onclick="smartbulkCloseHistoryModal();" style="font-size:13px; padding:9px 20px;">'
        + '<?= icon("upload", 15) ?> Start New Bulk Upload'
        + '</button>';
    }
    emptyHtml += '</div>';
    body.innerHTML = emptyHtml;
    return;
  }

  if (fCount) fCount.innerText = records.length + ' batch record(s) loaded';

  var html = '<div class="smartbulk-table-wrap" style="border:1px solid #E2E8F0; border-radius:10px; overflow:hidden;">'
    + '<table class="smartbulk-table" style="margin:0;">'
    + '<thead>'
    + '<tr>'
    + '<th style="min-width:140px;">Date &amp; Time</th>'
    + '<th style="min-width:160px;">Batch ID</th>'
    + '<th style="min-width:180px;">Spreadsheet File</th>'
    + '<th style="min-width:100px;">Department</th>'
    + '<th style="min-width:130px;">Uploaded By</th>'
    + '<th style="text-align:center;">Detected</th>'
    + '<th style="text-align:center;">Imported</th>'
    + '<th style="text-align:center;">Duplicates</th>'
    + '<th style="text-align:center;">Failed</th>'
    + '<th style="text-align:center;">Status</th>'
    + '<th style="text-align:right; min-width:170px;">Actions</th>'
    + '</tr>'
    + '</thead>'
    + '<tbody>';

  records.forEach(function(h) {
    var searchText = [h.batch_id, h.file_name, h.department, h.uploader_name, h.status, h.academic_year].join(' ').toLowerCase();
    var errs = [];
    try {
      if (h.errors_json) errs = JSON.parse(h.errors_json);
    } catch (e) { errs = []; }

    var hasErrors = (parseInt(h.failed_rows, 10) > 0 || parseInt(h.duplicate_records, 10) > 0 || errs.length > 0);

    var errDownloadBtn = '';
    if (hasErrors) {
      errDownloadBtn = '<a class="btn-smartbulk-secondary" style="font-size:11.5px; padding:4px 9px; color:#B91C1C; border-color:#FECACA; background:#FEF2F2;" href="' + BULK_ENDPOINT + '?action=download_errors&batch_id=' + encodeURIComponent(h.batch_id) + '&module=' + encodeURIComponent(h.module) + '" download title="Download Error Spreadsheet">'
        + '<?= icon("download", 12) ?> Errors (.xlsx)'
        + '</a>';
    }

    var detailsBtn = '<button type="button" class="btn-smartbulk-secondary" id="sbDetailsBtn_' + esc(h.batch_id) + '" style="font-size:11.5px; padding:4px 9px;" onclick="smartbulkToggleRowDetails(\'' + esc(h.batch_id) + '\')">'
      + '<?= icon("info", 12) ?> Details'
      + '</button>';

    var statusBadgeClass = 'valid';
    if (parseInt(h.failed_rows, 10) > 0) statusBadgeClass = 'invalid';
    else if (parseInt(h.duplicate_records, 10) > 0) statusBadgeClass = 'duplicate';

    html += '<tr class="sb-history-data-row" data-batch-id="' + esc(h.batch_id) + '" data-search="' + esc(searchText) + '">'
      + '<td style="white-space:nowrap; font-size:12px; color:#475569;">'
      + '<div style="font-weight:600; color:#1E293B;">' + smartbulkFormatDate(h.created_at) + '</div>'
      + '<div style="font-size:11px; color:#94A3B8;">AY: ' + esc(h.academic_year || '—') + '</div>'
      + '</td>'
      + '<td>'
      + '<span class="sb-batch-badge" title="Click to copy Batch ID" onclick="smartbulkCopyBatchId(\'' + esc(h.batch_id) + '\', this)">'
      + esc(h.batch_id)
      + '</span>'
      + '</td>'
      + '<td>'
      + '<div style="display:flex; align-items:center; gap:6px; font-weight:600; color:#1E293B; font-size:12.5px;">'
      + '<span style="color:#166534;"><?= icon("file-text", 15) ?></span>'
      + '<span>' + esc(h.file_name) + '</span>'
      + '</div>'
      + '</td>'
      + '<td>'
      + '<span class="badge badge-neutral" style="font-size:11px;">' + esc(h.department || 'All') + '</span>'
      + '</td>'
      + '<td style="font-size:12px;">'
      + '<div style="font-weight:600; color:#1E293B;">' + esc(h.uploader_name || 'User #' + h.uploaded_by) + '</div>'
      + (h.uploader_role ? '<div style="font-size:11px; color:#64748B;">' + esc(h.uploader_role) + '</div>' : '')
      + '</td>'
      + '<td style="text-align:center; font-weight:600; color:#334155;">' + esc(h.total_rows) + '</td>'
      + '<td style="text-align:center;"><span style="color:#166534; font-weight:700; background:#DCFCE7; padding:2px 8px; border-radius:12px; font-size:11.5px;">✓ ' + esc(h.imported_records) + '</span></td>'
      + '<td style="text-align:center;">'
      + (parseInt(h.duplicate_records, 10) > 0 ? '<span style="color:#C2410C; font-weight:600; background:#FFEDD5; padding:2px 8px; border-radius:12px; font-size:11.5px;">' + esc(h.duplicate_records) + '</span>' : '<span style="color:#94A3B8;">0</span>')
      + '</td>'
      + '<td style="text-align:center;">'
      + (parseInt(h.failed_rows, 10) > 0 ? '<span style="color:#B91C1C; font-weight:700; background:#FEE2E2; padding:2px 8px; border-radius:12px; font-size:11.5px;">' + esc(h.failed_rows) + '</span>' : '<span style="color:#94A3B8;">0</span>')
      + '</td>'
      + '<td style="text-align:center;"><span class="smartbulk-badge ' + statusBadgeClass + '">' + esc(h.status || 'Completed') + '</span></td>'
      + '<td style="text-align:right; white-space:nowrap;">'
      + '<div style="display:inline-flex; gap:6px; align-items:center;">'
      + errDownloadBtn
      + detailsBtn
      + '</div>'
      + '</td>'
      + '</tr>';

    var errSnippets = '';
    if (errs.length > 0) {
      errSnippets = '<div style="margin-top:10px; background:#FFFFFF; border:1px solid #FECACA; border-radius:8px; padding:10px 14px;">'
        + '<div style="font-size:12px; font-weight:700; color:#991B1B; margin-bottom:6px; display:flex; align-items:center; gap:6px;">'
        + '<?= icon("alert-triangle", 13) ?> Logged Issues &amp; Missing Proofs (' + errs.length + '):'
        + '</div>'
        + '<div style="max-height:140px; overflow-y:auto; font-size:12px; line-height:1.6; color:#475569;">';
      errs.slice(0, 8).forEach(function(er) {
        errSnippets += '<div style="margin-bottom:4px; padding-left:12px; position:relative;">'
          + '<span style="position:absolute; left:0; color:#EF4444;">&bull;</span>'
          + '<strong>Row ' + esc(er.row) + '</strong> (' + esc(er.field) + '): ' + esc(er.problem)
          + (er.value ? ' <code style="background:#F1F5F9; padding:1px 4px; border-radius:3px; font-size:11px;">' + esc(er.value) + '</code>' : '')
          + '</div>';
      });
      if (errs.length > 8) {
        errSnippets += '<div style="color:#64748B; font-style:italic; margin-top:4px;">... and ' + (errs.length - 8) + ' more issues recorded. Download full Excel report above.</div>';
      }
      errSnippets += '</div></div>';
    } else {
      errSnippets = '<div style="margin-top:8px; font-size:12px; color:#166534; font-weight:600;">'
        + '<?= icon("check", 13) ?> Clean batch: All records passed data validation, student matching, and compulsory proof verification.'
        + '</div>';
    }

    html += '<tr id="sbDetails_' + esc(h.batch_id) + '" style="display:none; background:#F8FAFC;">'
      + '<td colspan="11" style="padding:14px 20px; border-top:none; border-bottom:1px solid #E2E8F0;">'
      + '<div class="sb-history-details-row">'
      + '<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">'
      + '<div>'
      + '<span style="font-weight:700; color:#131D3B;">Batch Summary:</span> '
      + '<span>' + esc(h.imported_records) + ' of ' + esc(h.total_rows) + ' student records committed to database.</span> '
      + (parseInt(h.duplicate_records, 10) > 0 ? '<span style="color:#C2410C;">(' + esc(h.duplicate_records) + ' duplicates skipped)</span> ' : '')
      + (parseInt(h.failed_rows, 10) > 0 ? '<span style="color:#B91C1C;">(' + esc(h.failed_rows) + ' rejected)</span>' : '')
      + '</div>'
      + '<div style="display:flex; gap:8px;">'
      + (hasErrors ? '<a class="btn-smartbulk-secondary" style="font-size:11.5px; padding:3px 10px;" href="' + BULK_ENDPOINT + '?action=download_errors&batch_id=' + encodeURIComponent(h.batch_id) + '&module=' + encodeURIComponent(h.module) + '" download><?= icon("download", 12) ?> Download Error Spreadsheet (.xlsx)</a>' : '')
      + '<a class="btn-smartbulk-secondary" style="font-size:11.5px; padding:3px 10px;" href="<?= e(url("approvals.php?type=" . urlencode($moduleKey))) ?>">View in Approvals</a>'
      + '</div>'
      + '</div>'
      + errSnippets
      + '</div>'
      + '</td>'
      + '</tr>';
  });

  html += '</tbody></table></div>';
  body.innerHTML = html;
}

function smartbulkFilterHistoryTable() {
  var q = (document.getElementById('sbHistorySearchInput').value || '').trim().toLowerCase();
  var rows = document.querySelectorAll('.sb-history-data-row');
  var visibleCount = 0;
  rows.forEach(function(r) {
    var text = r.getAttribute('data-search') || '';
    var batchId = r.getAttribute('data-batch-id') || '';
    var detailRow = document.getElementById('sbDetails_' + batchId);
    if (!q || text.indexOf(q) !== -1) {
      r.style.display = '';
      visibleCount++;
    } else {
      r.style.display = 'none';
      if (detailRow) detailRow.style.display = 'none';
    }
  });
  var fCount = document.getElementById('sbHistoryFooterCount');
  if (fCount) fCount.innerText = visibleCount + ' batch record(s) matching search';
}

function smartbulkToggleRowDetails(batchId) {
  var row = document.getElementById('sbDetails_' + batchId);
  var btn = document.getElementById('sbDetailsBtn_' + batchId);
  if (!row) return;
  if (row.style.display === 'none' || !row.style.display) {
    row.style.display = 'table-row';
    if (btn) btn.innerHTML = '<?= icon("chevron-up", 12) ?> Hide';
  } else {
    row.style.display = 'none';
    if (btn) btn.innerHTML = '<?= icon("info", 12) ?> Details';
  }
}

function smartbulkCopyBatchId(batchId, el) {
  if (!navigator.clipboard) return;
  navigator.clipboard.writeText(batchId).then(function() {
    var orig = el.innerText;
    el.innerText = 'Copied!';
    setTimeout(function() { el.innerText = orig; }, 1500);
  }).catch(function() {});
}

function smartbulkFormatDate(dtStr) {
  if (!dtStr) return '—';
  try {
    var d = new Date(dtStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dtStr;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
      + ', ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
  } catch (e) {
    return dtStr;
  }
}

function esc(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Check if PHP passed in an active session preview to display immediately
<?php if ($sessionPreview): ?>
document.addEventListener('DOMContentLoaded', function() {
  smartbulkRenderPreview(<?= json_encode($sessionPreview) ?>);
});
<?php endif; ?>
</script>
