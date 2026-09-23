<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';      
require_once __DIR__ . '/models/FacultyAchievement.php';

$user = require_role(['Faculty', 'Coordinator', 'HoD']);

// The subject of the report is the session user — never a request parameter.
$facultyId = (int) $user['id'];

$academicYear = active_academic_year();

$data    = faculty_achievement_details($facultyId, $academicYear);
$faculty = $data['faculty'];
$records = $data['records'];
$summary = $data['summary'];

if (!$faculty) {
    http_response_code(404);
    $pageTitle  = 'Dean Report';
    $breadcrumb = 'Dean Report';
    require __DIR__ . '/inc/header.php';
    echo '<div class="alert alert-danger mt-4">Your faculty record could not be loaded. Please contact the IQAC administrator.</div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

$totalAchievements = count($records);

$pageTitle  = 'Dean Report';
$breadcrumb = 'Dean Report';
require __DIR__ . '/inc/header.php';
?>

<!-- Page header -->
<div class="page-head">
  <div>
    <h1>Dean Report</h1>
    <div class="sub">
      Faculty achievement report for the active academic year
      &middot; <?= e($faculty['name']) ?>
      &middot; Academic Year: <?= e($academicYear) ?>
    </div>
  </div>

  <div class="actions flex gap-2 items-center no-print" style="flex-wrap:wrap;">
    <a class="btn btn-secondary btn-sm" href="<?= e(url('individual-faculty-report.php')) ?>" title="Back to my achievements">
      <?= icon('arrow-left', 14) ?> Back to Achievements
    </a>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()" title="Print or save this report as PDF">
      <?= icon('file-text', 14) ?> Print Report
    </button>
  </div>
</div>

<!-- Faculty identity -->
<div class="card mt-4">
  <div class="card-body" style="padding:20px 24px;">
    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
      <div class="avatar-dark" style="width:54px; height:54px; border-radius:50%; background:#131D3B; color:#fff; display:grid; place-items:center; font-weight:700; font-size:18px;">
        <?= e(initials($faculty['name'])) ?>
      </div>
      <div style="flex:1; min-width:220px;">
        <div style="font-size:20px; font-weight:700; color:var(--ink,#131D3B);"><?= e($faculty['name']) ?></div>
        <div style="font-size:13px; color:var(--ink-muted,#5A6785); margin-top:2px;">
          <?= e($faculty['designation']) ?> &middot; Department of <?= e(department_full_name($faculty['department'])) ?>
        </div>
      </div>
      <div style="display:flex; gap:20px; flex-wrap:wrap; background:var(--navy-50,#F4F6FA); padding:10px 16px; border-radius:10px; border:1px solid var(--hairline,#E4E9F2);">
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Employee ID</div>
          <div style="font-weight:700; color:var(--ink,#131D3B);"><?= e($faculty['employee_id']) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Department</div>
          <div style="font-weight:700; color:var(--ink,#131D3B);"><?= e($faculty['department']) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Academic Year</div>
          <div style="font-weight:700; color:var(--brand,#FF4F01);"><?= e($academicYear) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Total Achievements</div>
          <div style="font-weight:700; color:var(--ink,#131D3B);"><?= (int) $totalAchievements ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Achievements -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title"><?= icon('award', 16) ?> Faculty Achievements</div>
      <div class="card-sub">
        <?= (int) $totalAchievements ?> achievement<?= $totalAchievements === 1 ? '' : 's' ?>
        recorded for academic year <?= e($academicYear) ?>
      </div>
    </div>
  </div>

  <div class="card-body pt-0">
    <?php if (empty($records)): ?>
      <div class="empty">
        <div class="ic"><?= icon('award', 20) ?></div>
        <p>No achievements available for the active academic year.</p>
        <div class="note">
          Records you upload for <?= e($academicYear) ?> will appear here automatically.
        </div>
      </div>
    <?php else: ?>
      <!-- Category counts -->
      <div style="display:flex; flex-wrap:wrap; gap:8px; margin:14px 0 18px;">
        <?php foreach ($summary as $catLabel => $cnt): ?>
          <span class="badge badge-neutral" style="font-size:12px; padding:4px 10px;">
            <strong><?= e($catLabel) ?>:</strong> <?= (int) $cnt ?>
          </span>
        <?php endforeach; ?>
      </div>

      <!-- Every individual achievement, numbered -->
      <div class="table-wrap">
        <table class="data wide">
          <thead>
            <tr>
              <th style="width:44px;">#</th>
              <th>Achievement Title / Details</th>
              <th style="width:180px;">Category</th>
              <th style="width:110px;">Status</th>
              <th style="width:110px;">Submitted On</th>
              <th style="width:100px;">Proof</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $idx => $item): ?>
              <?php
                $pfile = trim((string)($item['proof_file'] ?? ''));
                $pType = $item['type_key'] ?? '';
                $pId   = (int)($item['id'] ?? 0);
                $meta  = ($pfile !== '') ? record_proof_meta($pType, $pId, $pfile) : null;
              ?>
              <tr>
                <td class="faint tabular"><?= $idx + 1 ?></td>
                <td class="fw-500" style="max-width:460px;">
                  <div style="color:var(--ink,#131D3B); font-weight:600;"><?= e($item['title']) ?></div>
                  <?php if (!empty($item['raw']['journal_name'])): ?>
                    <div class="card-sub">Journal: <?= e($item['raw']['journal_name']) ?></div>
                  <?php elseif (!empty($item['raw']['publisher_name'])): ?>
                    <div class="card-sub">Publisher: <?= e($item['raw']['publisher_name']) ?></div>
                  <?php elseif (!empty($item['raw']['conference_name'])): ?>
                    <div class="card-sub">Conference: <?= e($item['raw']['conference_name']) ?></div>
                  <?php elseif (!empty($item['raw']['event_title'])): ?>
                    <div class="card-sub">Event: <?= e($item['raw']['event_title']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-neutral" style="font-size:11.5px;"><?= e($item['category']) ?></span></td>
                <td><span class="badge badge-<?= e(status_class($item['status'])) ?>"><?= e($item['status']) ?></span></td>
                <td class="card-sub">
                  <?php $ts = strtotime((string) $item['created_at']); ?>
                  <?= $ts ? date('d/m/Y', $ts) : '—' ?>
                </td>
                <td class="c">
                  <?php if ($meta): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($meta['view_url']) ?>" target="_blank" rel="noopener">
                      <?= icon('paperclip', 13) ?> Proof
                    </a>
                  <?php else: ?>
                    <span class="faint">—</span>
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

<style>
  /* Printing this page hands the Dean the report alone — no app chrome. */
  @media print {
    .sidebar, .topbar, .no-print, .alert { display: none !important; }
    .main, .content, .container { margin: 0 !important; padding: 0 !important; max-width: none !important; }
    .card { box-shadow: none !important; border-color: #ccc !important; break-inside: avoid; }
  }
</style>

<?php require __DIR__ . '/inc/footer.php'; ?>
