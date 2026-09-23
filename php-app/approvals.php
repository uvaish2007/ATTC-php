<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/EditRequest.php';
require_once __DIR__ . '/models/Target.php';

$user = require_role(['Admin', 'HoD', 'Dean', 'Coordinator']);

// A Coordinator or HoD may only review their own department; Admin/Dean may review any.
$scopeDept = in_array($user['role'], ['HoD', 'Coordinator'], true) ? ($user['department'] ?? null) : null;

$activeYear = active_academic_year();
$isHod = $user['role'] === 'HoD';
$canProcess = in_array($user['role'], ['Admin', 'Dean'], true);
journal_process_approval_expiry();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (input('process_action') !== '') {
        if (!$canProcess) {
            flash('error', 'Unauthorized: Only Dean and Administrator can process edit requests.');
            redirect('/approvals.php?tab=requests');
        }
        $pAction       = (string) input('process_action');
        $ticketId      = (int) input('ticket_id');
        $adminComments = trim((string) input('admin_comments'));

        [$ok, $msg] = edit_request_process($ticketId, $pAction, $adminComments, (int) $user['id'], (string) $user['role']);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php?tab=requests');
    }

    if ($user['role'] !== 'Admin' && academic_year_is_locked($activeYear)) {
        flash('error', "Academic year {$activeYear} cycle is locked by Administrator. Workflow actions are frozen for all roles.");
        redirect('/approvals.php');
    }

    $action = (string) input('review_action');

    // SECURITY: HoD must never be able to approve or reject records directly!
    if ($user['role'] === 'HoD' && in_array($action, ['approve', 'reject', 'approve_all'], true)) {
        flash('error', 'HoD is a reviewer only and cannot approve or reject submitted records. To request changes, use Request Edit to Dean.');
        redirect('/approvals.php');
    }

    if ($action === 'create_edit_request' || $action === 'request_edit') {
        if (!in_array($user['role'], ['HoD', 'Admin'], true)) {
            flash('error', 'Only HoD or Admin can submit an Edit Request.');
            redirect('/approvals.php');
        }
        $fromTab = (string) input('from_tab');
        $targetTab = ($fromTab === 'requests') ? 'requests' : 'records';
        [$ok, $msg] = edit_request_create($_POST, $user);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php?tab=' . $targetTab);
    } elseif ($action === 'approve_edit_request') {
        if (!in_array($user['role'], ['Dean', 'Admin'], true)) {
            flash('error', 'Only Dean or Admin can approve Edit Requests.');
            redirect('/approvals.php');
        }
        $reqId   = (int) input('request_id');
        $comment = (string) input('decision_comment');
        [$ok, $msg] = edit_request_review($reqId, 'approve', $comment, $user);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php?tab=requests');
    } elseif ($action === 'reject_edit_request') {
        if (!in_array($user['role'], ['Dean', 'Admin'], true)) {
            flash('error', 'Only Dean or Admin can reject Edit Requests.');
            redirect('/approvals.php');
        }
        $reqId   = (int) input('request_id');
        $comment = (string) input('decision_comment');
        [$ok, $msg] = edit_request_review($reqId, 'reject', $comment, $user);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php?tab=requests');
    } elseif ($action === 'acknowledge_review') {
        if (!in_array($user['role'], ['HoD', 'Admin'], true)) {
            flash('error', 'Only HoD or Admin can acknowledge record review.');
            redirect('/approvals.php');
        }
        $type = (string) input('record_type');
        $id   = (int)    input('record_id');
        [$ok, $msg] = record_acknowledge_review($type, $id, $user);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php?tab=records');
    } elseif ($action === 'approve_all') {
        if ($user['role'] === 'HoD') {
            flash('error', 'HoD is a reviewer only and cannot approve records directly.');
            redirect('/approvals.php');
        }
        [$ok, $msg] = records_bulk_approve((string) input('department'), (int) $user['id'], $scopeDept, $user['role'], $activeYear);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php');
    } else {
        if ($user['role'] === 'HoD' && in_array($action, ['approve', 'reject'], true)) {
            flash('error', 'HoD is a reviewer only and cannot approve or reject submitted records.');
            redirect('/approvals.php');
        }
        $type   = (string) input('record_type');
        $id     = (int)    input('record_id');
        $remark = (string) input('review_remark');
        [$ok, $msg] = record_review($type, $id, $action, $remark, $user['id'], $scopeDept, $user['role'], $activeYear);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php');
    }
}

$types       = array_filter(record_types(), fn($k) => record_requires_approval($k), ARRAY_FILTER_USE_KEY);
$departments = departments_all();
$allYears    = academic_years();

$filterDept = in_array($user['role'], ['Admin', 'Dean'], true) ? (trim((string) input('department')) ?: null) : null;
$filterType = (string) input('type');
if (!isset($types[$filterType])) { $filterType = ''; }
$search = trim((string) input('q'));

$effectiveDept = $scopeDept ?? $filterDept;

$currentTab = (string) input('tab');
$defaultTab = match ($user['role']) {
    'Dean'        => 'requests',
    'Coordinator' => 'pending',
    'HoD'         => 'records',
    default       => 'pending',
};
if (!in_array($currentTab, ['records', 'requests', 'pending', 'corrections', 'history'], true)) {
    $currentTab = $defaultTab;
}
if ($user['role'] === 'HoD' && $currentTab === 'pending') {
    $currentTab = 'records';
}

$records = pending_records($effectiveDept, null, $user['role'], $activeYear);

if ($user['role'] === 'HoD' || ($user['role'] === 'Dean' && $currentTab === 'records') || ($user['role'] === 'Admin' && $currentTab === 'records')) {
    $allDeptRecords = [];
    $targetDept = ($user['role'] === 'HoD') ? $scopeDept : $filterDept;
    foreach ($types as $key => $t) {
        $recs = records_list($key, $targetDept, null, null, null, null, $activeYear);
        foreach ($recs as $r) {
            $r['_type_key']   = $key;
            $r['_type_label'] = $t['label'];
            $r['_title']      = $r[$t['title_col']] ?? '(untitled)';
            $allDeptRecords[] = $r;
        }
    }
    usort($allDeptRecords, fn($a, $b) => strtotime($b['created_at'] ?? '1970-01-01') <=> strtotime($a['created_at'] ?? '1970-01-01'));
    $records = $allDeptRecords;
}

if ($user['role'] === 'HoD') {
    $editRequests = edit_requests_list($scopeDept, null, null, (int)$user['id']);
} elseif ($user['role'] === 'Coordinator') {
    $editRequests = edit_requests_list($scopeDept, 'Approved', null);
} elseif ($user['role'] === 'Dean') {
    $editRequests = edit_requests_list($filterDept, null, null);
} else {     $editRequests = edit_requests_list($filterDept, null, null);
}

$pendingRequests = array_values(array_filter($editRequests, fn($er) => $er['status'] === 'Pending'));
$historyRequests = array_values(array_filter($editRequests, fn($er) => in_array($er['status'], ['Approved', 'Rejected', 'Completed'], true)));

$authorizedCorrections = [];
if ($user['role'] === 'Coordinator' || $user['role'] === 'Admin') {
    foreach ($types as $key => $t) {
        $cRecs = records_list($key, $scopeDept, 'Unlocked for Edit', null, null, null, $activeYear);
        foreach ($cRecs as $cr) {
            $cr['_type_key']   = $key;
            $cr['_type_label'] = $t['label'];
            $cr['_title']      = $cr[$t['title_col']] ?? '(untitled)';
            
            try {
                $erStmt = db()->prepare("SELECT * FROM edit_requests WHERE record_id = ? AND record_type = ? ORDER BY id DESC LIMIT 1");
                $erStmt->execute([(int)$cr['id'], $key]);
                $cr['_edit_request'] = $erStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\PDOException $e) {
                $cr['_edit_request'] = null;
            }
            
            $authorizedCorrections[] = $cr;
        }
    }
    usort($authorizedCorrections, fn($a, $b) => strtotime($b['updated_at'] ?? $b['created_at']) <=> strtotime($a['updated_at'] ?? $a['created_at']));
}

