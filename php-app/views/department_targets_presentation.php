<?php
/**
 * Department-Specific Targets Presentation View
 * ATTS IQAC Portal — Mohamed Sathak Engineering College
 *
 * Implements the 35-slide Department-Specific Target Presentation for HoD:
 * Slide 1: Department Targets Overall Summary
 * Slide 2: Target Fixed vs Target Achieved
 * Slide 3: Department Target Performance
 * Slide 4: Department Faculty Contribution
 * Slides 5–34: All 30 Institutional Academic Target Sections (Department-Specific)
 * Slide 35: Final Department Target Performance Summary
 */

declare(strict_types=1);

// Ensure required variables are available
$department           = $department ?? 'Department';
$academicYear         = $academicYear ?? active_academic_year();
$summary              = $summary ?? [];
$sections30           = $sections30 ?? [];
$facultyContributions = $facultyContributions ?? [];
$attentionCategories  = $attentionCategories ?? [];
$totalUploadedRecords = $totalUploadedRecords ?? 0;
?>

<!-- =================================================================== -->
<!-- SLIDE 1: DEPARTMENT TARGETS OVERALL SUMMARY                         -->
<!-- =================================================================== -->
<section class="present-slide active" data-slide-index="1">
  <div class="slide-header" style="margin-bottom:18px;">
    <div class="slide-title-wrap">
      <div style="font-size:12px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:4px;">
        <?= e($department) ?> &middot; DEPARTMENT QUALITY ASSURANCE &middot; MSEC
      </div>
      <h2 style="font-size:24px; font-weight:800; color:var(--navy);">
        <?= icon('target', 24) ?> <?= e($department) ?> &mdash; DEPARTMENT TARGETS OVERALL SUMMARY
      </h2>
      <p>Department: <strong><?= e($department) ?></strong> &middot; Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Department-specific benchmarks, verified faculty uploads, and achievement status</p>
    </div>
    <div class="slide-badge-wrap">
      <span class="badge-pill brand"><?= icon('building', 13) ?> Department: <?= e($department) ?></span>
      <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year: <?= e($academicYear) ?></span>
      <span class="badge-pill success"><?= icon('shield', 13) ?> HoD Scope</span>
    </div>
  </div>

  <!-- 4 Prominent KPI Cards -->
  <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:20px;">
    <div class="kpi-card accent-navy" style="padding:22px 18px;">
      <div class="kpi-top">
        <span class="kpi-label">Total Department Targets</span>
        <div class="kpi-icon navy"><?= icon('target', 18) ?></div>
      </div>
      <div class="kpi-val"><?= (int) ($summary['total_targets'] ?? 0) ?></div>
      <div class="kpi-sub">Department benchmarks configured for <?= e($academicYear) ?></div>
    </div>

    <div class="kpi-card accent-green" style="padding:22px 18px;">
      <div class="kpi-top">
        <span class="kpi-label">Targets Achieved</span>
        <div class="kpi-icon green"><?= icon('check', 18) ?></div>
      </div>
      <div class="kpi-val" style="color:var(--success);"><?= (int) ($summary['achieved_targets'] ?? 0) ?></div>
      <div class="kpi-sub"><?= $summary['targets_met_pct'] ?? 0 ?>% of department targets fulfilled</div>
    </div>

    <div class="kpi-card accent-orange" style="padding:22px 18px;">
      <div class="kpi-top">
        <span class="kpi-label">Pending / In Progress</span>
        <div class="kpi-icon orange"><?= icon('clock', 18) ?></div>
      </div>
      <div class="kpi-val" style="color:var(--orange);"><?= (int) ($summary['in_progress_targets'] ?? 0) ?></div>
      <div class="kpi-sub">Active cycles advancing toward targets</div>
    </div>

    <div class="kpi-card accent-red" style="padding:22px 18px;">
      <div class="kpi-top">
        <span class="kpi-label">Not Completed</span>
        <div class="kpi-icon red"><?= icon('alert-triangle', 18) ?></div>
      </div>
      <div class="kpi-val" style="color:var(--danger);"><?= (int) ($summary['not_completed_targets'] ?? 0) ?></div>
      <div class="kpi-sub">Benchmarks incomplete where official deadline elapsed</div>
    </div>
  </div>

  <!-- Quantitative Aggregate Metrics & Status Rules -->
  <div class="split-grid-2">
    <div class="panel-card">
      <div class="panel-head">
        <span class="panel-title"><?= icon('bar-chart', 16) ?> Target Fixed vs Target Achieved Quantities</span>
        <span class="badge-pill info"><?= e($department) ?> Aggregate</span>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
          <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Target Fixed</div>
          <div style="font-size:26px; font-weight:800; color:var(--navy); margin-top:4px;"><?= number_format((int)($summary['total_target_fixed'] ?? 0)) ?></div>
          <div style="font-size:11.5px; color:var(--ink-faint); margin-top:2px;">Cumulative benchmark goal</div>
        </div>
        <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
          <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Target Achieved</div>
          <div style="font-size:26px; font-weight:800; color:var(--success); margin-top:4px;"><?= number_format((int)($summary['total_target_achieved'] ?? 0)) ?></div>
          <div style="font-size:11.5px; color:var(--ink-faint); margin-top:2px;">Cumulative verified submissions</div>
        </div>
      </div>
      <div class="progress-track" style="height:14px;">
        <div class="progress-fill <?= ($summary['overall_attainment_pct'] ?? 0) >= 100 ? 'success' : '' ?>" style="width:<?= min(100, (float)($summary['overall_attainment_pct'] ?? 0)) ?>%;"></div>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12px; margin-top:6px; color:var(--ink-muted);">
        <span>Achievement Percentage: <strong style="color:var(--navy);"><?= $summary['overall_attainment_pct'] ?? 0 ?>%</strong></span>
        <span>Remaining Target: <strong style="color:var(--orange);"><?= number_format((int)($summary['remaining_target'] ?? 0)) ?></strong></span>
      </div>
    </div>

    <div class="panel-card">
      <div class="panel-head">
        <span class="panel-title"><?= icon('shield', 16) ?> Target Distinction &amp; Workflow Status</span>
        <span class="badge-pill neutral"><?= e($department) ?> Workflow</span>
      </div>
      <div style="display:flex; flex-direction:column; gap:12px; font-size:13px; color:var(--ink);">
        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-bottom:8px;">
          <div style="background:var(--navy-50); padding:10px; border-radius:var(--r-sm); text-align:center;">
            <div style="font-size:11px; color:var(--ink-muted); font-weight:600;">Dean Pending</div>
            <div style="font-size:18px; font-weight:800; color:var(--warning); margin-top:2px;"><?= (int) ($summary['dean_pending_count'] ?? 0) ?></div>
          </div>
          <div style="background:var(--navy-50); padding:10px; border-radius:var(--r-sm); text-align:center;">
            <div style="font-size:11px; color:var(--ink-muted); font-weight:600;">Approved Records</div>
            <div style="font-size:18px; font-weight:800; color:var(--success); margin-top:2px;"><?= (int) ($summary['approved_count'] ?? 0) ?></div>
          </div>
          <div style="background:var(--navy-50); padding:10px; border-radius:var(--r-sm); text-align:center;">
            <div style="font-size:11px; color:var(--ink-muted); font-weight:600;">Drafts / Edits</div>
            <div style="font-size:18px; font-weight:800; color:var(--ink-muted); margin-top:2px;"><?= (int) ($summary['draft_count'] ?? 0) ?></div>
          </div>
        </div>
        <div style="display:flex; align-items:flex-start; gap:10px;">
          <span class="badge-pill success" style="width:140px; justify-content:center; flex-shrink:0;">Achieved</span>
          <span>Target genuinely met or exceeded through verified submissions.</span>
        </div>
        <div style="display:flex; align-items:flex-start; gap:10px;">
          <span class="badge-pill warning" style="width:140px; justify-content:center; flex-shrink:0;">In Progress</span>
          <span>Active tracking cycle or awaiting review where deadline has not elapsed.</span>
        </div>
        <div style="display:flex; align-items:flex-start; gap:10px;">
          <span class="badge-pill danger" style="width:140px; justify-content:center; flex-shrink:0;">Not Completed</span>
          <span>Target deadline has elapsed without achieving the configured goal.</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =================================================================== -->
