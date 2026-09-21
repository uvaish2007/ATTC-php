<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/EditRequest.php';
require_once __DIR__ . '/models/Target.php';

$user = require_role(['Admin', 'HoD', 'Dean', 'Coordinator']);

// A Coordinator or HoD may only review their own department; Admin/Dean may review any.
$scopeDept = user_department_scope($user);
$activeYear = active_academic_year();
$isHod = $user['role'] === 'HoD';
$canProcess = in_array($user['role'], ['Admin', 'Dean'], true);
journal_process_approval_expiry();

// Active Tab: 'records' (default) or 'edit_requests'
$activeTab = trim((string) input('tab', 'records'));
if (!in_array($activeTab, ['records', 'edit_requests'], true) || ($activeTab === 'edit_requests' && !$canProcess && !$isHod)) {
    $activeTab = 'records';
}

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Handle ticket processing from Dean/Admin
    if (input('process_action') !== '') {
        if (!$canProcess) {
            flash('error', 'Unauthorized: Only Dean and Administrator can process edit requests.');
            redirect('/approvals.php?tab=edit_requests');
        }
        $pAction       = (string) input('process_action');
        $ticketId      = (int) input('ticket_id');
        $adminComments = trim((string) input('admin_comments'));

        [$ok, $msg] = edit_request_process($ticketId, $pAction, $adminComments, (int) $user['id'], (string) $user['role']);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php?tab=edit_requests');
    }

    if ($user['role'] !== 'Admin' && academic_year_is_locked($activeYear)) {
        flash('error', "Academic year {$activeYear} cycle is locked by Administrator. Approvals are frozen for all roles.");
        redirect('/approvals.php' . ($activeTab === 'edit_requests' ? '?tab=edit_requests' : ''));
    }

    $action = (string) input('review_action');

    // SECURITY: HOD must never be able to approve or reject records directly!
    if ($user['role'] === 'HoD' && in_array($action, ['approve', 'reject', 'approve_all'], true)) {
        flash('error', 'HOD does not have permission to directly approve or reject records. Please submit an Edit Request to Dean/Admin.');
        redirect('/approvals.php');
    }

    if ($action === 'request_edit') {
        $type        = (string) input('record_type');
        $id          = (int)    input('record_id');
        $reason      = trim((string) input('reason'));
        $correction  = trim((string) input('correction'));
        $hodComments = trim((string) input('hod_comments'));

        $orig = edit_request_original_record($type, $id);
        if (!$orig) {
            flash('error', 'Target record not found.');
            redirect('/approvals.php');
        }

        // Enforce department scope
        if ($scopeDept !== null && ($orig['department'] ?? '') !== $scopeDept) {
            flash('error', 'Unauthorized: You may only submit edit requests for records within your department.');
            redirect('/approvals.php');
        }

        // Enforce academic year match unless Admin
        if (!empty($orig['academic_year']) && $orig['academic_year'] !== $activeYear && $user['role'] !== 'Admin') {
            flash('error', "Cannot request edit for a record from an inactive academic year ({$orig['academic_year']}).");
            redirect('/approvals.php');
        }

        [$ok, $msg] = edit_request_create([
            'record_type'   => $type,
            'record_id'     => $id,
            'faculty_name'  => $orig['faculty_name'] ?? $orig['candidate_name'] ?? $orig['student_name'] ?? '',
            'department'    => $orig['department'] ?? ($scopeDept ?? ''),
            'academic_year' => $orig['academic_year'] ?? $activeYear,
            'category'      => $orig['_type_label'] ?? '',
            'record_title'  => $orig['_title'] ?? '',
            'reason'        => $reason,
            'correction'    => $correction,
            'hod_comments'  => $hodComments,
            'requested_by'  => (int) $user['id'],
        ]);

        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php');
    } elseif ($action === 'approve_all') {
        if ($user['role'] === 'HoD') {
            flash('error', 'HOD cannot perform bulk approval. Please submit structured edit requests.');
            redirect('/approvals.php');
        }
        [$ok, $msg] = records_bulk_approve((string) input('department'), (int) $user['id'], $scopeDept, $user['role'], $activeYear);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/approvals.php');
    } else {
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

// Edit requests counts (scoped to HOD dept, or global for Admin/Dean)
$scopeDeptForEdit = !user_can_choose_department($user) ? user_department_scope($user) : null;
$allScopeEditTickets = edit_requests_list(['department' => $scopeDeptForEdit ?: null]);
$editCounts = [
    'total'     => count($allScopeEditTickets),
    'Pending'   => count(array_filter($allScopeEditTickets, fn($t) => $t['status'] === 'Pending')),
    'Approved'  => count(array_filter($allScopeEditTickets, fn($t) => $t['status'] === 'Approved')),
    'Rejected'  => count(array_filter($allScopeEditTickets, fn($t) => $t['status'] === 'Rejected')),
    'Completed' => count(array_filter($allScopeEditTickets, fn($t) => $t['status'] === 'Completed')),
];
$pendingEditRequestsCount = $editCounts['Pending'];

// 1. Records under review logic
$filterDept = user_can_choose_department($user) ? (trim((string) input('department')) ?: null) : null;
$filterType = (string) input('type');
if (!isset($types[$filterType])) { $filterType = ''; }
$search = trim((string) input('q'));

$effectiveDept = $scopeDept ?? $filterDept;
$records = pending_records($effectiveDept, null, $user['role'], $activeYear);

if ($filterType !== '') {
    $records = array_values(array_filter($records, fn($r) => $r['_type_key'] === $filterType));
}
if ($search !== '') {
    $needle  = mb_strtolower($search);
    $records = array_values(array_filter($records, function ($r) use ($needle) {
        $hay = mb_strtolower(($r['_title'] ?? '') . ' ' . ($r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? '') . ' ' . ($r['department'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    }));
}
$hasFilter = $filterDept || $filterType !== '' || $search !== '';

// 2. Edit Requests tab tickets data
$filterEditStatus = (string) input('status', 'all');
if (!in_array($filterEditStatus, ['all', 'Pending', 'Approved', 'Rejected', 'Completed'], true)) {
    $filterEditStatus = 'all';
}
$filterEditDept = $scopeDeptForEdit ?: (user_can_choose_department($user) ? trim((string) input('edit_department', input('department'))) : null);
$filterEditYear = trim((string) input('academic_year'));
$editSearch     = trim((string) input('eq', input('q')));

$editTickets = edit_requests_list([
    'status'        => $filterEditStatus,
    'department'    => $filterEditDept ?: null,
    'academic_year' => $filterEditYear ?: null,
    'q'             => $editSearch ?: null,
]);

$ticketDetails = [];
foreach ($editTickets as $t) {
    $orig = edit_request_original_record($t['record_type'], (int) $t['record_id']);
    $ticketDetails[$t['id']] = [
        'ticket' => $t,
        'orig'   => $orig,
    ];
}

// Map each record key 'type:id' to its ticket details for instant review in the records table
$ticketsByRecord = [];
foreach ($allScopeEditTickets as $t) {
    $key = $t['record_type'] . ':' . $t['record_id'];
    if (!isset($ticketsByRecord[$key])) {
        $orig = edit_request_original_record($t['record_type'], (int) $t['record_id']);
        $ticketsByRecord[$key] = [
            'ticket' => $t,
            'orig'   => $orig,
        ];
    }
}

$pageTitle  = $isHod ? 'Review Records' : 'Approvals';
$breadcrumb = $isHod ? 'Review Records' : 'Approvals';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head" style="margin-bottom:16px">
  <div>
    <h1><?= $isHod ? 'Review Records' : 'Approvals' ?></h1>
    <div class="sub">
      <?= $isHod ? 'Review departmental records and submit edit requests to Dean/Admin' : 'Review submitted records and process HOD edit requests' ?>
      <?= $scopeDept ? ' · ' . e($scopeDept) : '' ?>
    </div>
  </div>
</div>

<!-- Unified Tabs: Records Review vs. Edit Requests -->
<div class="tabs" role="tablist" style="margin-bottom:24px">
  <a href="<?= e(url('approvals.php?tab=records')) ?>" class="tab <?= $activeTab === 'records' ? 'active' : '' ?>" role="tab" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
    <?= icon('file-text', 15) ?>
    <span><?= $isHod ? 'Records Review' : 'Pending Approvals' ?></span>
    <span class="tab-count"><?= count($records) ?></span>
  </a>

  <?php if ($canProcess || $isHod): ?>
    <a href="<?= e(url('approvals.php?tab=edit_requests')) ?>" class="tab <?= $activeTab === 'edit_requests' ? 'active' : '' ?>" role="tab" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
      <?= icon('edit', 15) ?>
      <span>Edit Requests</span>
      <span class="tab-count" style="<?= $pendingEditRequestsCount > 0 ? 'background:#FEF3C7;color:#92400E;font-weight:700' : '' ?>"><?= $pendingEditRequestsCount > 0 ? $pendingEditRequestsCount . ' pending' : $editCounts['total'] ?></span>
    </a>
  <?php endif; ?>
</div>

<!-- TAB 1: PENDING RECORDS REVIEW -->
<?php if ($activeTab === 'records'): ?>
  <?php $activeCount = ($filterDept ? 1 : 0) + ($filterType !== '' ? 1 : 0) + ($search !== '' ? 1 : 0); ?>
  <form method="get" class="fbar">
    <input type="hidden" name="tab" value="records">
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
        <?php foreach ($types as $key => $t): ?>
          <option value="<?= e($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field fb-search">
      <?= icon('search', 15) ?>
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search title, person or department…" aria-label="Search approvals">
      <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
    </label>

    <span class="fbar-end">
      <?php if ($activeCount): ?>
        <span class="fbar-count"><?= (int) ($activeCount) ?> active</span>
        <a class="fbar-clear" href="<?= e(url('approvals.php?tab=records')) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note">Showing everything in scope</span>
      <?php endif; ?>
    </span>
  </form>

  <?php $isYearLocked = academic_year_is_locked($activeYear); ?>
  <?php if ($isYearLocked): ?>
    <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
      <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
        <?= icon('lock', 20) ?>
      </div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:13px;color:#991B1B">Academic Year <?= e($activeYear) ?> Cycle is Locked</div>
        <div style="font-size:12px;color:#B91C1C;margin-top:2px">
          The Administrator has locked this academic year cycle following an Executive Meeting. Record reviews and approvals are frozen across all roles. <?= $user['role'] === 'Admin' ? 'As an Administrator, you retain review authority.' : 'Approvals cannot be made until the cycle is unlocked by an Administrator.' ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php
    $emApprovals     = em_status($activeYear);
    $emLockedPending = $emApprovals['em1_locked']
        ? count(array_filter($records, fn($r) => em_record_is_locked($user['role'], $r['created_at'] ?? null, $activeYear)))
        : 0;
  ?>
  <?php if ($emApprovals['em1_locked'] && !$isYearLocked): ?>
    <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
      <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
        <?= icon('lock', 20) ?>
      </div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:13px;color:#991B1B">EM1 is Locked</div>
        <div style="font-size:12px;color:#B91C1C;margin-top:2px">
          EM1 closed on <?= e(date('d M Y', strtotime($emApprovals['schedule']['em1_end']))) ?>. Records submitted during EM1 are read-only.
          <?php if ($user['role'] === 'Admin'): ?>
            As an Administrator, you retain review authority.
          <?php elseif ($emLockedPending): ?>
            <?= (int) $emLockedPending ?> pending record<?= $emLockedPending === 1 ? ' below was' : 's below were' ?> submitted during EM1 and can no longer be approved or rejected.
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($records)): ?>
    <div class="card"><div class="card-body" style="padding:0">
      <div class="empty" style="padding:80px 24px">
        <?php if ($hasFilter): ?>
          <div class="ic" style="width:56px; height:56px"><?= icon('filter', 24) ?></div>
          <p style="font-size:16px; font-weight:600">No records match these filters</p>
          <div class="note">Widen or <a href="<?= e(url('approvals.php?tab=records')) ?>">clear</a> them to see every pending record.</div>
        <?php else: ?>
          <div class="ic" style="background:#ECFDF5; color:#047857; width:56px; height:56px"><?= icon('check', 24) ?></div>
          <p style="font-size:16px; font-weight:600">All caught up!</p>
          <div class="note">No records pending review right now.</div>
        <?php endif; ?>
      </div>
    </div></div>
  <?php else: ?>
    <?php
      $byDept = [];
      foreach ($records as $r) {
          $k = ($r['department'] ?? '') !== '' ? $r['department'] : 'Unassigned';
          $byDept[$k][] = $r;
      }
      ksort($byDept, SORT_NATURAL | SORT_FLAG_CASE);
      $single = count($byDept) === 1;
    ?>
    <?php foreach ($byDept as $deptName => $deptRecs): ?>
      <details class="card tg-group ap-group"<?= $single ? ' open' : '' ?>>
        <summary class="tg-group-head">
          <span class="tg-dept"><?= icon('building', 15) ?> <?= e($deptName) ?></span>
          <span class="badge badge-info"><?= count($deptRecs) ?> <?= $isHod ? 'under review' : 'pending' ?></span>
          <span class="ap-head-actions">
            <?php if (!$isYearLocked || $user['role'] === 'Admin'): ?>
              <?php if ($user['role'] === 'HoD'): ?>
                <a href="<?= e(url('approvals.php?tab=edit_requests')) ?>" class="btn btn-sm btn-ghost" style="color:#1D4ED8;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                  <?= icon('clock', 13) ?> View Edit Requests
                </a>
              <?php elseif ($user['role'] === 'Coordinator'): ?>
                <button type="button" class="btn btn-sm ap-approve-all"
                  onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)"><?= icon('check', 14) ?> Approve all & Save to DB</button>
              <?php else: ?>
                <button type="button" class="btn btn-sm ap-approve-all"
                  onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)"><?= icon('check', 14) ?> Approve all</button>
              <?php endif; ?>
            <?php endif; ?>
            <span class="tg-chev"><?= icon('chevron', 16) ?></span>
          </span>
        </summary>
        <div class="table-wrap"><table class="data" style="min-width:600px">
          <thead><tr>
            <th style="padding-left:24px">Record</th>
            <th>Type</th>
            <th>Proof</th>
            <th>Status</th>
            <th>Submitted / Note</th>
            <th class="num" style="padding-right:24px">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($deptRecs as $r): ?>
            <tr>
              <td style="padding-left:24px">
                <div style="font-weight:500; max-width:340px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis"><?= e($r['_title']) ?></div>
                <?php $who = $r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? ''; ?>
                <?php if ($who !== ''): ?><div class="card-sub"><?= e($who) ?> &middot; Dept: <?= e($r['department'] ?? 'N/A') ?></div><?php endif; ?>
              </td>
              <td><span class="badge badge-info"><?= e($r['_type_label']) ?></span></td>
              <td>
                <?php if (!empty($r['proof_file'])): ?>
                  <a class="btn btn-ghost btn-sm" href="<?= e(record_proof_url($r['_type_key'] ?? '', (int)$r['id'], $r['proof_file'])) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 14) ?> View</a>
                <?php else: ?>
                  <span class="card-sub">—</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-<?= status_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
              <td class="card-sub" title="<?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?>">
                <?= e(time_ago($r['created_at'])) ?>
                <?php if (!empty($r['review_remark'])): ?>
                  <div style="font-size:11px;color:#1D4ED8;margin-top:2px"><strong>Note:</strong> <?= e($r['review_remark']) ?></div>
                <?php endif; ?>
              </td>
              <td class="num" style="padding-right:24px">
                <?php if (!$isYearLocked || $user['role'] === 'Admin'): ?>
                  <div class="flex gap-2" style="justify-content:flex-end">
                    <?php if ($user['role'] === 'HoD'): ?>
                      <?php if ($r['status'] === 'Edit Requested'): ?>
                        <span class="badge badge-warning" style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:4px 8px;font-weight:600">
                          <?= icon('clock', 12) ?> Edit Request Pending
                        </span>
                        <a href="<?= e(url('approvals.php?tab=edit_requests')) ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:0 6px;color:#1D4ED8" title="View in Edit Requests">View Ticket</a>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm" style="background:#1D4ED8;color:#fff;border:1px solid #1D4ED8;font-weight:600;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(29,78,216,0.25);border-radius:6px;padding:0 12px;height:32px"
                          onclick="openHodEditRequest(<?= htmlspecialchars(json_encode([
                            'id' => (int)$r['id'],
                            'type_key' => $r['_type_key'],
                            'type_label' => $r['_type_label'],
                            'title' => $r['_title'],
                            'faculty_name' => $who,
                            'department' => $r['department'] ?? ($scopeDept ?? ''),
                            'academic_year' => $r['academic_year'] ?? $activeYear,
                          ]), ENT_QUOTES, 'UTF-8') ?>)">
                          <?= icon('edit', 14) ?> Request Edit to Dean/Admin
                        </button>
                      <?php endif; ?>
                    <?php elseif ($user['role'] === 'Coordinator'): ?>
                      <?php if ($r['status'] === 'Unlocked for Edit'): ?>
                        <a class="btn btn-sm" style="background:#EFF6FF;color:#1D4ED8;border-color:#BFDBFE;height:32px;padding:0 10px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px"
                          href="<?= e(url('upload.php?type=' . urlencode($r['_type_key']) . '&edit_id=' . (int)$r['id'])) ?>"><?= icon('edit',14) ?> Edit & Resubmit</a>
                      <?php else: ?>
                        <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                          onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve')"><?= icon('check',14) ?> Approve & Save to DB</button>
                      <?php endif; ?>
                    <?php elseif (in_array($user['role'], ['Dean', 'Admin'], true)): ?>
                      <?php if ($r['status'] === 'Edit Requested'): ?>
                        <?php $recKey = $r['_type_key'] . ':' . $r['id']; ?>
                        <?php if (isset($ticketsByRecord[$recKey])): ?>
                          <button type="button" class="btn btn-sm" style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;height:32px;padding:0 10px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px"
                            onclick="viewTicket(<?= (int) $ticketsByRecord[$recKey]['ticket']['id'] ?>)">
                            <?= icon('clock', 13) ?> Review Edit Request
                          </button>
                        <?php else: ?>
                          <a class="btn btn-sm" style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;height:32px;padding:0 10px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px"
                            href="<?= e(url('approvals.php?tab=edit_requests&status=Pending')) ?>">
                            <?= icon('clock', 13) ?> Review Edit Request
                          </a>
                        <?php endif; ?>
                      <?php else: ?>
                        <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                          onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve')"><?= icon('check',14) ?> Approve</button>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($user['role'] !== 'HoD'): ?>
                      <button class="btn btn-sm" style="background:#FEF2F2;color:#B91C1C;border-color:#FECACA;height:32px;padding:0 10px;font-size:12px"
                        onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'reject')"><?= icon('x',14) ?> Reject</button>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px">
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

    <form method="post" id="bulkForm" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="review_action" value="approve_all">
      <input type="hidden" name="department" id="bulk-dept">
    </form>
  <?php endif; ?>