if ($filterType !== '') {
    $records = array_values(array_filter($records, fn($r) => $r['_type_key'] === $filterType));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $records = array_values(array_filter($records, function ($r) use ($needle) {
        $hay = mb_strtolower(($r['_title'] ?? '') . ' ' . ($r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? '') . ' ' . ($r['department'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    }));
}
$hasFilter = $filterDept || $filterType !== '' || $search !== '';

$isHod = $user['role'] === 'HoD';
$pageTitle = match ($user['role']) {
    'HoD'         => 'Review Records',
    'Coordinator' => 'Approvals & Verifications',
    'Dean'        => 'Governance & Approvals',
    default       => 'Approvals',
};
$breadcrumb = $isHod ? 'Review Records' : 'Approvals';
require __DIR__ . '/inc/header.php';
?>

<?php $activeCount = ($filterDept ? 1 : 0) + ($filterType !== '' ? 1 : 0) + ($search !== '' ? 1 : 0); ?>
<div class="page-head" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px">
  <div>
    <h1 style="font-size:24px; font-weight:700; color:#0F172A; margin:0 0 4px"><?= e($pageTitle) ?></h1>
    <div class="sub" style="font-size:13px; color:#64748B">
      <?php if ($user['role'] === 'HoD'): ?>
        <?= count($records) ?> department record<?= count($records) !== 1 ? 's' : '' ?> under review<?= $scopeDept ? ' · ' . e($scopeDept) : '' ?>
      <?php elseif ($user['role'] === 'Coordinator'): ?>
        <?= count($records) ?> pending verification · <?= count($authorizedCorrections) ?> authorized correction<?= count($authorizedCorrections) !== 1 ? 's' : '' ?><?= $scopeDept ? ' · ' . e($scopeDept) : '' ?>
      <?php elseif ($user['role'] === 'Dean'): ?>
        <?= count($pendingRequests) ?> pending edit request<?= count($pendingRequests) !== 1 ? 's' : '' ?> awaiting decision
      <?php else: ?>
        <?= count($records) ?> record<?= count($records) !== 1 ? 's' : '' ?> awaiting action
      <?php endif; ?>
    </div>
  </div>

  <!-- Role-specific tab navigation -->
  <div style="display:flex; flex-wrap:wrap; gap:6px; background:#F1F5F9; padding:4px; border-radius:10px; border:1px solid #E2E8F0; max-width:100%">
    <?php if ($user['role'] === 'HoD'): ?>
      <a href="?tab=records" class="btn btn-sm <?= $currentTab === 'records' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('file-text', 14) ?> Department Records (<?= count($records) ?>)
      </a>
      <a href="?tab=requests" class="btn btn-sm <?= $currentTab === 'requests' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('edit', 14) ?> My Edit Requests (<?= count($editRequests) ?>)
      </a>
    <?php elseif ($user['role'] === 'Coordinator'): ?>
      <a href="?tab=pending" class="btn btn-sm <?= $currentTab === 'pending' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('check-circle', 14) ?> Pending Verification (<?= count($records) ?>)
      </a>
      <a href="?tab=corrections" class="btn btn-sm <?= $currentTab === 'corrections' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px; <?= count($authorizedCorrections) > 0 ? 'border:1px solid #A7F3D0; font-weight:700;' : '' ?>">
        <?= icon('edit', 14) ?> Approved by Dean · Corrections (<?= count($authorizedCorrections) ?>)
      </a>
      <a href="?tab=requests" class="btn btn-sm <?= $currentTab === 'requests' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('file-text', 14) ?> Dean-Approved Requests (<?= count($editRequests) ?>)
      </a>
    <?php elseif ($user['role'] === 'Dean'): ?>
      <a href="?tab=requests" class="btn btn-sm <?= $currentTab === 'requests' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('edit', 14) ?> Edit Requests (<?= count($pendingRequests) > 0 ? count($pendingRequests) . ' pending' : count($editRequests) ?>)
      </a>
      <a href="?tab=records" class="btn btn-sm <?= $currentTab === 'records' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('file-text', 14) ?> Department Records (<?= count($records) ?>)
      </a>
      <a href="?tab=history" class="btn btn-sm <?= $currentTab === 'history' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('clock', 14) ?> Decision History (<?= count($historyRequests) ?>)
      </a>
    <?php else: ?>
      <a href="?tab=pending" class="btn btn-sm <?= $currentTab === 'pending' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('check-circle', 14) ?> Pending Records
      </a>
      <a href="?tab=records" class="btn btn-sm <?= $currentTab === 'records' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('file-text', 14) ?> Department Records (<?= count($records) ?>)
      </a>
      <a href="?tab=requests" class="btn btn-sm <?= $currentTab === 'requests' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('edit', 14) ?> Edit Requests (<?= count($pendingRequests) > 0 ? count($pendingRequests) . ' pending' : count($editRequests) ?>)
      </a>
      <a href="?tab=history" class="btn btn-sm <?= $currentTab === 'history' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:12px; height:32px; border-radius:8px">
        <?= icon('clock', 14) ?> History
      </a>
    <?php endif; ?>
  </div>
</div>

<?php $isYearLocked = academic_year_is_locked($activeYear); ?>
<?php if ($isYearLocked): ?>
  <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
      <?= icon('lock', 20) ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;color:#991B1B">Academic Year <?= e($activeYear) ?> Cycle is Locked</div>
      <div style="font-size:12px;color:#B91C1C;margin-top:2px">
        The Administrator has locked this academic year cycle following an Executive Meeting. Reviews and approvals are frozen across all roles.
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- =========================================================================
     TAB CONTENT 1: EDIT REQUESTS (For Dean / Admin / HoD Requests Tab)
     ========================================================================= -->
<?php if ($currentTab === 'requests'): ?>
  <?php
    $reqStatusFilter = trim((string) input('req_status'));
    if ($user['role'] === 'HoD') {
        $reqList = $editRequests;
        $tabTitle = 'My Edit Requests to Dean';
        $tabSub   = 'Track status of modification requests submitted for department records';
    } elseif ($user['role'] === 'Coordinator') {
        $reqList = $editRequests;
        $tabTitle = 'Dean-Approved Edit Requests';
        $tabSub   = 'Modification requests approved by Dean. You are authorized to correct and resubmit these records.';
    } else {         $tabTitle = ($user['role'] === 'Dean') ? 'Edit Requests & Decisions' : 'Edit Requests Oversight';
        $tabSub   = 'Review requests submitted by HoDs to unlock approved records for Coordinator correction.';
        if ($reqStatusFilter === 'Pending') {
            $reqList = $pendingRequests;
        } elseif ($reqStatusFilter === 'Approved') {
            $reqList = array_values(array_filter($editRequests, fn($er) => $er['status'] === 'Approved'));
        } elseif ($reqStatusFilter === 'Rejected') {
            $reqList = array_values(array_filter($editRequests, fn($er) => $er['status'] === 'Rejected'));
        } elseif ($reqStatusFilter === 'Completed') {
            $reqList = array_values(array_filter($editRequests, fn($er) => $er['status'] === 'Completed'));
        } else {
            $reqList = $editRequests;
        }
    }
  ?>
  <div class="card" style="margin-bottom:24px">
    <div class="card-head" style="padding:16px 20px; border-bottom:1px solid #E2E8F0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px">
      <div>
        <div class="card-title" style="font-size:16px; font-weight:700; color:#0F172A">
          <?= e($tabTitle) ?>
        </div>
        <div class="card-sub" style="font-size:12px; color:#64748B; margin-top:2px">
          <?= e($tabSub) ?>
        </div>
      </div>
      <?php if (in_array($user['role'], ['Dean', 'Admin'], true)): ?>
        <div style="display:flex; gap:6px; flex-wrap:wrap">
          <a href="?tab=requests&req_status=all" class="btn btn-sm <?= ($reqStatusFilter === 'all' || $reqStatusFilter === '') ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:11.5px; height:28px; border-radius:6px; padding:0 10px">
            All (<?= count($editRequests) ?>)
          </a>
          <a href="?tab=requests&req_status=Pending" class="btn btn-sm <?= $reqStatusFilter === 'Pending' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:11.5px; height:28px; border-radius:6px; padding:0 10px; <?= count($pendingRequests) > 0 ? 'border:1px solid #F59E0B; color:#B45309; font-weight:700;' : '' ?>">
            Pending Decision (<?= count($pendingRequests) ?>)
          </a>
          <a href="?tab=requests&req_status=Approved" class="btn btn-sm <?= $reqStatusFilter === 'Approved' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:11.5px; height:28px; border-radius:6px; padding:0 10px">
            Approved by Dean (<?= count(array_filter($editRequests, fn($e) => $e['status'] === 'Approved')) ?>)
          </a>
          <a href="?tab=requests&req_status=Rejected" class="btn btn-sm <?= $reqStatusFilter === 'Rejected' ? 'btn-primary' : 'btn-ghost' ?>" style="font-size:11.5px; height:28px; border-radius:6px; padding:0 10px">
            Rejected (<?= count(array_filter($editRequests, fn($e) => $e['status'] === 'Rejected')) ?>)
          </a>
        </div>
      <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($reqList)): ?>
        <div class="empty" style="padding:60px 24px; text-align:center">
          <div class="ic" style="background:#ECFDF5; color:#047857; width:50px; height:50px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; border-radius:50%">
            <?= icon('check', 24) ?>
          </div>
          <p style="font-size:15px; font-weight:600; color:#0F172A; margin:0 0 4px">No edit requests found</p>
          <div class="card-sub"><?= $user['role'] === 'HoD' ? 'You have not submitted any edit requests for this academic year.' : 'No edit requests match the selected view.' ?></div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data" style="width:100%; min-width:850px">
            <thead>
              <tr style="background:#F8FAFC; border-bottom:1px solid #E2E8F0">
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Request ID</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Requested By / Dept</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Faculty / Record</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Proof</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Issue / Requested Correction</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Status</th>
                <th class="num" style="padding:12px 20px; font-size:12px; font-weight:700; color:#475569; text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reqList as $er): ?>
                <tr style="border-bottom:1px solid #F1F5F9">
                  <td style="padding:14px 16px; vertical-align:top">
                    <span style="font-family:monospace; font-weight:700; color:#1E40AF; background:#DBEAFE; padding:3px 8px; border-radius:6px; font-size:12px">
                      ER-<?= str_pad((string)$er['id'], 4, '0', STR_PAD_LEFT) ?>
                    </span>
                    <div style="font-size:11px; color:#64748B; margin-top:4px"><?= e(time_ago($er['created_at'])) ?></div>
                  </td>
                  <td style="padding:14px 16px; vertical-align:top">
                    <div style="font-weight:600; color:#0F172A"><?= e($er['requested_by_name'] ?? 'HoD') ?></div>
                    <div style="font-size:12px; color:#64748B"><?= e($er['requested_by_role']) ?> · <span style="font-weight:600; color:#1E3A8A"><?= e($er['department']) ?></span></div>
                  </td>
                  <td style="padding:14px 16px; vertical-align:top">
                    <div style="font-weight:600; color:#0F172A; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap" title="<?= e($er['record_title']) ?>">
                      <?= e($er['record_title'] ?: '(untitled record)') ?>
                    </div>
                    <div style="font-size:12px; color:#64748B; margin-top:2px">
                      Faculty: <strong><?= e($er['faculty_name'] ?: 'Unknown') ?></strong> · <span class="badge badge-info" style="font-size:10px"><?= e($types[$er['record_type']]['label'] ?? $er['record_type']) ?></span>
                    </div>
                  </td>
                  <td style="padding:14px 16px; vertical-align:top">
                    <?php
                      $erProof = trim((string)($er['proof_file'] ?? ''));
                      if ($erProof === '' && !empty($er['record_type']) && !empty($er['record_id'])) {
                          $foundOrig = record_find($er['record_type'], (int)$er['record_id']);
                          $erProof = trim((string)($foundOrig['proof_file'] ?? ''));
                      }
                      if ($erProof !== '' && stripos($erProof, 'upload.php') !== false) {
                          $erProof = '';
                      }
                    ?>
                    <?php if ($erProof === ''): ?>
                      <span class="card-sub" style="font-size:11px">No proof attached</span>
                    <?php elseif (!proof_file_exists($erProof)): ?>
                      <span class="card-sub" style="color:var(--ink-muted,#64748b); font-size:11px">Proof unavailable</span>
                    <?php else: ?>
                      <?php $erProofUrl = record_proof_url($er['record_type'], (int)$er['record_id'], $erProof, false, false); ?>
                      <div style="display:inline-flex; align-items:center; gap:4px">
                        <button type="button" class="btn btn-sm" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; height:28px; padding:0 8px; font-size:11.5px; font-weight:600; display:inline-flex; align-items:center; gap:4px; border-radius:6px; cursor:pointer"
                          data-url="<?= e($erProofUrl) ?>"
                          data-title="<?= e($er['record_title'] ?? '') ?>"
                          data-who="<?= e($er['faculty_name'] ?? '') ?>"
                          data-dept="<?= e($er['department'] ?? '') ?>"
                          data-label="<?= e($types[$er['record_type']]['label'] ?? $er['record_type']) ?>"
                          onclick="openProofViewer(this.dataset.url, this.dataset.title, this.dataset.who, this.dataset.dept, this.dataset.label)">
                          <?= icon('paperclip', 13) ?> View Proof
                        </button>
                        <a href="<?= e($erProofUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="height:28px; padding:0 6px; font-size:11px" title="Open proof document directly in a new tab">
                          <?= icon('external-link', 12) ?>
                        </a>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding:14px 16px; vertical-align:top; max-width:320px">
                    <div style="font-size:12.5px; color:#1E293B; background:#F8FAFC; border:1px solid #E2E8F0; padding:8px 12px; border-radius:6px">
                      <div style="font-weight:600; color:#991B1B; margin-bottom:2px">Reason:</div>
                      <div><?= nl2br(e($er['reason'])) ?></div>
                      <?php if (!empty($er['specific_field'])): ?>
                        <div style="margin-top:6px; font-size:11.5px; border-top:1px dashed #CBD5E1; padding-top:4px">
                          <strong>Field:</strong> <?= e($er['specific_field']) ?><br>
                          <?php if (!empty($er['current_value'])): ?>Current: <span style="color:#64748B"><?= e($er['current_value']) ?></span> &rarr; <?php endif; ?>
                          Correction: <span style="color:#047857; font-weight:600"><?= e($er['requested_value'] ?? '') ?></span>
                        </div>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($er['decision_comment'])): ?>
                      <div style="font-size:11.5px; margin-top:6px; color:#1E40AF; background:#EFF6FF; padding:6px 10px; border-radius:6px; border:1px solid #BFDBFE">
                        <strong>Dean Note:</strong> <?= e($er['decision_comment']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding:14px 16px; vertical-align:top">
                    <?php if ($er['status'] === 'Approved'): ?>
                      <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:4px 8px; border-radius:6px; display:inline-flex; align-items:center; gap:4px">
                        <?= icon('check-circle', 13) ?> Approved by Dean
                      </span>
                    <?php else: ?>
                      <span class="badge badge-<?= status_class($er['status']) ?>"><?= e($er['status']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="num" style="padding:14px 20px; vertical-align:top; text-align:right">
                    <?php if (in_array($user['role'], ['Dean', 'Admin'], true) && $er['status'] === 'Pending'): ?>
                      <div style="display:inline-flex; gap:6px; flex-wrap:wrap; justify-content:flex-end">
                        <button type="button" class="btn btn-sm" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; height:30px; font-size:12px; font-weight:600"
                          onclick="openDecisionModal('approve', <?= (int)$er['id'] ?>, <?= e(json_encode('ER-' . str_pad((string)$er['id'], 4, '0', STR_PAD_LEFT))) ?>, <?= e(json_encode($er['faculty_name'])) ?>, <?= e(json_encode($er['record_title'])) ?>)">
                          <?= icon('check', 13) ?> Approve Request
                        </button>
                        <button type="button" class="btn btn-sm" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; height:30px; font-size:12px; font-weight:600"
                          onclick="openDecisionModal('reject', <?= (int)$er['id'] ?>, <?= e(json_encode('ER-' . str_pad((string)$er['id'], 4, '0', STR_PAD_LEFT))) ?>, <?= e(json_encode($er['faculty_name'])) ?>, <?= e(json_encode($er['record_title'])) ?>)">
                          <?= icon('x', 13) ?> Reject
                        </button>
                      </div>
                    <?php elseif ($er['status'] === 'Approved'): ?>
                      <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:4px">
                        <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px">
                          <?= icon('check-circle', 14) ?> Approved by Dean
                        </span>
                        <span style="font-size:11px; color:#4338CA; font-weight:600">
                          Unlocked for Coordinator
                        </span>
                        <?php if ($user['role'] === 'Coordinator'): ?>
                          <a class="btn btn-sm" style="background:#1D4ED8; color:#fff; font-weight:600; height:28px; padding:0 10px; font-size:11.5px; text-decoration:none; display:inline-flex; align-items:center; gap:4px; border-radius:6px; margin-top:3px"
                            href="<?= e(url('upload.php?type=' . urlencode($er['record_type']) . '&edit_id=' . (int)$er['record_id'])) ?>">
                            <?= icon('edit', 13) ?> Edit &amp; Resubmit
                          </a>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($er['status'] === 'Rejected'): ?>
                      <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:3px">
                        <span class="badge" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px">
                          <?= icon('x-circle', 14) ?> Rejected
                        </span>
                        <span style="font-size:11px; color:#64748B"><?= e(time_ago($er['decided_at'])) ?></span>
                      </div>
                    <?php elseif ($er['status'] === 'Completed'): ?>
                      <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:3px">
                        <span class="badge" style="background:#EEF2FF; color:#4338CA; border:1px solid #C7D2FE; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px">
                          <?= icon('check', 14) ?> Corrected &amp; Resubmitted
                        </span>
                        <span style="font-size:11px; color:#64748B"><?= e(time_ago($er['completed_at'] ?? $er['decided_at'])) ?></span>
                      </div>
                    <?php else: ?>
                      <span style="font-size:12px; color:#64748B"><?= e($er['decided_at'] ? date('d M Y', strtotime($er['decided_at'])) : '—') ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- =========================================================================
     TAB CONTENT 2: AUTHORIZED CORRECTIONS (For Coordinator Tab)
     ========================================================================= -->
<?php if ($currentTab === 'corrections'): ?>
  <div class="card" style="margin-bottom:24px">
    <div class="card-head" style="padding:16px 20px; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px">
      <div>
        <div class="card-title" style="font-size:16px; font-weight:700; color:#0F172A">
          <?= icon('check-circle', 18) ?> Records Approved by Dean for Correction
        </div>
        <div class="card-sub" style="font-size:12.5px; color:#64748B; margin-top:2px">
          Dean has approved the modification requests below. These records are unlocked for you to correct and resubmit.
        </div>
      </div>
      <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-weight:700; font-size:12px; padding:6px 12px; border-radius:6px">
        <?= count($authorizedCorrections) ?> Record<?= count($authorizedCorrections) !== 1 ? 's' : '' ?> Unlocked
      </span>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($authorizedCorrections)): ?>
        <div class="empty" style="padding:60px 24px; text-align:center">
          <div class="ic" style="background:#ECFDF5; color:#047857; width:50px; height:50px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; border-radius:50%">
            <?= icon('check', 24) ?>
          </div>
          <p style="font-size:15px; font-weight:600; color:#0F172A; margin:0 0 4px">No records awaiting correction</p>
          <div class="card-sub">There are currently no unlocked records requiring modification.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data" style="width:100%; min-width:850px">
            <thead>
              <tr style="background:#F8FAFC; border-bottom:1px solid #E2E8F0">
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Record Title</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Type</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Faculty</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Dean Approval &amp; Correction Note</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Status</th>
                <th class="num" style="padding:12px 20px; font-size:12px; font-weight:700; color:#475569; text-align:right">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($authorizedCorrections as $ac): ?>
                <?php $er = $ac['_edit_request'] ?? null; ?>
                <tr style="border-bottom:1px solid #F1F5F9">
                  <td style="padding:14px 16px; font-weight:600; color:#0F172A; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap" title="<?= e($ac['_title']) ?>">
                    <?= e($ac['_title']) ?>
                    <?php if ($er): ?>
                      <div style="font-size:11px; font-family:monospace; color:#1E40AF; font-weight:700; margin-top:2px">ER-<?= str_pad((string)$er['id'], 4, '0', STR_PAD_LEFT) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding:14px 16px"><span class="badge badge-info"><?= e($ac['_type_label']) ?></span></td>
                  <td style="padding:14px 16px">
                    <div style="font-weight:600; color:#0F172A"><?= e($ac['faculty_name'] ?? 'Faculty') ?></div>
                    <div style="font-size:11px; color:#64748B"><?= e($ac['department'] ?? '') ?></div>
                  </td>
                  <td style="padding:14px 16px; max-width:320px">
                    <div style="font-size:12px; background:#EFF6FF; border:1px solid #BFDBFE; padding:8px 12px; border-radius:6px; color:#1E3A8A">
                      <div style="font-weight:700; color:#1D4ED8; margin-bottom:2px">
                        <?= icon('check-circle', 12) ?> Dean Approved Note:
                      </div>
                      <div><?= e($ac['review_remark'] ?: ($er['decision_comment'] ?? 'Dean authorized modification.')) ?></div>
                      <?php if ($er && !empty($er['specific_field'])): ?>
                        <div style="margin-top:6px; font-size:11.5px; border-top:1px dashed #BFDBFE; padding-top:4px">
                          <strong>Target Field:</strong> <code style="background:#DBEAFE; color:#1E40AF; padding:1px 4px; border-radius:3px"><?= e($er['specific_field']) ?></code>
                          <?php if (!empty($er['requested_value'])): ?>
                            &rarr; <span style="color:#047857; font-weight:600"><?= e($er['requested_value']) ?></span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td style="padding:14px 16px">
                    <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:5px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:4px">
                      <?= icon('check-circle', 13) ?> Approved by Dean
                    </span>
                    <div style="font-size:11px; color:#4338CA; font-weight:600; margin-top:3px">Unlocked for Edit</div>
                  </td>
                  <td class="num" style="padding:14px 20px; text-align:right">
                    <a class="btn btn-sm" style="background:#1D4ED8; color:#fff; font-weight:600; height:32px; padding:0 12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; border-radius:6px"
                      href="<?= e(url('upload.php?type=' . urlencode($ac['_type_key']) . '&edit_id=' . (int)$ac['id'])) ?>">
                      <?= icon('edit', 14) ?> Edit &amp; Resubmit
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- =========================================================================
     TAB CONTENT 3: DECISION HISTORY (For Dean / Admin Tab)
     ========================================================================= -->
<?php if ($currentTab === 'history'): ?>
  <div class="card" style="margin-bottom:24px">
    <div class="card-head" style="padding:16px 20px; border-bottom:1px solid #E2E8F0">
      <div class="card-title" style="font-size:16px; font-weight:700; color:#0F172A">Edit Request Decision History</div>
      <div class="card-sub" style="font-size:12px; color:#64748B">Audit log of previously approved, rejected, and completed edit requests.</div>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($historyRequests)): ?>
        <div class="empty" style="padding:60px 24px; text-align:center">
          <p style="font-size:15px; font-weight:600; color:#0F172A; margin:0 0 4px">No historical decisions yet</p>
          <div class="card-sub">Decided edit requests will appear here.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data" style="width:100%; min-width:800px">
            <thead>
              <tr style="background:#F8FAFC; border-bottom:1px solid #E2E8F0">
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Request</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Department</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Faculty &amp; Title</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Decision</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Dean Comment</th>
                <th style="padding:12px 16px; font-size:12px; font-weight:700; color:#475569">Decided At</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historyRequests as $hr): ?>
                <tr style="border-bottom:1px solid #F1F5F9">
                  <td style="padding:12px 16px; font-family:monospace; font-weight:700; color:#1E40AF">
                    ER-<?= str_pad((string)$hr['id'], 4, '0', STR_PAD_LEFT) ?>
                  </td>
                  <td style="padding:12px 16px; font-weight:600; color:#0F172A"><?= e($hr['department']) ?></td>
                  <td style="padding:12px 16px">
                    <div style="font-weight:600; color:#0F172A"><?= e($hr['faculty_name']) ?></div>
                    <div style="font-size:12px; color:#64748B"><?= e($hr['record_title']) ?></div>
                  </td>
                  <td style="padding:12px 16px">
                    <span class="badge badge-<?= status_class($hr['status']) ?>"><?= e($hr['status']) ?></span>
                  </td>
                  <td style="padding:12px 16px; font-size:12.5px; color:#334155">
                    <?= e($hr['decision_comment'] ?: '—') ?>
                  </td>
                  <td style="padding:12px 16px; font-size:12px; color:#64748B">
                    <?= e($hr['decided_at'] ? date('d M Y, h:i A', strtotime($hr['decided_at'])) : '—') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- =========================================================================
     TAB CONTENT 4: DEPARTMENT RECORDS / PENDING APPROVALS
     ========================================================================= -->