<!-- SLIDE 2: TARGET FIXED VS TARGET ACHIEVED                            -->
<!-- =================================================================== -->
<section class="present-slide" data-slide-index="2">
  <div class="slide-header">
    <div class="slide-title-wrap">
      <div style="font-size:11.5px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:3px;">
        <?= e($department) ?> &middot; BENCHMARK COMPARISON
      </div>
      <h2><?= icon('bar-chart', 20) ?> <?= e($department) ?> &mdash; TARGET FIXED VS TARGET ACHIEVED</h2>
      <p>Department: <strong><?= e($department) ?></strong> &middot; Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Configured goals, verified achievements, and progress</p>
    </div>
    <div class="slide-badge-wrap">
      <span class="badge-pill brand"><?= icon('building', 13) ?> Department: <?= e($department) ?></span>
      <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year: <?= e($academicYear) ?></span>
    </div>
  </div>

  <!-- Quick Summary Cards for Target Fixed vs Achieved -->
  <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:20px;">
    <div class="kpi-card accent-navy">
      <div class="kpi-top"><span class="kpi-label">Target Fixed</span><div class="kpi-icon navy"><?= icon('target', 16) ?></div></div>
      <div class="kpi-val"><?= number_format((int)($summary['total_target_fixed'] ?? 0)) ?></div>
      <div class="kpi-sub">Total target volume</div>
    </div>
    <div class="kpi-card accent-green">
      <div class="kpi-top"><span class="kpi-label">Achieved</span><div class="kpi-icon green"><?= icon('check', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--success);"><?= number_format((int)($summary['total_target_achieved'] ?? 0)) ?></div>
      <div class="kpi-sub">Verified achievements</div>
    </div>
    <div class="kpi-card accent-orange">
      <div class="kpi-top"><span class="kpi-label">Remaining</span><div class="kpi-icon orange"><?= icon('clock', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--orange);"><?= number_format((int)($summary['remaining_target'] ?? 0)) ?></div>
      <div class="kpi-sub">Remaining balance</div>
    </div>
    <div class="kpi-card accent-blue">
      <div class="kpi-top"><span class="kpi-label">Progress</span><div class="kpi-icon blue"><?= icon('trending-up', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--info);"><?= $summary['overall_attainment_pct'] ?? 0 ?>%</div>
      <div class="kpi-sub">Overall attainment</div>
    </div>
  </div>

  <!-- Visual Progress Bars & Comparison -->
  <div class="panel-card" style="margin-bottom:20px;">
    <div class="panel-head">
      <span class="panel-title"><?= icon('bar-chart', 16) ?> <?= e($department) ?> Target Section Comparisons</span>
      <span class="badge-pill neutral"><?= count(array_filter($sections30, fn($s) => $s['target_fixed'] > 0 || $s['achieved_value'] > 0)) ?> Active Categories</span>
    </div>
    <div style="max-height:360px; overflow-y:auto; padding-right:8px; display:flex; flex-direction:column; gap:10px;">
      <?php
        $activeSecs = array_filter($sections30, fn($s) => $s['target_fixed'] > 0 || $s['achieved_value'] > 0);
        if (empty($activeSecs)):
      ?>
        <div class="empty-box">No department targets or achievements configured for <?= e($department) ?> in <?= e($academicYear) ?>.</div>
      <?php else: ?>
        <?php foreach ($activeSecs as $s): ?>
          <?php
            $pct = $s['attainment_pct'];
            $barCol = $pct >= 100 ? 'var(--success)' : ($pct >= 50 ? 'var(--orange)' : 'var(--danger)');
          ?>
          <div style="background:var(--navy-50); padding:10px 14px; border-radius:var(--r-sm); border:1px solid var(--hairline);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <strong style="color:var(--navy); font-size:13px;"><?= e($s['order']) ?>. <?= e($s['title']) ?></strong>
              <span style="font-size:12px; font-weight:700; color:<?= $barCol ?>;">
                Fixed: <?= number_format($s['target_fixed']) ?> <?= e($s['unit']) ?> &middot; Achieved: <?= number_format($s['achieved_value']) ?> <?= e($s['unit']) ?> (<?= $pct ?>%)
              </span>
            </div>
            <div class="progress-track" style="height:8px;">
              <div class="progress-fill" style="width:<?= min(100, $pct) ?>%; background:<?= $barCol ?>;"></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- =================================================================== -->
