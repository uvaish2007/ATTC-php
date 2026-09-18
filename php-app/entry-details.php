<?php
/**
 * Dedicated Dual View of Entries with Request-for-Edit Governance.
 * BUG-WF-12: Admin & Dean Dual View with structured Request-for-Edit (no direct silent override).
 *
 * Displays submitted record data on the left and attached proof document on the right side-by-side.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

$user = require_login();

$type = trim((string) input('type'));
$id   = (int) input('id');

if ($type === '' || $id <= 0) {
    flash('error', 'Invalid entry parameters.');
    redirect('/approvals.php');
}

$record = record_find($type, $id);
if (!$record) {
    flash('error', 'Record not found or has been removed.');
    redirect('/approvals.php');
}

// Access Control: Admin and Dean can view any record.
// HoD and Coordinator can view records of their own department.
// Faculty can view records they submitted.
$canView = false;
if (in_array($user['role'], ['Admin', 'Dean', 'Principal', 'Director'], true)) {
    $canView = true;
} elseif (in_array($user['role'], ['HoD', 'Coordinator'], true)) {
    $canView = department_names_match($record['department'] ?? '', $user['department'] ?? '');
} elseif ($user['role'] === 'Faculty') {
    $canView = ((int)($record['created_by'] ?? 0) === (int)$user['id']);
}

if (!$canView) {
    flash('error', 'Access Denied: You are not authorized to view this entry.');
    redirect('/approvals.php');
}

$canGovern = in_array($user['role'], ['Admin', 'Dean'], true);

// Handle POST Governance Actions from Dual View
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$canGovern) {
        flash('error', 'Unauthorized: Only Dean and Administrator can perform governance actions on entry details.');
        redirect("/entry-details.php?type={$type}&id={$id}");
    }

    $govAction = (string) input('gov_action');

    if ($govAction === 'request_edit') {
        $reason = trim((string) input('reason'));
        $specificField = trim((string) input('specific_field'));
        $currentVal = trim((string) input('current_value'));
        $requestedVal = trim((string) input('requested_value'));

        [$ok, $msg] = record_request_edit_by_dean($type, $id, $user, $reason, $specificField, $currentVal, $requestedVal);
        flash($ok ? 'success' : 'error', $msg);
        redirect("/entry-details.php?type={$type}&id={$id}");
    } elseif ($govAction === 'approve') {
        $remark = trim((string) input('review_remark'));
        [$ok, $msg] = record_review($type, $id, 'approve', $remark, (int)$user['id'], null, $user['role'], $record['academic_year'] ?? null);
        flash($ok ? 'success' : 'error', $msg);
        redirect("/entry-details.php?type={$type}&id={$id}");
    } elseif ($govAction === 'reject') {
        $remark = trim((string) input('review_remark'));
        if ($remark === '') {
            flash('error', 'Rejection reason is required.');
            redirect("/entry-details.php?type={$type}&id={$id}");
        }
        [$ok, $msg] = record_review($type, $id, 'reject', $remark, (int)$user['id'], null, $user['role'], $record['academic_year'] ?? null);
        flash($ok ? 'success' : 'error', $msg);
        redirect("/entry-details.php?type={$type}&id={$id}");
    }
}

// Fetch linked edit requests / governance history
$editReqStmt = db()->prepare("SELECT * FROM edit_requests WHERE record_id = ? AND record_type = ? ORDER BY id DESC");
$editReqStmt->execute([$id, $type]);
$linkedRequests = $editReqStmt->fetchAll(PDO::FETCH_ASSOC);
$latestRequest = $linkedRequests[0] ?? null;

// Display Attributes
$attributes = record_display_attributes($type, $record);

// Attached proof resolution
$proofFile = !empty($record['proof_file']) ? $record['proof_file'] : null;
$docUrl    = $record['document_link'] ?? $record['certificate_link'] ?? $record['report_link'] ?? $record['proceedings_link'] ?? null;
$proofUrl  = $proofFile ? proof_url($proofFile) : ($docUrl ?: null);

$pageTitle = 'Entry Details · ' . ($record['_title'] ?? 'Record #' . $id);
require __DIR__ . '/inc/header.php';
?>

<div style="max-width:1440px; margin:0 auto; padding:10px 0 30px;">
  <!-- Breadcrumb & Top Bar -->
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
    <div>
      <div style="display:flex; align-items:center; gap:8px; font-size:12.5px; color:#64748B; margin-bottom:4px;">
        <a href="<?= e(url('dashboard.php')) ?>" style="color:#64748B; text-decoration:none;">Dashboard</a>
        <span>&rsaquo;</span>
        <a href="<?= e(url('approvals.php')) ?>" style="color:#64748B; text-decoration:none;">Review &amp; Approvals</a>
        <span>&rsaquo;</span>
        <span style="color:#0F172A; font-weight:600;">Entry Details #<?= $id ?></span>
      </div>
      <h1 style="font-size:20px; font-weight:800; color:#0F172A; margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <span style="max-width:700px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e($record['_title']) ?></span>
        <span class="badge badge-info" style="font-size:12px;"><?= e($record['_type_label']) ?></span>
      </h1>
    </div>

    <div style="display:flex; align-items:center; gap:8px;">
      <a href="<?= e(url('approvals.php')) ?>" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:5px; height:34px;">
        <?= icon('arrow-left', 14) ?> Back to Approvals
      </a>
      <?php if ($canGovern && $record['status'] !== 'Unlocked for Edit'): ?>
        <a href="#governance-panel" class="btn btn-sm" style="background:#F59E0B; color:#fff; font-weight:700; display:inline-flex; align-items:center; gap:5px; height:34px;">
          <?= icon('edit', 14) ?> Request Edit
        </a>
      <?php endif; ?>
      <?php if ($proofUrl): ?>
        <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:5px; height:34px;">
          <?= icon('external-link', 14) ?> Open Proof in Tab
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Status Bar / Quick Metadata Pill Banner -->
  <div class="card" style="margin-bottom:20px; padding:12px 18px; background:#fff; border:1px solid #E2E8F0; border-radius:10px;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-size:13px;">
        <div><span style="color:#64748B;">Record ID:</span> <strong style="color:#0F172A; font-family:monospace;">#<?= $id ?></strong></div>
        <div><span style="color:#64748B;">Department:</span> <strong style="color:#1E3A8A;"><?= e($record['department'] ?? 'N/A') ?></strong></div>
        <div><span style="color:#64748B;">Academic Year:</span> <strong style="color:#0F172A;"><?= e($record['academic_year'] ?? '2025-26') ?></strong></div>
        <div><span style="color:#64748B;">Submitted:</span> <strong style="color:#0F172A;"><?= e(date('d M Y, h:i A', strtotime($record['created_at']))) ?></strong></div>
      </div>

      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:12px; color:#64748B; font-weight:600;">Status:</span>
        <?php if ($record['status'] === 'Unlocked for Edit'): ?>
          <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:5px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
            <?= icon('check-circle', 14) ?> Approved by Dean &middot; Unlocked for Edit
          </span>
        <?php elseif ($record['status'] === 'Edit Requested'): ?>
          <span class="badge" style="background:#FEF3C7; color:#B45309; border:1px solid #FCD34D; font-size:12px; font-weight:700; padding:5px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
            <?= icon('clock', 14) ?> Edit Requested (Pending Dean)
          </span>
        <?php elseif ($record['status'] === 'Resubmitted'): ?>
          <span class="badge" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; font-size:12px; font-weight:700; padding:5px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
            <?= icon('check', 14) ?> Resubmitted (HoD Reviewing)
          </span>
        <?php else: ?>
          <span class="badge badge-<?= status_class($record['status']) ?>" style="font-size:12px; font-weight:700; padding:5px 12px;">
            <?= e($record['status']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- DUAL VIEW CONTAINER (Split Grid) -->
  <div style="display:grid; grid-template-columns: minmax(420px, 1fr) minmax(480px, 1.1fr); gap:20px; align-items:start;">
    
    <!-- =========================================================================
         LEFT COLUMN: ENTRY DETAILS & GOVERNANCE ACTIONS
         ========================================================================= -->
    <div style="display:flex; flex-direction:column; gap:20px;">

      <!-- Card 1: Submitted Metadata & Attributes -->
      <div class="card" style="padding:0; overflow:hidden; border:1px solid #E2E8F0; border-radius:10px; background:#fff;">
        <div class="card-head" style="padding:14px 18px; background:#F8FAFC; border-bottom:1px solid #E2E8F0; display:flex; align-items:center; justify-content:space-between;">
          <div style="font-size:14px; font-weight:700; color:#0F172A; display:flex; align-items:center; gap:6px;">
            <?= icon('file-text', 16) ?> Submitted Entry Attributes
          </div>
          <span class="badge badge-neutral" style="font-size:11px;"><?= count($attributes) ?> fields</span>
        </div>

        <div style="padding:0;">
          <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <tbody>
              <?php foreach ($attributes as $idx => $attr): ?>
                <tr style="border-bottom:1px solid #F1F5F9; <?= $idx % 2 === 1 ? 'background:#FAFAFC;' : '' ?>">
                  <td style="padding:10px 16px; font-weight:600; color:#475569; width:38%; vertical-align:top;">
                    <?= e($attr['label']) ?>
                  </td>
                  <td style="padding:10px 16px; color:#0F172A; vertical-align:top; word-break:break-word;">
                    <?php
                      $val = $attr['value'];
                      if ($val === '') {
                          echo '<span style="color:#94A3B8;">—</span>';
                      } elseif (filter_var($val, FILTER_VALIDATE_URL) || preg_match('/^https?:\/\//i', $val)) {
                          echo '<a href="' . e($val) . '" target="_blank" rel="noopener" style="color:#2563EB; text-decoration:underline; display:inline-flex; align-items:center; gap:4px;">' . e($val) . ' ' . icon('external-link', 12) . '</a>';
                      } elseif (preg_match('/^10\.\d{4,9}\/[-._;()\/:A-Z0-9]+$/i', $val)) {
                          echo '<a href="https://doi.org/' . e($val) . '" target="_blank" rel="noopener" style="color:#2563EB; text-decoration:underline; display:inline-flex; align-items:center; gap:4px;">' . e($val) . ' ' . icon('external-link', 12) . '</a>';
                      } else {
                          echo e($val);
                      }
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Card 2: Governance & Verification History -->
      <div class="card" style="padding:16px 18px; border:1px solid #E2E8F0; border-radius:10px; background:#fff;">
        <div style="font-size:14px; font-weight:700; color:#0F172A; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
          <?= icon('clock', 15) ?> Governance &amp; Verification Audit
        </div>

        <div style="display:flex; flex-direction:column; gap:10px; font-size:12.5px;">
          <div style="padding:10px 12px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:2px;">
              <strong style="color:#334155;">Original Submission</strong>
              <span style="color:#64748B;"><?= e(date('d M Y, h:i A', strtotime($record['created_at']))) ?></span>
            </div>
            <div style="color:#64748B;">Author: <strong style="color:#0F172A;"><?= e($record['faculty_name'] ?? 'Faculty') ?></strong> &middot; Dept: <?= e($record['department'] ?? 'General') ?></div>
          </div>

          <?php if (!empty($record['review_remark'])): ?>
            <div style="padding:10px 12px; background:#EFF6FF; border:1px solid #BFDBFE; border-left:4px solid #1D4ED8; border-radius:6px;">
              <strong style="color:#1E40AF; display:block; margin-bottom:3px;">Reviewer Remark / Instructions:</strong>
              <div style="color:#1E3A8A;"><?= nl2br(e($record['review_remark'])) ?></div>
            </div>
          <?php endif; ?>

          <?php if (!empty($linkedRequests)): ?>
            <div style="margin-top:6px;">
              <div style="font-size:12px; font-weight:700; color:#475569; margin-bottom:6px;">Linked Edit Requests:</div>
              <?php foreach ($linkedRequests as $er): ?>
                <div style="padding:8px 12px; background:#FEF3C7; border:1px solid #FCD34D; border-radius:6px; margin-bottom:6px; font-size:12px;">
                  <div style="display:flex; justify-content:space-between; font-weight:600; color:#92400E;">
                    <span>ER-#<?= $er['id'] ?> (<?= e($er['status']) ?>)</span>
                    <span><?= e(date('d M Y', strtotime($er['created_at']))) ?></span>
                  </div>
                  <div style="color:#78350F; margin-top:2px;"><strong>Reason:</strong> <?= e($er['reason']) ?></div>
                  <?php if (!empty($er['specific_field'])): ?>
                    <div style="color:#78350F;"><strong>Target Field:</strong> <?= e($er['specific_field']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($er['admin_comments'])): ?>
                    <div style="color:#1E40AF; margin-top:2px;"><strong>Dean Decision:</strong> <?= e($er['admin_comments']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Card 3: Governance Action Controls (Admin & Dean) -->
      <?php if ($canGovern): ?>
        <div id="governance-panel" class="card" style="padding:18px; border:2px solid #E2E8F0; border-radius:10px; background:#FAFAFC;">
          <div style="font-size:15px; font-weight:800; color:#0F172A; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
            <?= icon('shield', 16) ?> Governance Actions (Admin &amp; Dean)
          </div>
          <div style="font-size:12px; color:#64748B; margin-bottom:16px;">
            Review proof alongside details. Return for correction via structured request-for-edit governance without direct silent overrides.
          </div>

          <?php if ($record['status'] === 'Unlocked for Edit'): ?>
            <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-left:4px solid #059669; padding:12px 14px; border-radius:8px; font-size:13px; color:#065F46;">
              <strong>Record Unlocked:</strong> Dean/Admin has requested an edit on this entry. It is currently unlocked for the Department Coordinator to correct and resubmit. Direct modification is restricted to the Coordinator.
            </div>
          <?php else: ?>
            <!-- REQUEST EDIT WORKFLOW SECTION -->
            <div style="background:#fff; border:1px solid #E2E8F0; border-radius:8px; padding:16px; margin-bottom:16px;">
              <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <h4 style="margin:0; font-size:13.5px; font-weight:700; color:#B45309; display:flex; align-items:center; gap:6px;">
                  <?= icon('edit', 14) ?> Request Edit (Return to Coordinator)
                </h4>
                <span class="badge" style="background:#FEF3C7; color:#B45309; font-size:10px; border:1px solid #FCD34D;">No Silent Override</span>
              </div>
              <p style="font-size:12px; color:#64748B; margin:0 0 12px;">
                Identified an issue in this record? Request an edit to unlock it for the Coordinator without forcing direct approval. All actions are fully logged.
              </p>

              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="gov_action" value="request_edit">

                <div class="field" style="margin-bottom:10px;">
                  <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">
                    Target Field to Correct (Optional)
                  </label>
                  <input type="text" name="specific_field" class="input" placeholder="e.g. DOI, Journal Name, Authors, Volume/Issue" style="height:34px; font-size:12.5px;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                  <div class="field">
                    <label style="font-size:11.5px; font-weight:600; color:#64748B; display:block; margin-bottom:3px;">Current Value</label>
                    <input type="text" name="current_value" class="input" placeholder="Value shown in record" style="height:32px; font-size:12px;">
                  </div>
                  <div class="field">
                    <label style="font-size:11.5px; font-weight:600; color:#64748B; display:block; margin-bottom:3px;">Requested Value</label>
                    <input type="text" name="requested_value" class="input" placeholder="Expected corrected value" style="height:32px; font-size:12px;">
                  </div>
                </div>

                <div class="field" style="margin-bottom:14px;">
                  <label style="font-size:12px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">
                    Instructions / Issue Description for Coordinator <span style="color:#DC2626;">*</span>
                  </label>
                  <textarea name="reason" class="input" rows="3" required placeholder="Explain clearly what needs correction (e.g. DOI link returns 404, author name misspelling, attach official proceedings)…" style="font-size:12.5px;"></textarea>
                </div>

                <button type="submit" class="btn btn-sm" style="background:#F59E0B; color:#fff; font-weight:700; height:34px; display:inline-flex; align-items:center; gap:5px;" onclick="return confirm('Unlock this record for Coordinator correction and notify department?');">
                  <?= icon('send', 13) ?> Submit Request for Edit
                </button>
              </form>
            </div>

            <!-- APPROVE / REJECT DIRECT ACTIONS (For Submitted records) -->
            <?php if ($record['status'] === 'Submitted'): ?>
              <div style="display:flex; align-items:center; gap:10px; padding-top:10px; border-top:1px dashed #E2E8F0;">
                <form method="post" style="display:inline-block;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="gov_action" value="approve">
                  <button type="submit" class="btn btn-sm" style="background:#047857; color:#fff; font-weight:700; height:34px; display:inline-flex; align-items:center; gap:4px;" onclick="return confirm('Confirm approval of this record into database?');">
                    <?= icon('check-circle', 14) ?> Approve Record
                  </button>
                </form>

                <form method="post" style="display:inline-block;" onsubmit="var r = prompt('Please enter rejection reason:'); if(!r) return false; this.review_remark.value = r; return true;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="gov_action" value="reject">
                  <input type="hidden" name="review_remark" value="">
                  <button type="submit" class="btn btn-sm" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; height:34px; display:inline-flex; align-items:center; gap:4px;">
                    <?= icon('x-circle', 14) ?> Reject
                  </button>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- =========================================================================
         RIGHT COLUMN: LIVE PROOF ATTACHMENT VIEWER
         ========================================================================= -->
    <div style="position:sticky; top:20px;">
      <div class="card" style="padding:0; overflow:hidden; border:1px solid #CBD5E1; border-radius:10px; background:#fff; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <div class="card-head" style="padding:14px 18px; background:#F8FAFC; border-bottom:1px solid #E2E8F0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
          <div style="display:flex; align-items:center; gap:8px; min-width:0;">
            <?= icon('paperclip', 16) ?>
            <strong style="font-size:14px; color:#0F172A; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:280px;">
              <?= e($proofFile ?: 'Proof Document Attachment') ?>
            </strong>
          </div>

          <div style="display:flex; align-items:center; gap:6px;">
            <?php if ($proofUrl): ?>
              <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="height:30px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px;">
                <?= icon('external-link', 12) ?> Open Tab
              </a>
              <a href="<?= e($proofUrl . (strpos($proofUrl, '?') !== false ? '&download=1' : '?download=1')) ?>" class="btn btn-outline btn-sm" style="height:30px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px;">
                <?= icon('download', 12) ?> Download
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div style="background:#F1F5F9; min-height:76vh; position:relative; display:flex; flex-direction:column;">
          <?php if ($proofUrl): ?>
            <iframe src="<?= e($proofUrl) ?>" style="width:100%; height:78vh; border:none; display:block; background:#fff;" loading="lazy"></iframe>
          <?php else: ?>
            <div style="padding:50px 30px; text-align:center; color:#64748B; margin:auto;">
              <div style="font-size:36px; margin-bottom:12px; opacity:0.6;">📄</div>
              <div style="font-size:15px; font-weight:700; color:#334155; margin-bottom:6px;">No Proof Document Attached</div>
              <div style="font-size:13px; max-width:320px; margin:0 auto 16px;">This entry was submitted without a direct PDF upload file. Check external reference links below.</div>
              <?php if (!empty($record['document_link']) || !empty($record['doi']) || !empty($record['journal_link'])): ?>
                <div style="display:flex; flex-direction:column; gap:8px; max-width:320px; margin:0 auto; text-align:left; font-size:12.5px;">
                  <?php if (!empty($record['doi'])): ?>
                    <a href="<?= e(strpos($record['doi'], 'http') === 0 ? $record['doi'] : 'https://doi.org/' . $record['doi']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                      <?= icon('external-link', 12) ?> DOI Reference Link
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($record['document_link'])): ?>
                    <a href="<?= e($record['document_link']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                      <?= icon('external-link', 12) ?> External Document Link
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div style="padding:8px 14px; background:#F8FAFC; border-top:1px solid #E2E8F0; font-size:11.5px; color:#64748B; display:flex; align-items:center; justify-content:space-between;">
            <span>Proof Document Viewer &middot; ATTS IQAC Portal</span>
            <?php if ($proofUrl): ?>
              <span>Cross-check metadata against official certificate / proof</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