<?php if ($currentTab === 'records' || $currentTab === 'pending'): ?>
  <?php if ($user['role'] === 'Coordinator' && count($authorizedCorrections) > 0): ?>
    <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-left:4px solid #059669; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px">
      <div style="display:flex; align-items:center; gap:12px">
        <span style="color:#047857; display:flex; align-items:center"><?= icon('check-circle', 22) ?></span>
        <div>
          <div style="font-weight:700; color:#065F46; font-size:14px">
            Dean has approved <?= count($authorizedCorrections) ?> edit request(s) for your department
          </div>
          <div style="font-size:12px; color:#047857; margin-top:2px">
            These records are unlocked for you to correct and resubmit for HoD review.
          </div>
        </div>
      </div>
      <a href="?tab=corrections" class="btn btn-sm" style="background:#059669; color:#fff; font-weight:600; text-decoration:none; padding:6px 14px; border-radius:6px; font-size:12px">
        View Approved Corrections (<?= count($authorizedCorrections) ?>) &rarr;
      </a>
    </div>
  <?php endif; ?>

  <!-- Filters Bar -->
  <form method="get" class="fbar" style="margin-bottom:20px">
    <input type="hidden" name="tab" value="<?= e($currentTab) ?>">
    <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

    <?php if (in_array($user['role'], ['Admin', 'Dean'], true)): ?>
      <label class="fb-field"><span class="fb-k">Department</span>
        <select name="department" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e($d['name']) ?>" <?= $filterDept === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

    <label class="fb-field"><span class="fb-k">Record type</span>
      <select name="type" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($types as $k => $t): ?>
          <option value="<?= e($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field fb-grow"><span class="fb-k">Search</span>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search title, faculty or department...">
    </label>

    <button type="submit" class="btn btn-primary btn-sm"><?= icon('search', 13) ?></button>
    <?php if ($activeCount > 0): ?>
      <a href="?tab=<?= e($currentTab) ?>" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($records)): ?>
    <div class="card"><div class="empty">
      <div class="ic"><?= icon('check', 28) ?></div>
      <p style="font-size:16px; font-weight:600; color:#0F172A; margin:0 0 4px">No records found</p>
      <div class="card-sub">
        <?= $search !== '' || $filterType !== '' || $filterDept
          ? 'Try adjusting your search or filters.'
          : ($user['role'] === 'HoD'
            ? 'No records currently in review for ' . e($scopeDept ?? 'your department') . '.'
            : 'No department records currently pending verification in academic year ' . e($activeYear) . '.') ?>
      </div>
    </div></div>
  <?php else: ?>
    <?php
      $byDept = [];
      foreach ($records as $r) {
          $d = $r['department'] ?? 'Other';
          $byDept[$d][] = $r;
      }
      ksort($byDept);
    ?>

    <?php foreach ($byDept as $deptName => $deptRecs): ?>
      <details class="acc-group" open style="margin-bottom:14px">
        <summary class="acc-summary" style="display:flex; justify-content:space-between; align-items:center">
          <div style="display:flex; align-items:center; gap:8px">
            <?= icon('building', 16) ?>
            <strong><?= e($deptName) ?></strong>
            <span class="badge badge-info" style="font-size:11px"><?= count($deptRecs) ?> under review</span>
          </div>
          <?php if (in_array($user['role'], ['Coordinator', 'Admin'], true) && !$isYearLocked): ?>
            <div style="display:inline-flex; gap:6px" onclick="event.stopPropagation()">
              <button class="btn btn-sm" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-weight:600; height:28px; padding:0 8px; font-size:11.5px"
                onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)">
                <?= icon('check-circle', 12) ?> Approve Department Submissions
              </button>
            </div>
          <?php endif; ?>
        </summary>
        <div class="table-wrap"><table class="data">
          <thead><tr>
            <th style="padding-left:24px; font-size:12px; font-weight:700; color:#475569">Record Title</th>
            <th style="font-size:12px; font-weight:700; color:#475569">Type</th>
            <th style="font-size:12px; font-weight:700; color:#475569">Status</th>
            <th style="font-size:12px; font-weight:700; color:#475569">Proof</th>
            <th style="font-size:12px; font-weight:700; color:#475569">Submitted / Note</th>
            <th class="num" style="padding-right:24px; text-align:right; font-size:12px; font-weight:700; color:#475569">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($deptRecs as $r): ?>
            <tr style="border-bottom:1px solid #F1F5F9">
              <td style="padding-left:24px; vertical-align:middle">
                <div style="font-weight:600; max-width:340px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#0F172A"><?= e($r['_title']) ?></div>
                <?php $who = $r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? ''; ?>
                <?php if ($who !== ''): ?><div class="card-sub" style="font-size:11.5px; color:#64748B"><?= e($who) ?> &middot; Dept: <?= e($r['department'] ?? 'N/A') ?></div><?php endif; ?>
              </td>
              <td style="vertical-align:middle"><span class="badge badge-info"><?= e($r['_type_label']) ?></span></td>
              <td style="vertical-align:middle">
                <?php if ($r['status'] === 'Unlocked for Edit'): ?>
                  <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:4px 8px; border-radius:6px; display:inline-flex; align-items:center; gap:4px">
                    <?= icon('check-circle', 12) ?> Approved by Dean
                  </span>
                  <div style="font-size:11px; color:#4338CA; font-weight:600; margin-top:2px">Unlocked for Edit</div>
                <?php else: ?>
                  <span class="badge badge-<?= status_class($r['status']) ?>"><?= e($r['status']) ?></span>
                <?php endif; ?>
              </td>
              <td style="vertical-align:middle">
                <?php
                  $pProof = trim((string)($r['proof_file'] ?? ''));
                  if ($pProof !== '' && stripos($pProof, 'upload.php') !== false) {
                      $pProof = '';
                  }
                ?>
                <?php if ($pProof === ''): ?>
                  <span class="card-sub">No proof attached</span>
                <?php elseif (!proof_file_exists($pProof)): ?>
                  <span class="card-sub" style="color:var(--ink-muted,#64748b);">Proof unavailable</span>
                <?php else: ?>
                  <?php
                    $pUrl = record_proof_url($r['_type_key'], (int)$r['id'], $pProof, false, false);
                  ?>
                  <div style="display:inline-flex; align-items:center; gap:6px">
                    <button type="button" class="btn btn-sm" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; height:28px; padding:0 8px; font-size:12px; display:inline-flex; align-items:center; gap:4px; font-weight:600; border-radius:6px; cursor:pointer"
                      data-url="<?= e($pUrl) ?>"
                      data-title="<?= e($r['_title']) ?>"
                      data-who="<?= e($who) ?>"
                      data-dept="<?= e($r['department'] ?? '') ?>"
                      data-label="<?= e($r['_type_label']) ?>"
                      onclick="openProofViewer(this.dataset.url, this.dataset.title, this.dataset.who, this.dataset.dept, this.dataset.label)">
                      <?= icon('paperclip', 13) ?> View Proof
                    </button>
                    <a href="<?= e($pUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="height:28px; padding:0 6px; font-size:11px; display:inline-flex; align-items:center; gap:3px" title="Open proof document directly in a new tab">
                      <?= icon('external-link', 12) ?> Tab
                    </a>
                  </div>
                <?php endif; ?>
              </td>
              <td class="card-sub" style="vertical-align:middle" title="<?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?>">
                <?= e(time_ago($r['created_at'])) ?>
                <?php if (!empty($r['review_remark'])): ?>
                  <?php if ($r['status'] === 'Unlocked for Edit'): ?>
                    <div style="font-size:11px; color:#1E40AF; background:#EFF6FF; border:1px solid #BFDBFE; padding:3px 6px; border-radius:4px; margin-top:3px">
                      <strong>Dean Note:</strong> <?= e($r['review_remark']) ?>
                    </div>
                  <?php else: ?>
                    <div style="font-size:11px; color:#1D4ED8; margin-top:2px"><strong>Note:</strong> <?= e($r['review_remark']) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="num" style="padding-right:24px; vertical-align:middle; text-align:right">
                <?php if (!$isYearLocked || $user['role'] === 'Admin'): ?>
                  <div class="flex gap-2" style="justify-content:flex-end; align-items:center">
                    <?php if ($user['role'] === 'HoD'): ?>
                      <!-- HoD: REVIEW ONLY. Check status to dynamically change button into Requested to Dean -->
                      <?php if ($r['status'] === 'Edit Requested'): ?>
                        <span class="badge" style="background:#FEF3C7; color:#B45309; border:1px solid #FCD34D; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px" title="Edit Request is pending Dean decision">
                          <?= icon('clock', 13) ?> Requested to Dean
                        </span>
                      <?php elseif ($r['status'] === 'Unlocked for Edit'): ?>
                        <span class="badge" style="background:#EEF2FF; color:#4338CA; border:1px solid #C7D2FE; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px" title="Dean approved edit; Coordinator authorized to modify">
                          <?= icon('edit', 13) ?> Unlocked · Coord Editing
                        </span>
                      <?php elseif ($r['status'] === 'Resubmitted'): ?>
                        <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:6px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:4px" title="Coordinator resubmitted correction">
                          <?= icon('check', 13) ?> Resubmitted
                        </span>
                        <button type="button" class="btn btn-sm" style="background:#047857; color:#fff; height:32px; padding:0 10px; font-size:12px; font-weight:600; margin-left:4px"
                          onclick="acknowledgeReview(<?= e(json_encode($r['_type_key'])) ?>, <?= (int)$r['id'] ?>)">
                          <?= icon('check-circle', 14) ?> Acknowledge Review
                        </button>
                        <button type="button" class="btn btn-sm" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; height:32px; padding:0 8px; font-size:11.5px; font-weight:600; margin-left:4px"
                          onclick="openHodEditRequest(<?= e(json_encode($r['_type_key'])) ?>, <?= (int)$r['id'] ?>, <?= e(json_encode($r['_title'])) ?>, <?= e(json_encode($who)) ?>, <?= e(json_encode($r['department'] ?? '')) ?>, <?= e(json_encode($r['academic_year'] ?? $activeYear)) ?>, <?= e(json_encode($r['_type_label'])) ?>)">
                          <?= icon('edit', 12) ?> Re-request
                        </button>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; height:32px; padding:0 10px; font-size:12px; font-weight:600"
                          onclick="openHodEditRequest(<?= e(json_encode($r['_type_key'])) ?>, <?= (int)$r['id'] ?>, <?= e(json_encode($r['_title'])) ?>, <?= e(json_encode($who)) ?>, <?= e(json_encode($r['department'] ?? '')) ?>, <?= e(json_encode($r['academic_year'] ?? $activeYear)) ?>, <?= e(json_encode($r['_type_label'])) ?>)">
                          <?= icon('edit', 14) ?> Request Edit to Dean
                        </button>
                      <?php endif; ?>
                    <?php elseif ($user['role'] === 'Coordinator'): ?>
                      <?php if ($r['status'] === 'Unlocked for Edit'): ?>
                        <a class="btn btn-sm" style="background:#1D4ED8; color:#fff; height:32px; padding:0 12px; font-size:12px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:600; border-radius:6px"
                          href="<?= e(url('upload.php?type=' . urlencode($r['_type_key']) . '&edit_id=' . (int)$r['id'])) ?>">
                          <?= icon('edit', 14) ?> Edit &amp; Resubmit
                        </a>
                      <?php else: ?>
                        <button class="btn btn-sm" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; height:32px; padding:0 10px; font-size:12px; font-weight:600"
                          onclick="reviewRecord('<?= e($r['_type_key']) ?>', <?= (int)$r['id'] ?>, 'approve')">
                          <?= icon('check', 14) ?> Approve &amp; Save to DB
                        </button>
                        <button class="btn btn-sm" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; height:32px; padding:0 10px; font-size:12px"
                          onclick="reviewRecord('<?= e($r['_type_key']) ?>', <?= (int)$r['id'] ?>, 'reject')">
                          <?= icon('x', 14) ?> Reject
                        </button>
                      <?php endif; ?>
                    <?php elseif ($user['role'] === 'Dean'): ?>
                      <?php if ($r['status'] === 'Edit Requested'): ?>
                        <a href="?tab=requests" class="btn btn-sm" style="background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; height:30px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:4px">
                          <?= icon('clock', 13) ?> Review Edit Request &rarr;
                        </a>
                      <?php elseif ($r['status'] === 'Unlocked for Edit'): ?>
                        <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:2px">
                          <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:4px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:4px" title="Dean approved edit; record is unlocked for Coordinator">
                            <?= icon('check-circle', 13) ?> Approved by Dean
                          </span>
                          <span style="font-size:11px; color:#4338CA; font-weight:600">Coord Editing</span>
                        </div>
                      <?php elseif ($r['status'] === 'Resubmitted'): ?>
                        <span class="badge" style="background:#EFF6FF; color:#1E40AF; border:1px solid #BFDBFE; font-size:12px; font-weight:700; padding:4px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:4px">
                          <?= icon('check', 13) ?> Resubmitted
                        </span>
                      <?php else: ?>
                        <span style="font-size:12px; color:#64748B; display:inline-flex; align-items:center; gap:4px">
                          <?= icon('check-circle', 13) ?> Review Only
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <?php if ($r['status'] === 'Unlocked for Edit'): ?>
                        <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:2px">
                          <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px" title="Approved by Dean; unlocked for Coordinator correction">
                            <?= icon('check-circle', 14) ?> Approved by Dean
                          </span>
                          <span style="font-size:11px; color:#4338CA; font-weight:600">Coord Editing</span>
                        </div>
                      <?php elseif ($r['status'] === 'Edit Requested'): ?>
                        <a href="?tab=requests" class="btn btn-sm" style="background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; height:30px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:4px">
                          <?= icon('clock', 13) ?> Edit Requested &rarr;
                        </a>
                      <?php elseif ($r['status'] === 'Approved'): ?>
                        <span class="badge" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px">
                          <?= icon('check', 13) ?> Approved
                        </span>
                      <?php elseif ($r['status'] === 'Resubmitted'): ?>
                        <span class="badge" style="background:#EFF6FF; color:#1E40AF; border:1px solid #C7D2FE; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px">
                          <?= icon('check', 13) ?> Resubmitted (HoD Reviewing)
                        </span>
                      <?php else: ?>
                        <button class="btn btn-sm" style="background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; height:32px; padding:0 10px; font-size:12px; font-weight:600"
                          onclick="reviewRecord('<?= e($r['_type_key']) ?>', <?= (int)$r['id'] ?>, 'approve')">
                          <?= icon('check', 14) ?> Approve
                        </button>
                        <button class="btn btn-sm" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; height:32px; padding:0 10px; font-size:12px"
                          onclick="reviewRecord('<?= e($r['_type_key']) ?>', <?= (int)$r['id'] ?>, 'reject')">
                          <?= icon('x', 14) ?> Reject
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span style="display:inline-flex; align-items:center; gap:4px; color:#991B1B; background:#FEE2E2; border:1px solid #FECACA; font-size:11px; font-weight:700; padding:2px 8px; border-radius:6px">
                    <?= icon('lock', 11) ?> Cycle Locked
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </details>
    <?php endforeach; ?>

    <!-- Bulk approve form (Coordinator/Admin only) -->
    <form method="post" id="bulkForm" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="review_action" value="approve_all">
      <input type="hidden" name="department" id="bulk-dept">
    </form>
  <?php endif; ?>