<!-- SLIDE 3: DEPARTMENT TARGET PERFORMANCE                              -->
<!-- =================================================================== -->
<section class="present-slide" data-slide-index="3">
  <div class="slide-header">
    <div class="slide-title-wrap">
      <div style="font-size:11.5px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:3px;">
        <?= e($department) ?> &middot; TARGET PERFORMANCE
      </div>
      <h2><?= icon('check-circle', 20) ?> <?= e($department) ?> &mdash; DEPARTMENT TARGET PERFORMANCE</h2>
      <p>Department: <strong><?= e($department) ?></strong> &middot; Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Section-by-section breakdown of configured targets, achievements, deadlines, and attainment status</p>
    </div>
    <div class="slide-badge-wrap">
      <span class="badge-pill brand"><?= icon('building', 13) ?> Department: <?= e($department) ?></span>
      <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year: <?= e($academicYear) ?></span>
    </div>
  </div>

  <div class="table-container" style="max-height:540px; overflow-y:auto;">
    <table class="present-table">
      <thead>
        <tr>
          <th style="width:50px;">S.No</th>
          <th>Target Section</th>
          <th class="num-align">Target Fixed</th>
          <th class="num-align">Target Achieved</th>
          <th class="num-align">Pending / In Progress</th>
          <th class="num-align">Not Completed</th>
          <th>Deadline</th>
          <th style="width:160px;">Achievement %</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sections30 as $secOrder => $s): ?>
          <?php
            $pct = $s['attainment_pct'];
            $bCol = $pct >= 100 ? 'var(--success)' : ($pct >= 50 ? 'var(--orange)' : 'var(--danger)');
            $deadlineStr = !empty($s['deadlines']) ? implode(', ', $s['deadlines']) : '&mdash;';
            $inProg = ($s['classification'] === 'Pending / In Progress') ? 1 : 0;
            $notComp = ($s['classification'] === 'Not Completed') ? 1 : 0;
            $hasData = ($s['target_fixed'] > 0 || $s['achieved_value'] > 0 || $s['targets_count'] > 0);
          ?>
          <tr>
            <td style="color:var(--ink-muted); font-weight:600;"><?= (int) $secOrder ?></td>
            <td>
              <strong style="color:var(--navy);"><?= e($s['title']) ?></strong>
              <div style="font-size:11px; color:var(--ink-faint);"><?= e($s['group']) ?> &middot; Unit: <?= e($s['unit']) ?></div>
            </td>
            <td class="num-align" style="font-weight:700;">
              <?= $s['target_fixed'] > 0 ? number_format($s['target_fixed']) . ' ' . e($s['unit']) : '&mdash;' ?>
            </td>
            <td class="num-align" style="font-weight:700; color:var(--success);">
              <?= $s['achieved_value'] > 0 ? number_format($s['achieved_value']) . ' ' . e($s['unit']) : ($hasData ? '0 ' . e($s['unit']) : '&mdash;') ?>
            </td>
            <td class="num-align" style="color:var(--orange); font-weight:600;"><?= $hasData ? $inProg : '&mdash;' ?></td>
            <td class="num-align" style="color:var(--danger); font-weight:600;"><?= $hasData ? $notComp : '&mdash;' ?></td>
            <td style="font-size:12px; color:var(--ink-muted);"><?= $deadlineStr ?></td>
            <td>
              <?php if ($s['target_fixed'] > 0): ?>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="progress-track" style="flex:1; height:8px;">
                    <div class="progress-fill" style="width:<?= min(100, $pct) ?>%; background:<?= $bCol ?>;"></div>
                  </div>
                  <span style="font-weight:700; font-size:12px; width:45px; text-align:right; color:<?= $bCol ?>;"><?= $pct ?>%</span>
                </div>
              <?php elseif ($s['achieved_value'] > 0): ?>
                <span class="badge-pill success">Recorded</span>
              <?php else: ?>
                <span style="color:var(--ink-faint); font-size:12px;">No target configured</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$hasData): ?>
                <span class="badge-pill neutral" style="font-size:11px;">Not Configured</span>
              <?php elseif ($s['classification'] === 'Achieved'): ?>
                <span class="badge-pill success" style="font-size:11px;"><?= icon('check', 12) ?> Achieved</span>
              <?php elseif ($s['classification'] === 'Not Completed'): ?>
                <span class="badge-pill danger" style="font-size:11px;"><?= icon('alert-triangle', 12) ?> Not Completed</span>
              <?php else: ?>
                <span class="badge-pill warning" style="font-size:11px;"><?= icon('clock', 12) ?> In Progress</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- =================================================================== -->