<!-- TAB 2: EDIT REQUESTS DASHBOARD (VIEWABLE BY ADMIN, DEAN, HOD) -->
<?php else: ?>

  <!-- Stat Counters -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:16px;margin-bottom:24px">
    <div class="card" style="padding:16px;display:flex;align-items:center;gap:14px;border-left:4px solid #3B82F6">
      <div style="width:38px;height:38px;border-radius:8px;background:#EFF6FF;color:#2563EB;display:flex;align-items:center;justify-content:center">
        <?= icon('file-text', 20) ?>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;color:#111827"><?= (int) $editCounts['total'] ?></div>
        <div style="font-size:12px;color:#6B7280;font-weight:500">Total Requests</div>
      </div>
    </div>

    <div class="card" style="padding:16px;display:flex;align-items:center;gap:14px;border-left:4px solid #F59E0B">
      <div style="width:38px;height:38px;border-radius:8px;background:#FFFBEB;color:#D97706;display:flex;align-items:center;justify-content:center">
        <?= icon('clock', 20) ?>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;color:#111827"><?= (int) $editCounts['Pending'] ?></div>
        <div style="font-size:12px;color:#6B7280;font-weight:500">Pending Review</div>
      </div>
    </div>

    <div class="card" style="padding:16px;display:flex;align-items:center;gap:14px;border-left:4px solid #10B981">
      <div style="width:38px;height:38px;border-radius:8px;background:#ECFDF5;color:#059669;display:flex;align-items:center;justify-content:center">
        <?= icon('check-circle', 20) ?>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;color:#111827"><?= (int) $editCounts['Approved'] ?></div>
        <div style="font-size:12px;color:#6B7280;font-weight:500">Approved (Unlocked)</div>
      </div>
    </div>

    <div class="card" style="padding:16px;display:flex;align-items:center;gap:14px;border-left:4px solid #EF4444">
      <div style="width:38px;height:38px;border-radius:8px;background:#FEF2F2;color:#DC2626;display:flex;align-items:center;justify-content:center">
        <?= icon('x-circle', 20) ?>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;color:#111827"><?= (int) $editCounts['Rejected'] ?></div>
        <div style="font-size:12px;color:#6B7280;font-weight:500">Rejected</div>
      </div>
    </div>

    <div class="card" style="padding:16px;display:flex;align-items:center;gap:14px;border-left:4px solid #6366F1">
      <div style="width:38px;height:38px;border-radius:8px;background:#EEF2FF;color:#4F46E5;display:flex;align-items:center;justify-content:center">
        <?= icon('check', 20) ?>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;color:#111827"><?= (int) $editCounts['Completed'] ?></div>
        <div style="font-size:12px;color:#6B7280;font-weight:500">Completed</div>
      </div>
    </div>
  </div>

  <!-- Filter Bar for Edit Requests -->
  <form method="get" class="fbar" style="margin-bottom:20px">
    <input type="hidden" name="tab" value="edit_requests">
    <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

    <label class="fb-field"><span class="fb-k">Status</span>
      <select name="status" onchange="this.form.submit()">
        <option value="all" <?= $filterEditStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
        <option value="Pending" <?= $filterEditStatus === 'Pending' ? 'selected' : '' ?>>Pending (<?= $editCounts['Pending'] ?>)</option>
        <option value="Approved" <?= $filterEditStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
        <option value="Rejected" <?= $filterEditStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        <option value="Completed" <?= $filterEditStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
      </select>
    </label>

    <?php if (user_can_choose_department($user)): ?>
      <label class="fb-field"><span class="fb-k">Department</span>
        <select name="department" onchange="this.form.submit()">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e($d['name']) ?>" <?= $filterEditDept === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

    <label class="fb-field"><span class="fb-k">Academic Year</span>
      <select name="academic_year" onchange="this.form.submit()">
        <option value="">All Years</option>
        <?php foreach ($allYears as $y): ?>
          <option value="<?= e($y) ?>" <?= $filterEditYear === $y ? 'selected' : '' ?>><?= e($y) ?><?= $y === $activeYear ? ' (Active)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field fb-search">
      <?= icon('search', 15) ?>
      <input type="search" name="q" value="<?= e($editSearch) ?>" placeholder="Search ticket #, title, faculty, or reason…" aria-label="Search edit requests">
      <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
    </label>

    <span class="fbar-end">
      <?php if ($filterEditStatus !== 'all' || $filterEditDept || $filterEditYear || $editSearch !== ''): ?>
        <a class="fbar-clear" href="<?= e(url('approvals.php?tab=edit_requests')) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note">Showing all scoped requests</span>
      <?php endif; ?>
    </span>
  </form>

  <!-- Tickets Table -->
  <?php if (empty($editTickets)): ?>
    <div class="card"><div class="card-body" style="padding:0">
      <div class="empty" style="padding:70px 24px">
        <div class="ic" style="width:56px; height:56px; background:#F3F4F6; color:#6B7280"><?= icon('inbox', 24) ?></div>
        <p style="font-size:16px; font-weight:600; margin-top:12px">No edit requests found</p>
        <div class="note">There are currently no tickets matching your filter criteria.</div>
        <?php if ($filterEditStatus !== 'all' || $filterEditDept || $filterEditYear || $editSearch !== ''): ?>
          <div style="margin-top:14px">
            <a href="<?= e(url('approvals.php?tab=edit_requests')) ?>" class="btn btn-outline btn-sm">Clear filters</a>
          </div>
        <?php endif; ?>
      </div>
    </div></div>
  <?php else: ?>
    <div class="card">
      <div class="table-wrap">
        <table class="data" style="min-width:900px">
          <thead>
            <tr>
              <th style="padding-left:20px;width:110px">Ticket ID</th>
              <th style="width:90px">Record ID</th>
              <th>Faculty & Dept</th>
              <th>Record / Category</th>
              <th>Requested By</th>
              <th>Reason & Correction</th>
              <th style="width:110px">Created</th>
              <th style="width:110px">Status</th>
              <th class="num" style="padding-right:20px;width:160px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($editTickets as $t): ?>
              <tr>
                <td style="padding-left:20px">
                  <span style="font-family:monospace;font-weight:700;color:#1D4ED8;background:#EFF6FF;border:1px solid #BFDBFE;padding:3px 8px;border-radius:5px;font-size:12px">
                    #ER-<?= (int) $t['id'] ?>
                  </span>
                </td>
                <td>
                  <span style="font-family:monospace;font-weight:600;color:#4B5563;background:#F3F4F6;padding:2px 6px;border-radius:4px;font-size:12px">
                    #<?= (int) $t['record_id'] ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight:600;color:#111827"><?= e($t['faculty_name'] ?: 'Faculty Member') ?></div>
                  <div class="card-sub" style="font-size:11.5px"><?= e($t['department']) ?> &middot; <span style="color:#4B5563"><?= e($t['academic_year']) ?></span></div>
                </td>
                <td>
                  <span class="badge badge-info" style="font-size:10.5px;margin-bottom:2px"><?= e($t['category'] ?: 'Record') ?></span>
                  <div style="font-weight:500;font-size:12.5px;max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151" title="<?= e($t['record_title']) ?>">
                    <?= e($t['record_title'] ?: '(untitled)') ?>
                  </div>
                </td>
                <td>
                  <div style="font-weight:500;font-size:12.5px;color:#111827"><?= e($t['requester_name'] ?: 'HOD') ?></div>
                  <div class="card-sub" style="font-size:11px">HOD</div>
                </td>
                <td>
                  <div style="max-width:260px">
                    <div style="font-size:12px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($t['reason']) ?>">
                      <strong style="color:#B45309">Reason:</strong> <?= e($t['reason']) ?>
                    </div>
                    <div style="font-size:11.5px;color:#4B5563;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px" title="<?= e($t['correction']) ?>">
                      <strong style="color:#047857">Correction:</strong> <?= e($t['correction']) ?>
                    </div>
                  </div>
                </td>
                <td class="card-sub" style="font-size:11.5px" title="<?= e(date('d M Y, h:i A', strtotime($t['created_at']))) ?>">
                  <?= e(time_ago($t['created_at'])) ?>
                </td>
                <td>
                  <span class="badge badge-<?= status_class($t['status']) ?>" style="font-size:11px">
                    <?= e($t['status']) ?>
                  </span>
                  <?php if ($t['status'] === 'Approved'): ?>
                    <div style="font-size:10px;color:#047857;margin-top:2px">Unlocked for Edit</div>
                  <?php endif; ?>
                </td>
                <td class="num" style="padding-right:20px">
                  <div class="flex gap-1" style="justify-content:flex-end">
                    <button type="button" class="btn btn-ghost btn-sm" style="font-size:12px;padding:0 8px;height:30px"
                      onclick="viewTicket(<?= (int) $t['id'] ?>)" title="View full details and original record">
                      <?= icon('eye', 13) ?> View
                    </button>

                    <?php if ($canProcess && $t['status'] === 'Pending'): ?>
                      <button type="button" class="btn btn-sm" style="background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;font-size:12px;padding:0 8px;height:30px;font-weight:600"
                        onclick="openProcessModal(<?= (int) $t['id'] ?>, 'approve', <?= json_encode($t['faculty_name'] ?? 'Faculty') ?>, <?= json_encode($t['category'] ?? 'Record') ?>)" title="Approve and unlock record for Coordinator">
                        <?= icon('check', 13) ?> Allow
                      </button>
                      <button type="button" class="btn btn-sm" style="background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;font-size:12px;padding:0 8px;height:30px"
                        onclick="openProcessModal(<?= (int) $t['id'] ?>, 'reject', <?= json_encode($t['faculty_name'] ?? 'Faculty') ?>, <?= json_encode($t['category'] ?? 'Record') ?>)" title="Reject request">
                        <?= icon('x', 13) ?> Reject
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<style>
  .ap-head-actions { margin-left:auto; display:inline-flex; align-items:center; gap:12px; }
  .ap-approve-all { background:#ECFDF5; color:#047857; border-color:#A7F3D0; height:32px; padding:0 12px; font-size:12px; }
  .ap-approve-all:hover { background:#D1FAE5; border-color:#6EE7B7; }
</style>

<!-- Dedicated HOD Request Edit Modal -->
<dialog class="modal" id="hodEditModal" style="max-width:36rem;border-radius:12px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04)">
  <form method="post" id="hodEditForm">
    <?= csrf_field() ?>
    <input type="hidden" name="review_action" value="request_edit">
    <input type="hidden" name="record_type" id="hod-rec-type">
    <input type="hidden" name="record_id" id="hod-rec-id">
    <div class="modal-head" style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #E5E7EB;padding:16px 20px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:34px;height:34px;border-radius:8px;background:#EFF6FF;color:#1D4ED8;display:flex;align-items:center;justify-content:center">
          <?= icon('edit', 18) ?>
        </div>
        <div>
          <h3 style="margin:0;font-size:16px;font-weight:700;color:#111827">Request Edit to Dean/Admin</h3>
          <div style="font-size:12px;color:#6B7280;margin-top:2px">Submit a structured edit request ticket for Dean or Admin review</div>
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('dialog').close()" style="padding:4px 8px;color:#9CA3AF">✕</button>
    </div>
    <div class="modal-body" style="padding:20px">
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 16px;margin-bottom:18px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px">
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Record ID</span>
            <strong id="hod-disp-id" style="color:#1F2937;font-family:monospace">#--</strong>
          </div>
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Academic Year</span>
            <strong id="hod-disp-year" style="color:#1F2937">--</strong>
          </div>
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Faculty / Candidate</span>
            <span id="hod-disp-faculty" style="color:#1F2937;font-weight:500">--</span>
          </div>
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Department</span>
            <span id="hod-disp-dept" style="color:#1F2937;font-weight:500">--</span>
          </div>
          <div style="grid-column:1 / -1;border-top:1px dashed #E5E7EB;padding-top:8px;margin-top:2px">
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Category & Title</span>
            <div style="display:flex;align-items:center;gap:6px;margin-top:2px">
              <span id="hod-disp-cat" class="badge badge-info" style="font-size:11px">Category</span>
              <span id="hod-disp-title" style="color:#111827;font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">--</span>
            </div>
          </div>
        </div>
      </div>

      <div class="field" style="margin-bottom:14px">
        <label style="font-weight:600;font-size:13px;color:#374151;display:block;margin-bottom:6px">
          Reason for Edit <span style="color:#DC2626">*</span>
        </label>
        <textarea class="input" name="reason" id="hod-in-reason" rows="3" required placeholder="Explain why this record cannot be approved as-is (e.g., incorrect date, missing co-authors, wrong indexing, proof mismatch)..." style="width:100%;box-sizing:border-box;resize:vertical;font-size:13px;line-height:1.5"></textarea>
      </div>

      <div class="field" style="margin-bottom:14px">
        <label style="font-weight:600;font-size:13px;color:#374151;display:block;margin-bottom:6px">
          Requested Correction <span style="color:#DC2626">*</span>
        </label>
        <textarea class="input" name="correction" id="hod-in-correction" rows="3" required placeholder="Specify the exact correction needed (e.g., update publication date to March 2025, re-upload page 2 of certificate, change journal name)..." style="width:100%;box-sizing:border-box;resize:vertical;font-size:13px;line-height:1.5"></textarea>
      </div>

      <div class="field" style="margin-bottom:4px">
        <label style="font-weight:600;font-size:13px;color:#374151;display:block;margin-bottom:6px">
          HOD Comments <span style="color:#6B7280;font-weight:400;font-size:12px">(Optional)</span>
        </label>
        <textarea class="input" name="hod_comments" id="hod-in-comments" rows="2" placeholder="Any additional notes or guidance for the Dean/Admin review..." style="width:100%;box-sizing:border-box;resize:vertical;font-size:13px;line-height:1.5"></textarea>
      </div>
    </div>
    <div class="modal-foot" style="border-top:1px solid #E5E7EB;padding:14px 20px;display:flex;justify-content:flex-end;gap:10px;background:#F9FAFB;border-bottom-left-radius:12px;border-bottom-right-radius:12px">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm" style="background:#1D4ED8;border-color:#1D4ED8;font-weight:600;display:inline-flex;align-items:center;gap:6px">
        <?= icon('send', 14) ?> Submit Request to Dean/Admin
      </button>
    </div>
  </form>
</dialog>

<!-- Record Review dialog (for Coordinator, Admin direct approve/reject) -->
<dialog class="modal" id="reviewDlg" style="max-width:32rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="record_type" id="rv-type">
    <input type="hidden" name="record_id" id="rv-id">
    <input type="hidden" name="review_action" id="rv-action">
    <div class="modal-head"><div><h3 id="rv-title">Review Record</h3></div></div>
    <div class="modal-body">
      <div class="field">
        <label id="rv-remark-label">Explanation / Remark</label>
        <textarea class="input" name="review_remark" id="rv-remark" rows="3" placeholder="Add remark or instructions…"></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm" id="rv-btn">Confirm</button>
    </div>
  </form>
</dialog>

<!-- Ticket Details & Original Record Modal (for Admin, Dean, and HoD) -->
<dialog class="modal" id="ticketDetailsModal" style="max-width:44rem;border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)">
  <div class="modal-head" style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #E5E7EB;padding:16px 20px">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:36px;height:36px;border-radius:8px;background:#EFF6FF;color:#1D4ED8;display:flex;align-items:center;justify-content:center">
        <?= icon('file-text', 18) ?>
      </div>
      <div>
        <h3 id="dt-ticket-title" style="margin:0;font-size:16px;font-weight:700;color:#111827">Ticket Details</h3>
        <div id="dt-ticket-sub" style="font-size:12px;color:#6B7280;margin-top:2px">Structured Edit Request Overview</div>
      </div>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('dialog').close()" style="padding:4px 8px;color:#9CA3AF">✕</button>
  </div>

  <div class="modal-body" style="padding:20px;max-height:75vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 16px;margin-bottom:16px">
      <div>
        <div style="font-size:11px;text-transform:uppercase;color:#6B7280;font-weight:600">Ticket Identifier</div>
        <div id="dt-ticket-id" style="font-size:16px;font-weight:700;color:#1D4ED8;font-family:monospace">#ER---</div>
      </div>
      <div>
        <div style="font-size:11px;text-transform:uppercase;color:#6B7280;font-weight:600;text-align:center">Ticket Status</div>
        <div id="dt-ticket-status" style="margin-top:2px">--</div>
      </div>
      <div>
        <div style="font-size:11px;text-transform:uppercase;color:#6B7280;font-weight:600;text-align:right">Submitted Date</div>
        <div id="dt-ticket-date" style="font-size:12.5px;color:#374151;font-weight:500;text-align:right">--</div>
      </div>
    </div>

    <div style="margin-bottom:18px">
      <h4 style="font-size:13px;font-weight:700;color:#1F2937;margin:0 0 10px 0;display:flex;align-items:center;gap:6px">
        <?= icon('alert-circle', 15) ?> HOD Edit Request Information
      </h4>
      <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:12px 16px;margin-bottom:12px">
        <div style="font-size:11.5px;font-weight:700;color:#92400E;text-transform:uppercase;margin-bottom:4px">Reason for Edit</div>
        <div id="dt-ticket-reason" style="font-size:13px;color:#78350F;line-height:1.5;white-space:pre-wrap">--</div>
      </div>
      <div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;padding:12px 16px;margin-bottom:12px">
        <div style="font-size:11.5px;font-weight:700;color:#065F46;text-transform:uppercase;margin-bottom:4px">Requested Correction</div>
        <div id="dt-ticket-correction" style="font-size:13px;color:#064E3B;line-height:1.5;white-space:pre-wrap">--</div>
      </div>
      <div id="dt-box-hod-comments" style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:12px 16px;display:none">
        <div style="font-size:11.5px;font-weight:700;color:#374151;text-transform:uppercase;margin-bottom:4px">HOD Comments / Notes</div>
        <div id="dt-ticket-hod-comments" style="font-size:13px;color:#1F2937;line-height:1.5;white-space:pre-wrap">--</div>
      </div>
    </div>

    <div style="margin-bottom:18px">
      <h4 style="font-size:13px;font-weight:700;color:#1F2937;margin:0 0 10px 0;display:flex;align-items:center;gap:6px">
        <?= icon('file', 15) ?> Original Record Review
      </h4>
      <div style="background:#FFFFFF;border:1px solid #E5E7EB;border-radius:8px;padding:14px 16px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:12.5px;margin-bottom:12px">
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Record ID</span>
            <strong id="dt-rec-id" style="font-family:monospace;color:#111827">#--</strong>
          </div>
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Category</span>
            <span id="dt-rec-cat" class="badge badge-info" style="font-size:11px">Category</span>
          </div>
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Faculty / Candidate</span>
            <span id="dt-rec-faculty" style="color:#111827;font-weight:600">--</span>
          </div>
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Department & Year</span>
            <span id="dt-rec-dept-year" style="color:#111827">--</span>
          </div>
        </div>

        <div style="border-top:1px solid #F3F4F6;padding-top:10px;margin-top:6px">
          <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Record Title</span>
          <div id="dt-rec-title" style="font-size:13.5px;font-weight:600;color:#111827;margin-top:2px;line-height:1.4">--</div>
        </div>

        <div id="dt-rec-proof-box" style="margin-top:12px;border-top:1px solid #F3F4F6;padding-top:10px;display:flex;align-items:center;justify-content:space-between">
          <div>
            <span style="color:#6B7280;font-size:11px;text-transform:uppercase;font-weight:600;display:block">Proof Document</span>
            <span id="dt-rec-proof-name" style="font-size:12px;color:#4B5563">Attached Document</span>
          </div>
          <a id="dt-rec-proof-link" href="#" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="font-size:12px;display:inline-flex;align-items:center;gap:5px">
            <?= icon('paperclip', 13) ?> View Attachment
          </a>
        </div>
      </div>
    </div>

    <div id="dt-box-processed" style="display:none;background:#F8FAFC;border:1px solid #CBD5E1;border-radius:8px;padding:12px 16px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <div style="font-size:12px;font-weight:700;color:#1E293B;display:flex;align-items:center;gap:6px">
          <?= icon('check-circle', 14) ?> Resolution Details
        </div>
        <div id="dt-proc-time" style="font-size:11.5px;color:#64748B">--</div>
      </div>
      <div style="font-size:12px;color:#334155;margin-bottom:4px">
        Processed by: <strong id="dt-proc-by">--</strong>
      </div>
      <div id="dt-box-admin-comments" style="margin-top:6px;font-size:12.5px;color:#1E293B">
        <strong>Comments:</strong> <span id="dt-proc-comments">--</span>
      </div>
    </div>
  </div>

  <div class="modal-foot" style="border-top:1px solid #E5E7EB;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;background:#F9FAFB;border-bottom-left-radius:12px;border-bottom-right-radius:12px">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Close</button>
    <div id="dt-foot-actions" style="display:flex;gap:8px"></div>
  </div>
</dialog>

<!-- Process Ticket Modal (for Dean / Admin on Approvals page) -->
<dialog class="modal" id="processModal" style="max-width:32rem;border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)">
  <form method="post" id="processForm">
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="edit_requests">
    <input type="hidden" name="process_action" id="proc-action" value="">
    <input type="hidden" name="ticket_id" id="proc-ticket-id" value="">

    <div class="modal-head" style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #E5E7EB;padding:16px 20px">
      <div style="display:flex;align-items:center;gap:10px">
        <div id="proc-icon-box" style="width:34px;height:34px;border-radius:8px;background:#ECFDF5;color:#047857;display:flex;align-items:center;justify-content:center">
          <?= icon('check', 18) ?>
        </div>
        <div>
          <h3 id="proc-modal-title" style="margin:0;font-size:16px;font-weight:700;color:#111827">Process Edit Request</h3>
          <div id="proc-modal-sub" style="font-size:12px;color:#6B7280;margin-top:2px">Confirm action on this ticket</div>
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('dialog').close()" style="padding:4px 8px;color:#9CA3AF">✕</button>
    </div>

    <div class="modal-body" style="padding:20px">
      <div id="proc-prompt-box" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#166534">
        Approving this ticket will unlock the underlying record and set its status to <strong>Unlocked for Edit</strong>. The Department Coordinator will be able to revise and resubmit the record.
      </div>

      <div class="field" style="margin-bottom:4px">
        <label id="proc-comments-label" style="font-weight:600;font-size:13px;color:#374151;display:block;margin-bottom:6px">
          Dean / Administrator Comments
        </label>
        <textarea class="input" name="admin_comments" id="proc-comments" rows="3" placeholder="Add instructions for the coordinator or explanation for this decision…" style="width:100%;box-sizing:border-box;resize:vertical;font-size:13px;line-height:1.5"></textarea>
      </div>
    </div>

    <div class="modal-foot" style="border-top:1px solid #E5E7EB;padding:14px 20px;display:flex;justify-content:flex-end;gap:10px;background:#F9FAFB;border-bottom-left-radius:12px;border-bottom-right-radius:12px">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-sm" id="proc-submit-btn" style="background:#047857;color:#fff;font-weight:600;border:none">
        Confirm
      </button>
    </div>
  </form>
</dialog>

<script>
const currentRole = <?= json_encode($user['role']) ?>;
const canProcess = <?= json_encode($canProcess) ?>;
const ticketData = <?= json_encode($ticketDetails) ?>;
const ticketsByRecord = <?= json_encode($ticketsByRecord) ?>;

function openHodEditRequest(rec) {
  document.getElementById('hod-rec-type').value = rec.type_key || '';
  document.getElementById('hod-rec-id').value = rec.id || '';
  document.getElementById('hod-disp-id').textContent = '#' + (rec.id || '');
  document.getElementById('hod-disp-year').textContent = rec.academic_year || <?= json_encode($activeYear) ?>;
  document.getElementById('hod-disp-faculty').textContent = rec.faculty_name || 'Faculty Member';
  document.getElementById('hod-disp-dept').textContent = rec.department || <?= json_encode($user['department'] ?? 'Department') ?>;
  document.getElementById('hod-disp-cat').textContent = rec.type_label || 'Record';
  document.getElementById('hod-disp-title').textContent = rec.title || 'Untitled';
  document.getElementById('hod-in-reason').value = '';
  document.getElementById('hod-in-correction').value = '';
  document.getElementById('hod-in-comments').value = '';
  document.getElementById('hodEditModal').showModal();
}

function reviewRecord(type, id, action) {
  document.getElementById('rv-type').value = type;
  document.getElementById('rv-id').value = id;
  document.getElementById('rv-action').value = action;
  
  const remarkLabel = document.getElementById('rv-remark-label');
  const remarkInput = document.getElementById('rv-remark');
  const btn = document.getElementById('rv-btn');

  if (action === 'reject') {
    document.getElementById('rv-title').textContent = 'Reject Record';
    remarkLabel.textContent = 'Rejection Reason (Optional)';
    remarkInput.placeholder = 'State reason for rejection…';
    remarkInput.required = false;
    btn.textContent = 'Reject Record';
    btn.className = 'btn btn-danger btn-sm';
    btn.style.cssText = '';
  } else {
    document.getElementById('rv-title').textContent = currentRole === 'Coordinator' ? 'Approve & Save to Database' : 'Approve Record';
    remarkLabel.textContent = 'Remark (Optional)';
    remarkInput.placeholder = 'Add a note…';
    remarkInput.required = false;
    btn.textContent = currentRole === 'Coordinator' ? 'Approve & Save to DB' : 'Approve';
    btn.className = 'btn btn-sm';
    btn.style.cssText = 'background:#047857;color:#fff';
  }

  document.getElementById('reviewDlg').showModal();
}

function approveAll(ev, dept, n) {
  ev.preventDefault();
  ev.stopPropagation();
  let msg = 'Approve all ' + n + ' pending record' + (n === 1 ? '' : 's') + ' in ' + dept + '?';
  if (currentRole === 'Coordinator') {
    msg = 'Approve and save all ' + n + ' pending record' + (n === 1 ? '' : 's') + ' in ' + dept + ' directly to the database?';
  }
  if (!confirm(msg)) return;
  document.getElementById('bulk-dept').value = dept;
  document.getElementById('bulkForm').submit();
}

function viewTicket(id) {
  let item = ticketData[id];
  if (!item) {
    for (let k in ticketsByRecord) {
      if (ticketsByRecord[k].ticket && ticketsByRecord[k].ticket.id == id) {
        item = ticketsByRecord[k];
        break;
      }
    }
  }
  if (!item) return;

  const t = item.ticket;
  const orig = item.orig;

  document.getElementById('dt-ticket-title').textContent = 'Edit Request #ER-' + t.id;
  document.getElementById('dt-ticket-id').textContent = '#ER-' + t.id;
  
  const statusEl = document.getElementById('dt-ticket-status');
  let badgeClass = 'badge-info';
  if (t.status === 'Pending') badgeClass = 'badge-warning';
  else if (t.status === 'Approved') badgeClass = 'badge-success';
  else if (t.status === 'Rejected') badgeClass = 'badge-danger';
  else if (t.status === 'Completed') badgeClass = 'badge-info';
  statusEl.innerHTML = '<span class="badge ' + badgeClass + '">' + t.status + '</span>';

  document.getElementById('dt-ticket-date').textContent = t.created_at || 'Recently';
  document.getElementById('dt-ticket-reason').textContent = t.reason || '--';
  document.getElementById('dt-ticket-correction').textContent = t.correction || '--';

  const hodCommBox = document.getElementById('dt-box-hod-comments');
  if (t.hod_comments && t.hod_comments.trim() !== '') {
    document.getElementById('dt-ticket-hod-comments').textContent = t.hod_comments;
    hodCommBox.style.display = 'block';
  } else {
    hodCommBox.style.display = 'none';
  }

  document.getElementById('dt-rec-id').textContent = '#' + t.record_id;
  document.getElementById('dt-rec-cat').textContent = t.category || (orig ? orig._type_label : 'Record');
  document.getElementById('dt-rec-faculty').textContent = t.faculty_name || (orig ? (orig.faculty_name || orig.candidate_name || 'Faculty') : 'Faculty');
  document.getElementById('dt-rec-dept-year').textContent = (t.department || '') + ' · ' + (t.academic_year || '');
  document.getElementById('dt-rec-title').textContent = t.record_title || (orig ? orig._title : '(untitled)');

  const proofBox = document.getElementById('dt-rec-proof-box');
  if (orig && orig.proof_file) {
    proofBox.style.display = 'flex';
    document.getElementById('dt-rec-proof-name').textContent = orig.proof_file;
    document.getElementById('dt-rec-proof-link').href = <?= json_encode(url('proof.php?file=')) ?> + encodeURIComponent(orig.proof_file);
  } else {
    proofBox.style.display = 'none';
  }

  const procBox = document.getElementById('dt-box-processed');
  if (t.status !== 'Pending' && (t.processed_by || t.admin_comments)) {
    procBox.style.display = 'block';
    document.getElementById('dt-proc-by').textContent = (t.processor_name || 'Admin/Dean') + (t.processor_role ? ' (' + t.processor_role + ')' : '');
    document.getElementById('dt-proc-time').textContent = t.processed_at || '';
    if (t.admin_comments && t.admin_comments.trim() !== '') {
      document.getElementById('dt-proc-comments').textContent = t.admin_comments;
      document.getElementById('dt-box-admin-comments').style.display = 'block';
    } else {
      document.getElementById('dt-box-admin-comments').style.display = 'none';
    }
  } else {
    procBox.style.display = 'none';
  }

  const footActions = document.getElementById('dt-foot-actions');
  footActions.innerHTML = '';
  if (canProcess && t.status === 'Pending') {
    const btnApprove = document.createElement('button');
    btnApprove.type = 'button';
    btnApprove.className = 'btn btn-sm';
    btnApprove.style.cssText = 'background:#047857;color:#fff;border:none;font-weight:600';
    btnApprove.textContent = 'Approve / Allow Edit';
    btnApprove.onclick = function() {
      document.getElementById('ticketDetailsModal').close();
      openProcessModal(t.id, 'approve', t.faculty_name, t.category);
    };

    const btnReject = document.createElement('button');
    btnReject.type = 'button';
    btnReject.className = 'btn btn-danger btn-sm';
    btnReject.textContent = 'Reject Request';
    btnReject.onclick = function() {
      document.getElementById('ticketDetailsModal').close();
      openProcessModal(t.id, 'reject', t.faculty_name, t.category);
    };

    footActions.appendChild(btnApprove);
    footActions.appendChild(btnReject);
  }

  document.getElementById('ticketDetailsModal').showModal();
}

function openProcessModal(id, action, facultyName, category) {
  document.getElementById('proc-ticket-id').value = id;
  document.getElementById('proc-action').value = action;
  const promptBox = document.getElementById('proc-prompt-box');
  const titleEl = document.getElementById('proc-modal-title');
  const iconBox = document.getElementById('proc-icon-box');
  const submitBtn = document.getElementById('proc-submit-btn');
  const commentsInput = document.getElementById('proc-comments');
  const commentsLabel = document.getElementById('proc-comments-label');

  commentsInput.value = '';

  if (action === 'approve') {
    titleEl.textContent = 'Approve Edit Request #ER-' + id;
    iconBox.style.background = '#ECFDF5';
    iconBox.style.color = '#047857';
    iconBox.innerHTML = <?= json_encode(icon('check', 18)) ?>;
    promptBox.style.background = '#F0FDF4';
    promptBox.style.border = '1px solid #BBF7D0';
    promptBox.style.color = '#166534';
    promptBox.innerHTML = 'Approving ticket <strong>#ER-' + id + '</strong> will unlock the record for <strong>' + (facultyName || 'Faculty') + '</strong> and set status to <strong>Unlocked for Edit</strong>. The Department Coordinator will be able to edit and resubmit it.';
    commentsLabel.innerHTML = 'Instructions for Coordinator / Faculty <span style="font-weight:400;color:#6B7280">(Optional)</span>';
    submitBtn.className = 'btn btn-sm';
    submitBtn.style.cssText = 'background:#047857;color:#fff;border:none;font-weight:600';
    submitBtn.textContent = 'Approve & Unlock Record';
  } else {
    titleEl.textContent = 'Reject Edit Request #ER-' + id;
    iconBox.style.background = '#FEF2F2';
    iconBox.style.color = '#DC2626';
    iconBox.innerHTML = <?= json_encode(icon('x', 18)) ?>;
    promptBox.style.background = '#FEF2F2';
    promptBox.style.border = '1px solid #FECACA';
    promptBox.style.color = '#991B1B';
    promptBox.innerHTML = 'Rejecting ticket <strong>#ER-' + id + '</strong> will keep the record in its existing approved status without allowing edits.';
    commentsLabel.innerHTML = 'Rejection Reason / Comments <span style="font-weight:400;color:#6B7280">(Recommended)</span>';
    submitBtn.className = 'btn btn-danger btn-sm';
    submitBtn.style.cssText = '';
    submitBtn.textContent = 'Reject Request';
  }

  document.getElementById('processModal').showModal();
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