<?php endif; ?>

<!-- =========================================================================
     MODAL 1: HoD EDIT REQUEST MODAL
     ========================================================================= -->
<dialog class="modal" id="hodEditDlg" style="max-width:34rem; width:92vw; border-radius:12px">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="from_tab" value="<?= e($currentTab) ?>">
    <input type="hidden" name="review_action" value="create_edit_request">
    <input type="hidden" name="record_type" id="her-type">
    <input type="hidden" name="record_id" id="her-id">

    <div class="modal-head" style="padding:16px 20px; border-bottom:1px solid #E2E8F0">
      <h3 style="margin:0; font-size:16px; font-weight:700; color:#0F172A">Submit Edit Request to Dean</h3>
      <div style="font-size:12px; color:#64748B; margin-top:2px">
        Request authorization to correct an officially submitted department record.
      </div>
    </div>

    <div class="modal-body" style="padding:18px 20px">
      <!-- Auto-fetched HoD & Record Metadata Box -->
      <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:12px 14px; margin-bottom:16px; font-size:12.5px; line-height:1.6">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px 12px">
          <div><strong style="color:#475569">HoD:</strong> <span id="her-hod-name"><?= e($user['name']) ?></span></div>
          <div><strong style="color:#475569">Department:</strong> <span id="her-hod-dept" style="color:#1E3A8A; font-weight:700"><?= e($user['department'] ?? 'CSBS') ?></span></div>
          <div><strong style="color:#475569">Faculty:</strong> <span id="her-faculty" style="font-weight:600"></span></div>
          <div><strong style="color:#475569">Category:</strong> <span id="her-cat" class="badge badge-info" style="font-size:10px"></span></div>
          <div><strong style="color:#475569">Academic Year:</strong> <span id="her-year"></span></div>
          <div><strong style="color:#475569">Record ID:</strong> <span id="her-rec-id" style="font-family:monospace"></span></div>
        </div>
        <div style="margin-top:6px; border-top:1px dashed #E2E8F0; padding-top:6px">
          <strong style="color:#475569">Record Title:</strong> <span id="her-title" style="font-weight:600; color:#0F172A"></span>
        </div>
      </div>

      <!-- Mandatory Reason -->
      <div class="field" style="margin-bottom:14px">
        <label style="display:block; font-size:13px; font-weight:700; color:#0F172A; margin-bottom:6px">
          Issue / Reason for Edit <span class="req" style="color:#DC2626">*</span>
        </label>
        <textarea class="input" name="reason" rows="3" required placeholder="Explain clearly why this record requires modification (e.g., incorrect publication date, wrong volume/issue, etc.)…"></textarea>
      </div>

      <!-- Optional Specific Field & Values -->
      <div style="background:#F1F5F9; border-radius:8px; padding:12px; border:1px solid #E2E8F0">
        <div style="font-size:12px; font-weight:700; color:#475569; margin-bottom:8px">Specific Field to Correct (Optional)</div>
        <div class="field" style="margin-bottom:10px">
          <input class="input" type="text" name="specific_field" placeholder="Field name (e.g. Publication Date, Volume, Authors)">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px">
          <div class="field">
            <label style="font-size:11px; font-weight:600; color:#64748B">Current Value</label>
            <input class="input" type="text" name="current_value" placeholder="e.g. 12/03/2026">
          </div>
          <div class="field">
            <label style="font-size:11px; font-weight:600; color:#64748B">Requested Value</label>
            <input class="input" type="text" name="requested_value" placeholder="e.g. 10/03/2026">
          </div>
        </div>
      </div>
    </div>

    <div class="modal-foot" style="padding:14px 20px; border-top:1px solid #E2E8F0; display:flex; justify-content:flex-end; gap:8px">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-sm" style="background:#1D4ED8; color:#fff; font-weight:600">
        Submit Edit Request to Dean
      </button>
    </div>
  </form>