<!-- SLIDE 4: DEPARTMENT FACULTY CONTRIBUTION                            -->
<!-- =================================================================== -->
<section class="present-slide" data-slide-index="4">
  <div class="slide-header">
    <div class="slide-title-wrap">
      <div style="font-size:11.5px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:3px;">
        <?= e($department) ?> &middot; FACULTY ACHIEVEMENTS
      </div>
      <h2><?= icon('users', 20) ?> <?= e($department) ?> &mdash; DEPARTMENT FACULTY CONTRIBUTION</h2>
      <p>Department: <strong><?= e($department) ?></strong> &middot; Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Verified faculty contributions and upload achievements belonging to <?= e($department) ?> department only</p>
    </div>
    <div class="slide-badge-wrap">
      <span class="badge-pill brand"><?= icon('building', 13) ?> Department: <?= e($department) ?></span>
      <span class="badge-pill neutral"><?= count($facultyContributions) ?> Contributing Faculty</span>
      <span class="badge-pill success"><?= (int) $totalUploadedRecords ?> Verified Records</span>
    </div>
  </div>

  <!-- Faculty Summary KPI Cards -->
  <div class="kpi-cards-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:20px;">
    <div class="kpi-card accent-navy">
      <div class="kpi-top"><span class="kpi-label">Contributing Faculty</span><div class="kpi-icon navy"><?= icon('users', 16) ?></div></div>
      <div class="kpi-val"><?= count($facultyContributions) ?></div>
      <div class="kpi-sub">Faculty members with verified records in <?= e($department) ?></div>
    </div>
    <div class="kpi-card accent-green">
      <div class="kpi-top"><span class="kpi-label">Eligible Upload Records</span><div class="kpi-icon green"><?= icon('check-circle', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--success);"><?= (int) $totalUploadedRecords ?></div>
      <div class="kpi-sub">Approved achievements in <?= e($academicYear) ?></div>
    </div>
    <div class="kpi-card accent-orange">
      <div class="kpi-top"><span class="kpi-label">Top Contributor</span><div class="kpi-icon orange"><?= icon('award', 16) ?></div></div>
      <div class="kpi-val" style="font-size:20px; color:var(--navy); line-height:1.2;">
        <?= !empty($facultyContributions) ? e($facultyContributions[0]['faculty_name']) : '&mdash;' ?>
      </div>
      <div class="kpi-sub"><?= !empty($facultyContributions) ? $facultyContributions[0]['eligible_count'] . ' verified records' : 'No contributions recorded' ?></div>
    </div>
  </div>

  <!-- Department Faculty Contribution Table -->
  <div class="table-container" style="max-height:480px; overflow-y:auto;">
    <table class="present-table">
      <thead>
        <tr>
          <th style="width:50px;">S.No</th>
          <th>Faculty Name</th>
          <th>Department</th>
          <th>Target Category</th>
          <th class="num-align">Eligible Achievement Count</th>
          <th>Pending Records</th>
          <th>Contribution Summary</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($facultyContributions)): ?>
          <tr>
            <td colspan="7" style="text-align:center; padding:36px; color:var(--ink-muted);">
              <div style="font-weight:700; font-size:14px; margin-bottom:4px;">No eligible faculty contributions recorded yet for <?= e($department) ?>.</div>
              <div style="font-size:12px; color:var(--ink-faint);">Approved faculty upload records will appear here as achievements are verified.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php $fn = 1; foreach ($facultyContributions as $f): ?>
            <tr>
              <td style="color:var(--ink-muted); font-weight:600;"><?= $fn++ ?></td>
              <td style="font-weight:700; color:var(--navy);"><?= e($f['faculty_name']) ?></td>
              <td><span class="badge-pill brand" style="font-size:11px;"><?= e($f['department']) ?></span></td>
              <td>
                <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:320px;">
                  <?php foreach ($f['categories'] as $cat): ?>
                    <span class="badge-pill neutral" style="font-size:10.5px;"><?= e($cat) ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="num-align" style="font-weight:800; color:var(--success); font-size:14px;">
                <?= (int) $f['eligible_count'] ?>
              </td>
              <td style="font-size:12px; color:var(--ink-muted);">&mdash;</td>
              <td style="font-size:12px; color:var(--ink);">
                <?= (int) $f['eligible_count'] ?> verified upload record(s) across <?= count($f['categories']) ?> category(s)
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- =================================================================== -->
<!-- SLIDES 5–34: ALL 30 INDIVIDUAL TARGET SECTIONS FOR DEPARTMENT       -->
<!-- =================================================================== -->
<?php foreach ($sections30 as $secOrder => $sec): ?>
  <?php $secSlideIdx = 4 + (int) $secOrder; ?>
  <section class="present-slide" data-slide-index="<?= $secSlideIdx ?>">
    <div class="slide-header">
      <div class="slide-title-wrap">
        <div style="font-size:11.5px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:3px;">
          Section <?= $sec['order'] ?> of 30 &middot; <?= e($sec['group']) ?>
        </div>
        <h2><?= icon('target', 20) ?> <?= e($sec['order']) ?>. <?= e($sec['title']) ?></h2>
        <p><?= e($sec['description']) ?></p>
      </div>
      <div class="slide-badge-wrap">
        <span class="badge-pill brand"><?= icon('building', 13) ?> Department: <?= e($department) ?></span>
        <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year: <?= e($academicYear) ?></span>
        <span class="badge-pill <?= $sec['classification'] === 'Achieved' ? 'success' : ($sec['classification'] === 'Not Completed' ? 'danger' : 'warning') ?>">
          <?= e($sec['classification']) ?>
        </span>
      </div>
    </div>

    <!-- 4 KPI Cards for Section -->
    <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:18px;">
      <div class="kpi-card accent-navy">
        <div class="kpi-top">
          <span class="kpi-label">Target Fixed</span>
          <div class="kpi-icon navy"><?= icon('target', 16) ?></div>
        </div>
        <div class="kpi-val"><?= number_format($sec['target_fixed']) ?> <span style="font-size:13px; font-weight:600; color:var(--ink-muted);"><?= e($sec['unit']) ?></span></div>
        <div class="kpi-sub"><?= e($department) ?> departmental goal</div>
      </div>

      <div class="kpi-card accent-green">
        <div class="kpi-top">
          <span class="kpi-label">Target Achieved</span>
          <div class="kpi-icon green"><?= icon('check', 16) ?></div>
        </div>
        <div class="kpi-val" style="color:var(--success);"><?= number_format($sec['achieved_value']) ?> <span style="font-size:13px; font-weight:600; color:var(--ink-muted);"><?= e($sec['unit']) ?></span></div>
        <div class="kpi-sub">Verified <?= e($department) ?> submissions</div>
      </div>

      <div class="kpi-card accent-orange">
        <div class="kpi-top">
          <span class="kpi-label">Achievement %</span>
          <div class="kpi-icon orange"><?= icon('trending-up', 16) ?></div>
        </div>
        <div class="kpi-val" style="color:var(--orange);"><?= $sec['attainment_pct'] ?>%</div>
        <div class="kpi-sub">Remaining: <?= number_format($sec['remaining']) ?> <?= e($sec['unit']) ?></div>
      </div>

      <div class="kpi-card accent-blue">
        <div class="kpi-top">
          <span class="kpi-label">Status &amp; Deadline</span>
          <div class="kpi-icon blue"><?= icon('calendar', 16) ?></div>
        </div>
        <div class="kpi-val" style="font-size:18px; color:var(--navy); line-height:1.2;">
          <?= e($sec['classification']) ?>
        </div>
        <div class="kpi-sub">
          <?= !empty($sec['deadlines']) ? 'Due: ' . implode(', ', $sec['deadlines']) : 'Standard cycle' ?>
        </div>
      </div>
    </div>

    <!-- Section Details: Configured Targets & Uploaded Records -->
    <div class="split-grid-2">
      <!-- Department Target Configuration -->
      <div class="panel-card">
        <div class="panel-head">
          <span class="panel-title"><?= icon('target', 16) ?> Configured Department Targets</span>
          <span class="badge-pill brand"><?= count($sec['targets']) ?> Benchmark(s)</span>
        </div>
        <?php if (empty($sec['targets'])): ?>
          <div class="empty-box">
            <div style="font-weight:700; margin-bottom:4px;"><?= icon('info', 14) ?> No department target configured.</div>
            <div style="font-size:12px; color:var(--ink-faint);">No specific numerical target has been set for <?= e($department) ?> in this section for <?= e($academicYear) ?>.</div>
          </div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:10px;">
            <?php foreach ($sec['targets'] as $st): ?>
              <div style="background:var(--navy-50); padding:12px 14px; border-radius:var(--r-md); border:1px solid var(--hairline);">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                  <strong style="color:var(--navy); font-size:13px;"><?= e($st['metric'] ?? $sec['title']) ?></strong>
                  <span class="badge-pill <?= ($st['status'] ?? '') === 'Approved' ? 'success' : 'warning' ?>"><?= e($st['status'] ?? 'Active') ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--ink-muted); margin-top:6px;">
                  <span>Target: <strong><?= number_format((int)($st['target_value'] ?? 0)) ?> <?= e($sec['unit']) ?></strong> &middot; Achieved: <strong style="color:var(--success);"><?= number_format((int)($st['achieved_value'] ?? 0)) ?></strong></span>
                  <span><?= !empty($st['target_deadline']) ? 'Deadline: ' . e($st['target_deadline']) : '' ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Department Eligible Upload Records -->
      <div class="panel-card">
        <div class="panel-head">
          <span class="panel-title"><?= icon('file-text', 16) ?> Verified Department Upload Records</span>
          <span class="badge-pill success"><?= count($sec['uploaded_records']) ?> Approved Record(s)</span>
        </div>
        <?php if (empty($sec['uploaded_records'])): ?>
          <div class="empty-box">
            <div style="font-weight:700; margin-bottom:4px;"><?= icon('info', 14) ?> No eligible achievement records submitted yet.</div>
            <div style="font-size:12px; color:var(--ink-faint);">Faculty upload records belonging to <?= e($department) ?> will be displayed here once approved.</div>
          </div>
        <?php else: ?>
          <div style="max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($sec['uploaded_records'] as $ur): ?>
              <div style="background:var(--navy-50); padding:10px 12px; border-radius:var(--r-sm); border:1px solid var(--hairline); font-size:12.5px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                  <strong style="color:var(--navy);"><?= e($ur['title']) ?></strong>
                  <span class="badge-pill success" style="font-size:10px; padding:2px 8px;"><?= e($ur['status']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; color:var(--ink-muted); font-size:11.5px; margin-top:4px;">
                  <span><?= icon('user', 11) ?> <?= e($ur['person']) ?></span>
                  <span><?= e(date('d M Y', strtotime($ur['date']))) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php endforeach; ?>

<!-- =================================================================== -->
<!-- SLIDE 35: FINAL DEPARTMENT TARGET PERFORMANCE SUMMARY               -->
<!-- =================================================================== -->
<section class="present-slide" data-slide-index="35">
  <div class="slide-header">
    <div class="slide-title-wrap">
      <div style="font-size:11.5px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:3px;">
        <?= e($department) ?> &middot; EXECUTIVE CONCLUSION
      </div>
      <h2><?= icon('award', 20) ?> <?= e($department) ?> &mdash; FINAL DEPARTMENT TARGET PERFORMANCE SUMMARY</h2>
      <p>Department: <strong><?= e($department) ?></strong> &middot; Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Consolidated score card reconciling all department targets, achievements, faculty contributions, and priority categories</p>
    </div>
    <div class="slide-badge-wrap">
      <span class="badge-pill brand"><?= icon('building', 13) ?> Department: <?= e($department) ?></span>
      <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year: <?= e($academicYear) ?></span>
      <span class="badge-pill success"><?= icon('check', 13) ?> Department Final Scorecard</span>
    </div>
  </div>

  <!-- 4 Reconciled KPI Cards (Matches Slide 1 Exactly!) -->
  <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:20px;">
    <div class="kpi-card accent-navy">
      <div class="kpi-top"><span class="kpi-label">Total Department Targets</span><div class="kpi-icon navy"><?= icon('target', 16) ?></div></div>
      <div class="kpi-val"><?= (int) ($summary['total_targets'] ?? 0) ?></div>
      <div class="kpi-sub">Total benchmarks configured</div>
    </div>
    <div class="kpi-card accent-green">
      <div class="kpi-top"><span class="kpi-label">Targets Achieved</span><div class="kpi-icon green"><?= icon('check', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--success);"><?= (int) ($summary['achieved_targets'] ?? 0) ?></div>
      <div class="kpi-sub"><?= $summary['targets_met_pct'] ?? 0 ?>% fulfilled</div>
    </div>
    <div class="kpi-card accent-orange">
      <div class="kpi-top"><span class="kpi-label">Pending / In Progress</span><div class="kpi-icon orange"><?= icon('clock', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--orange);"><?= (int) ($summary['in_progress_targets'] ?? 0) ?></div>
      <div class="kpi-sub">Active benchmarks</div>
    </div>
    <div class="kpi-card accent-red">
      <div class="kpi-top"><span class="kpi-label">Not Completed</span><div class="kpi-icon red"><?= icon('alert-triangle', 16) ?></div></div>
      <div class="kpi-val" style="color:var(--danger);"><?= (int) ($summary['not_completed_targets'] ?? 0) ?></div>
      <div class="kpi-sub">Past official deadline</div>
    </div>
  </div>

  <!-- Reconciled Scorecard & Priority Attention Categories -->
  <div class="split-grid-2">
    <div class="panel-card">
      <div class="panel-head">
        <span class="panel-title"><?= icon('bar-chart', 16) ?> <?= e($department) ?> Quantitative Performance</span>
        <span class="badge-pill info">Reconciled Summary</span>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
          <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Target Fixed</div>
          <div style="font-size:26px; font-weight:800; color:var(--navy); margin-top:4px;"><?= number_format((int)($summary['total_target_fixed'] ?? 0)) ?></div>
        </div>
        <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
          <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Target Achieved</div>
          <div style="font-size:26px; font-weight:800; color:var(--success); margin-top:4px;"><?= number_format((int)($summary['total_target_achieved'] ?? 0)) ?></div>
        </div>
      </div>
      <div class="progress-track" style="height:14px; margin-bottom:8px;">
        <div class="progress-fill <?= ($summary['overall_attainment_pct'] ?? 0) >= 100 ? 'success' : '' ?>" style="width:<?= min(100, (float)($summary['overall_attainment_pct'] ?? 0)) ?>%;"></div>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12.5px; color:var(--ink-muted); margin-bottom:14px;">
        <span>Overall Attainment: <strong style="color:var(--navy);"><?= $summary['overall_attainment_pct'] ?? 0 ?>%</strong></span>
        <span>Contributing Faculty: <strong style="color:var(--navy);"><?= count($facultyContributions) ?></strong></span>
        <span>Verified Uploads: <strong style="color:var(--success);"><?= (int) $totalUploadedRecords ?></strong></span>
      </div>
    </div>

    <div class="panel-card">
      <div class="panel-head">
        <span class="panel-title"><?= icon('alert-triangle', 16) ?> Key Target Categories Requiring Attention</span>
        <span class="badge-pill warning">Priority Action</span>
      </div>
      <?php if (empty($attentionCategories)): ?>
        <div class="empty-box">All configured department target categories have satisfactory progress or no elapsed deadlines.</div>
      <?php else: ?>
        <div style="max-height:260px; overflow-y:auto; display:flex; flex-direction:column; gap:10px;">
          <?php foreach ($attentionCategories as $att): ?>
            <div style="background:var(--navy-50); padding:10px 14px; border-radius:var(--r-md); border:1px solid var(--hairline);">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <strong style="color:var(--navy); font-size:13px;"><?= e($att['title']) ?></strong>
                <span class="badge-pill danger"><?= $att['attainment_pct'] ?>% Attained</span>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--ink-muted); margin-top:4px;">
                <span>Goal: <?= number_format($att['target_fixed']) ?> <?= e($att['unit'] ?? '') ?> &middot; Achieved: <?= number_format($att['achieved_value']) ?></span>
                <?php if ($att['not_completed'] > 0): ?>
                  <span style="color:var(--danger); font-weight:700;">Deadline passed</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