</dialog>

<!-- =========================================================================
     MODAL 2: DEAN DECISION MODAL (Approve / Reject Edit Request)
     ========================================================================= -->
<dialog class="modal" id="decisionDlg" style="max-width:28rem; width:90vw; border-radius:12px">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="review_action" id="dec-action">
    <input type="hidden" name="request_id" id="dec-req-id">

    <div class="modal-head" style="padding:16px 20px; border-bottom:1px solid #E2E8F0">
      <h3 id="dec-title" style="margin:0; font-size:16px; font-weight:700; color:#0F172A">Decision on Edit Request</h3>
    </div>

    <div class="modal-body" style="padding:18px 20px">
      <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12.5px">
        <div><strong>Request:</strong> <span id="dec-req-label"></span></div>
        <div><strong>Faculty:</strong> <span id="dec-faculty"></span></div>
        <div style="margin-top:2px; font-weight:600; color:#0F172A" id="dec-rec-title"></div>
      </div>

      <div class="field">
        <label id="dec-comment-label" style="font-size:13px; font-weight:700; color:#0F172A; display:block; margin-bottom:6px">
          Decision Comment
        </label>
        <textarea class="input" name="decision_comment" id="dec-comment" rows="3" placeholder="Add instructions for Coordinator or reason for rejection…"></textarea>
      </div>
    </div>

    <div class="modal-foot" style="padding:14px 20px; border-top:1px solid #E2E8F0; display:flex; justify-content:flex-end; gap:8px">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-sm" id="dec-btn">Confirm Decision</button>
    </div>
  </form>
</dialog>

<!-- =========================================================================
     MODAL 3: COORDINATOR / ADMIN REVIEW RECORD MODAL
     ========================================================================= -->
<dialog class="modal" id="reviewDlg" style="max-width:30rem; width:90vw; border-radius:12px">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="record_type" id="rv-type">
    <input type="hidden" name="record_id" id="rv-id">
    <input type="hidden" name="review_action" id="rv-action">

    <div class="modal-head" style="padding:16px 20px; border-bottom:1px solid #E2E8F0">
      <h3 id="rv-title" style="margin:0; font-size:16px; font-weight:700; color:#0F172A">Review Record</h3>
    </div>

    <div class="modal-body" style="padding:18px 20px">
      <div class="field">
        <label id="rv-remark-label" style="font-size:13px; font-weight:700; color:#0F172A; display:block; margin-bottom:6px">Remark (Optional)</label>
        <textarea class="input" name="review_remark" id="rv-remark" rows="3" placeholder="Add notes or feedback…"></textarea>
      </div>
    </div>

    <div class="modal-foot" style="padding:14px 20px; border-top:1px solid #E2E8F0; display:flex; justify-content:flex-end; gap:8px">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-sm" id="rv-btn" style="background:#047857; color:#fff">Confirm</button>
    </div>
  </form>
</dialog>

<!-- =========================================================================
     MODAL 4: PROOF VIEWER DIALOG
     ========================================================================= -->
<dialog class="modal" id="proofDlg" style="max-width:56rem; width:94vw; padding:0; border-radius:12px; overflow:hidden; border:none; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)">
  <div class="modal-head" style="padding:16px 20px; background:#fff; border-bottom:1px solid #E2E8F0; display:flex; align-items:center; justify-content:space-between; gap:12px">
    <div style="min-width:0; flex:1">
      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap">
        <h3 id="pv-title" style="margin:0; font-size:15px; font-weight:700; color:#0F172A; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:400px">Proof Document</h3>
        <span class="badge badge-info" id="pv-type">PDF</span>
      </div>
      <div class="card-sub" id="pv-meta" style="margin-top:3px; font-size:12px">Faculty Member &middot; Dept</div>
    </div>
    <div style="display:flex; align-items:center; gap:8px; flex-shrink:0">
      <a id="pv-newtab" href="#" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="height:32px; font-size:12px; display:inline-flex; align-items:center; gap:4px">
        <?= icon('external-link', 14) ?> Open Tab
      </a>
      <a id="pv-download" href="#" class="btn btn-outline btn-sm" style="height:32px; font-size:12px; display:inline-flex; align-items:center; gap:4px">
        <?= icon('download', 14) ?> Download
      </a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeProofViewer()" style="font-size:20px; line-height:1; width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; color:#64748B" title="Close">&times;</button>
    </div>
  </div>
  <div class="modal-body" style="padding:0; background:#F8FAFC; min-height:68vh; position:relative">
    <div id="pv-loader" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#F8FAFC; z-index:1; gap:10px; color:#64748B">
      <div style="font-size:13px; font-weight:500">Loading proof document…</div>
    </div>
    <iframe id="pv-frame" src="" style="width:100%; height:72vh; border:none; display:block; background:#fff" onload="document.getElementById('pv-loader').style.display='none'"></iframe>
    <div style="padding:10px 16px; background:#F1F5F9; border-top:1px solid #E2E8F0; font-size:12px; color:#64748B; display:flex; align-items:center; justify-content:space-between">
      <span>If preview does not render directly in your browser, use <strong>Open Tab</strong> or <strong>Download</strong> above.</span>
      <button type="button" class="btn btn-outline btn-sm" style="height:26px; font-size:11px; padding:0 8px" onclick="closeProofViewer()">Close</button>
    </div>
  </div>
</dialog>
<script>const currentRole = <?= json_encode($user['role']) ?>;
const canProcess = <?= json_encode($canProcess) ?>;

function openHodEditRequest(type, id, title, who, dept, year, typeLabel) {
  document.getElementById('her-type').value = type;
  document.getElementById('her-id').value = id;
  document.getElementById('her-title').textContent = title || '(untitled)';
  document.getElementById('her-faculty').textContent = who || 'Faculty Member';
  document.getElementById('her-cat').textContent = typeLabel || type;
  document.getElementById('her-year').textContent = year || '';
  document.getElementById('her-rec-id').textContent = '#' + id;
  var dlg = document.getElementById('hodEditDlg');
  if (dlg && dlg.showModal) {
    dlg.showModal();
  }
}

function openDecisionModal(decision, reqId, reqLabel, faculty, title) {
  document.getElementById('dec-action').value = decision === 'approve' ? 'approve_edit_request' : 'reject_edit_request';
  document.getElementById('dec-req-id').value = reqId;
  document.getElementById('dec-req-label').textContent = reqLabel;
  document.getElementById('dec-faculty').textContent = faculty;
  document.getElementById('dec-rec-title').textContent = title;
  
  const titleEl = document.getElementById('dec-title');
  const btn = document.getElementById('dec-btn');
  const label = document.getElementById('dec-comment-label');
  const input = document.getElementById('dec-comment');

  if (decision === 'approve') {
    titleEl.textContent = 'Approve Edit Request & Unlock Record';
    label.textContent = 'Instructions / Note for Coordinator (Optional)';
    input.placeholder = 'Add any specific guidance for the Coordinator…';
    btn.textContent = 'Approve & Unlock for Coordinator';
    btn.className = 'btn btn-sm';
    btn.style.cssText = 'background:#047857; color:#fff; font-weight:600';
  } else {
    titleEl.textContent = 'Reject Edit Request';
    label.textContent = 'Rejection Reason for HoD (Required)';
    input.placeholder = 'Explain why this edit request is rejected…';
    btn.textContent = 'Reject Request';
    btn.className = 'btn btn-danger btn-sm';
    btn.style.cssText = '';
  }

  var dlg = document.getElementById('decisionDlg');
  if (dlg && dlg.showModal) {
    dlg.showModal();
  }
}

function reviewRecord(type, id, action) {
  document.getElementById('rv-type').value = type;
  document.getElementById('rv-id').value = id;
  document.getElementById('rv-action').value = action;
  
  const btn = document.getElementById('rv-btn');
  const title = document.getElementById('rv-title');
  const label = document.getElementById('rv-remark-label');

  if (action === 'reject') {
    title.textContent = 'Reject Record';
    label.textContent = 'Rejection Reason (Optional)';
    btn.textContent = 'Confirm Rejection';
    btn.className = 'btn btn-danger btn-sm';
    btn.style.cssText = '';
  } else {
    title.textContent = currentRole === 'Coordinator' ? 'Approve & Save to Database' : 'Approve Record';
    label.textContent = 'Remark (Optional)';
    btn.textContent = currentRole === 'Coordinator' ? 'Approve & Save to DB' : 'Approve';
    btn.className = 'btn btn-sm';
    btn.style.cssText = 'background:#047857; color:#fff; font-weight:600';
  }

  var dlg = document.getElementById('reviewDlg');
  if (dlg && dlg.showModal) {
    dlg.showModal();
  }
}

function acknowledgeReview(type, id) {
  if (!confirm('Acknowledge review for this corrected record and confirm as Approved in the database?')) return;
  var form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = '<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">' +
                   '<input type="hidden" name="review_action" value="acknowledge_review">' +
                   '<input type="hidden" name="record_type" value="' + type + '">' +
                   '<input type="hidden" name="record_id" value="' + id + '">';
  document.body.appendChild(form);
  form.submit();
}

function approveAll(ev, dept, n) {
  ev.preventDefault();
  ev.stopPropagation();
  let msg = 'Approve all ' + n + ' pending record' + (n === 1 ? '' : 's') + ' in ' + dept + ' directly into the database?';
  if (!confirm(msg)) return;
  document.getElementById('bulk-dept').value = dept;
  document.getElementById('bulkForm').submit();
}

function openProofViewer(url, title, who, dept, typeLabel) {
  if (!url || url.indexOf('upload.php') !== -1) return;
  document.getElementById('pv-title').textContent = title || 'Proof Attachment';
  document.getElementById('pv-type').textContent = typeLabel || 'Document';
  var metaParts = [];
  if (who) metaParts.push(who);
  if (dept) metaParts.push('Dept: ' + dept);
  document.getElementById('pv-meta').textContent = metaParts.join(' · ') || 'Proof Document';

  document.getElementById('pv-newtab').href = url;
  document.getElementById('pv-download').href = url + (url.indexOf('?') >= 0 ? '&download=1' : '?download=1');

  var loader = document.getElementById('pv-loader');
  if (loader) {
    loader.style.display = 'flex';
  }

  var frame = document.getElementById('pv-frame');
  frame.src = url;

  var dlg = document.getElementById('proofDlg');
  if (dlg) {
    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    } else {
      dlg.setAttribute('open', '');
    }
  }

  // Safety fallback if iframe load event doesn't fire for PDF plugins
  setTimeout(function() {
    if (loader) loader.style.display = 'none';
  }, 1500);
}

function closeProofViewer() {
  var dlg = document.getElementById('proofDlg');
  var frame = document.getElementById('pv-frame');
  if (frame) frame.src = '';
  if (dlg) {
    if (typeof dlg.close === 'function') {
      dlg.close();
    } else {
      dlg.removeAttribute('open');
    }
  }
}

['proofDlg', 'reviewDlg', 'decisionDlg', 'hodEditDlg'].forEach(function(id) {
  var d = document.getElementById(id);
  if (d) {
    d.addEventListener('click', function(e) {
      var rect = this.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
        if (id === 'proofDlg') {
          closeProofViewer();
        } else {
          this.close();
        }
      }
    });
  }
});

var proofDlgEl = document.getElementById('proofDlg');
if (proofDlgEl) {
  proofDlgEl.addEventListener('close', function() {
    var frame = document.getElementById('pv-frame');
    if (frame) frame.src = '';
  });
}</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
