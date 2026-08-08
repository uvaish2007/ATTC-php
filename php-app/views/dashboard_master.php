<?php
/**
 * Main dashboard for Admin, Director, HoD and Coordinator.
 *
 * Admin and Director see every department and can change the filter.
 * HoD and Coordinator are tied to their own department (the model forces this,
 * so the dropdown is replaced by a plain label for them).
 *
 * Comes from dashboard.php:  $user, $data, $pageTitle
 */

$isOversight = $data['isOversight'];                             // Admin / Director
$isReviewer  = in_array($user['role'], ['Admin', 'HoD'], true);  // can approve
$scopeLabel  = $data['scope']['department'] ?: 'All departments';
$stats       = $data['stats'];

/*
 * One colour per review status, used by the doughnut and its legend.
 *
 * These are status colours, not decoration: green means done, blue means it is
 * moving, red means it came back, grey means it has not been sent yet. They are
 * listed in that order so no two similar colours end up side by side on the
 * ring, and every slice is also named and counted in the legend — nobody has to
 * tell them apart by colour alone.
 */
$statusColours = [
    'Approved'  => '#059669',
    'Submitted' => '#2563EB',
    'Rejected'  => '#DC2626',
    'Draft'     => '#6B7FA8',
];

// Short names for the chart, so the labels under the bars never collide.
$shortNames = [
    'Journals'       => 'Journals',  'Books'          => 'Books',    'Conferences' => 'Conf.',
    'Patents'        => 'Patents',   'FDP'            => 'FDP',      'MoUs'        => 'MoUs',
    'NPTEL'          => 'NPTEL',     'Online Courses' => 'Online',
    'Events'         => 'Events',    'NSS/YRC/RRC'    => 'NSS',      'Value Added' => 'Val.Add',
    'Training'       => 'Training',
    'Internships'    => 'Interns',   'Placements'     => 'Placed',   'Summer Training' => 'Summer',
    'Achievements'   => 'Achiev.',   'Participations' => 'Particip.',
];

// ---- Headline KPIs: rates and progress, not just raw counts ----------------
$sb          = $data['statusBreakdown'];
$recTotal    = array_sum($sb);
$approved    = (int) ($sb['Approved'] ?? 0);
$pendingRec  = (int) ($sb['Submitted'] ?? 0);
$rejectedRec = (int) ($sb['Rejected'] ?? 0);
$approvalRate = $recTotal > 0 ? (int) round($approved / $recTotal * 100) : 0;

$attain = $isOversight ? ($data['targetAttainment']['summary'] ?? null) : null;
$attPct = ($attain && $attain['targets'] > 0) ? (int) $attain['percent'] : null;
$rateColor = fn(int $p) => $p >= 75 ? '#059669' : ($p >= 40 ? '#FF4F01' : '#DC2626');

// Each card: label, value, sub, icon, tone; optional bar (%) + barColor.
$cards = [];
$cards[] = ['label' => 'Total Records', 'value' => (string) $stats['totalRecords'],
            'sub' => $approved . ' approved · ' . $pendingRec . ' pending', 'icon' => 'layers', 'tone' => 'brand'];

$cards[] = ['label' => 'Approval Rate', 'value' => $approvalRate . '%',
            'sub' => $approved . ' of ' . $recTotal . ' approved', 'icon' => 'check', 'tone' => 'navy',
            'bar' => $approvalRate, 'barColor' => $rateColor($approvalRate)];

$cards[] = ['label' => 'Pending Review', 'value' => (string) $pendingRec,
            'sub' => $pendingRec ? ($isReviewer ? 'Awaiting your review' : 'Awaiting review') : 'All caught up',
            'icon' => 'clock', 'tone' => $pendingRec ? 'brand' : 'navy'];

if ($isOversight) {
    if ($attPct !== null) {
        $cards[] = ['label' => 'Target Attainment', 'value' => $attPct . '%',
                    'sub' => (int) $attain['met'] . ' of ' . (int) $attain['targets'] . ' targets met',
                    'icon' => 'target', 'tone' => 'navy', 'bar' => min(100, $attPct), 'barColor' => $rateColor($attPct)];
    } else {
        $cards[] = ['label' => 'Targets', 'value' => (string) $stats['targets'], 'sub' => 'Configured targets', 'icon' => 'target', 'tone' => 'navy'];
    }
    $cards[] = ['label' => 'Departments', 'value' => (string) $stats['departments'], 'sub' => 'Active departments', 'icon' => 'building', 'tone' => 'navy'];
    $cards[] = ['label' => 'Team', 'value' => (string) $stats['users'], 'sub' => 'Registered accounts', 'icon' => 'users', 'tone' => 'navy'];
} else {
    $cards[] = ['label' => 'Targets', 'value' => (string) $stats['targets'], 'sub' => 'Configured targets', 'icon' => 'target', 'tone' => 'navy'];
}

// The department table only earns its place when there are several to compare.
$rows            = $data['matrix']['rows'];
$showDepartments = $isOversight && count($rows) > 1;

// Drill-down: an oversight user can click a department anywhere on the page to
// scope the whole dashboard to it (keeping the year filter).
$deptUrl = function (string $dept) use ($data) {
    $q = array_filter(['department' => $dept, 'year' => $data['scope']['year'] ?? '']);
    return url('dashboard.php') . ($q ? '?' . http_build_query($q) : '');
};
?>

<div class="page-head">
  <div>
    <h1><?= e($pageTitle) ?></h1>
    <div class="sub">
      <?= $isOversight ? 'Institution-wide overview' : 'Department overview' ?>
      &middot; <?= e($scopeLabel) ?><?= !empty($data['scope']['year']) ? ' &middot; ' . e($data['scope']['year']) : '' ?><?= $data['scope']['status'] ? ' &middot; ' . e($data['scope']['status']) : '' ?>
    </div>
  </div>

  <!-- Picking a filter just reloads the page with it in the query string -->
  <div class="actions">
    <form method="get" class="flex gap-2 items-center">

      <?php if ($isOversight): ?>
        <select class="select" name="department" onchange="this.form.submit()">
          <option value="">All departments</option>
          <?php foreach ($data['departments'] as $dept): ?>
            <option value="<?= e($dept) ?>" <?= $data['scope']['department'] === $dept ? 'selected' : '' ?>>
              <?= e($dept) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <span class="badge badge-neutral"><?= e($scopeLabel) ?></span>
      <?php endif; ?>

      <select class="select" name="year" onchange="this.form.submit()">
        <option value="">All years</option>
        <?php foreach ($data['years'] as $y): ?>
          <option value="<?= e($y) ?>" <?= ($data['scope']['year'] ?? '') === $y ? 'selected' : '' ?>>
            <?= e($y) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select class="select" name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['Approved', 'Submitted', 'Draft', 'Rejected'] as $status): ?>
          <option value="<?= $status ?>" <?= $data['scope']['status'] === $status ? 'selected' : '' ?>>
            <?= $status ?>
          </option>
        <?php endforeach; ?>
      </select>

    </form>
  </div>
</div>


<?php /* ---- HoD: the unlock window, shown with total + remaining time ---- */ ?>
<?php if ($user['role'] === 'HoD'): ?>
  <?php $du = unlock_state($user['department'] ?? null); ?>
  <?php if ($du['state'] === 'unlocked'): ?>
    <div class="unlock-banner open" data-until="<?= (int) $du['until'] * 1000 ?>">
      <div class="ub-ic"><?= icon('clock', 20) ?></div>
      <div class="ub-body">
        <div class="ub-title">Targets unlocked for editing</div>
        <div class="ub-sub">
          Total window <strong><?= (int) $du['hours'] ?>h</strong>
          &middot; Remaining <strong class="ub-remaining tabular">…</strong>
          &middot; <a href="<?= e(url('targets.php')) ?>">edit your targets</a> before the timer ends.
        </div>
      </div>
      <div class="ub-clock tabular">…</div>
    </div>
    <script>
      (function () {
        var b = document.querySelector('.unlock-banner.open'); if (!b) return;
        var until = parseInt(b.getAttribute('data-until'), 10);
        var rEl = b.querySelector('.ub-remaining'), cEl = b.querySelector('.ub-clock');
        function p(n){ return (n<10?'0':'')+n; }
        function tick(){
          var ms = until - Date.now();
          if (ms <= 0){ cEl.textContent='00:00:00'; if(rEl) rEl.textContent='expired'; location.reload(); return; }
          var s=Math.floor(ms/1000), h=Math.floor(s/3600), m=Math.floor((s%3600)/60), x=s%60;
          cEl.textContent=p(h)+':'+p(m)+':'+p(x); if(rEl) rEl.textContent=h+'h '+p(m)+'m '+p(x)+'s';
        }
        tick(); setInterval(tick, 1000);
      })();
    </script>
  <?php elseif ($du['state'] === 'requested'): ?>
    <div class="unlock-banner pending">
      <div class="ub-ic"><?= icon('clock', 20) ?></div>
      <div class="ub-body">
        <div class="ub-title">Unlock request awaiting the Admin</div>
        <div class="ub-sub">Your locked targets can be edited once the Admin grants the request.</div>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>


<!-- Counters -->
<div class="stat-grid <?= $isOversight ? 'grid-6' : 'grid-4' ?>">

  <?php foreach ($cards as $c): ?>
    <div class="stat">
      <div class="stat-top">
        <div class="stat-label"><?= e($c['label']) ?></div>
        <div class="stat-ic <?= $c['tone'] ?>"><?= icon($c['icon']) ?></div>
      </div>
      <div class="stat-value tabular"><?= e((string) $c['value']) ?></div>
      <?php if (isset($c['bar'])): ?>
        <div class="stat-bar"><span style="width:<?= (int) $c['bar'] ?>%;background:<?= e($c['barColor'] ?? 'var(--orange-500)') ?>"></span></div>
      <?php endif; ?>
      <div class="stat-desc"><?= e($c['sub']) ?></div>
    </div>
  <?php endforeach; ?>

</div>


<!-- ==========================================================================
     Departments (left) and the metric chart (right)
     ======================================================================= -->
<div class="mt-5 <?= $showDepartments ? 'grid-1-2' : 'grid-1' ?>">

  <?php if ($showDepartments): ?>
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Departments</div>
          <div class="card-sub">Click a heading to sort</div>
        </div>
      </div>

      <div class="card-body">
        <?php
          // Biggest department sets the length of the share bars.
          $maxTotal   = max(1, ...array_column($rows, 'total'));
          $allRecords = max(1, array_sum(array_column($rows, 'total')));

          // Show the busiest department first.
          $ranked = $rows;
          usort($ranked, fn($a, $b) => $b['total'] <=> $a['total']);
        ?>

        <div class="table-wrap">
          <table class="data sortable" id="deptTable">
            <thead>
              <tr>
                <th class="sort-th" onclick="sortTable('deptTable', 0)">#</th>
                <th class="sort-th" onclick="sortTable('deptTable', 1)">Department</th>
                <th class="sort-th num" onclick="sortTable('deptTable', 2)">Records</th>
                <th class="sort-th num" onclick="sortTable('deptTable', 3)">Share</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ranked as $index => $row): ?>
                <?php $share = round($row['total'] / $allRecords * 100); ?>
                <tr class="drill-row" onclick="location.href='<?= e($deptUrl($row['department'])) ?>'" title="Open <?= e($row['department']) ?>">
                  <td class="faint tabular" data-value="<?= $index + 1 ?>"><?= $index + 1 ?></td>
                  <td class="fw-500 nowrap" data-value="<?= e($row['department']) ?>"><a class="drill-link" href="<?= e($deptUrl($row['department'])) ?>"><?= e($row['department']) ?></a></td>
                  <td class="num tabular fw-600" data-value="<?= (int) $row['total'] ?>"><?= (int) $row['total'] ?></td>
                  <td class="num" data-value="<?= (int) $share ?>">
                    <div class="share">
                      <span class="share-track">
                        <span class="share-fill" style="width:<?= round($row['total'] / $maxTotal * 100) ?>%"></span>
                      </span>
                      <span class="tabular faint"><?= (int) $share ?>%</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>


  <!-- Records by category — every metric grouped by what it measures -->
  <?php
    $groupMeta = [
        'faculty'  => ['title' => 'Faculty Contributions', 'icon' => 'file-text'],
        'activity' => ['title' => 'Activities & Outreach',  'icon' => 'calendar'],
        'student'  => ['title' => 'Student Records',         'icon' => 'users'],
    ];
    $grouped = ['faculty' => [], 'activity' => [], 'student' => []];
    foreach ($data['matrix']['metrics'] as $m) {
        $grouped[$m['group']][] = ['label' => $m['label'], 'value' => (int) ($data['totals'][$m['key']] ?? 0)];
    }
    // One scale across every metric, so bar lengths are comparable between groups.
    $metricMax = max(1, ...array_map('intval', array_values($data['totals'])));
  ?>
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Records by Category</div>
        <div class="card-sub">Every metric, grouped by what it measures</div>
      </div>
      <span class="tabular card-sub"><?= array_sum(array_map('intval', array_values($data['totals']))) ?> total</span>
    </div>

    <div class="card-body mg-wrap">
      <?php foreach ($groupMeta as $gkey => $meta): ?>
        <?php $items = $grouped[$gkey]; $gsum = array_sum(array_column($items, 'value')); ?>
        <div class="mg-group">
          <div class="mg-head">
            <span class="mg-title"><?= icon($meta['icon'], 14) ?> <?= e($meta['title']) ?></span>
            <span class="mg-sum tabular"><?= (int) $gsum ?></span>
          </div>
          <?php foreach ($items as $it): ?>
            <div class="bar-row thin">
              <span class="bar-label truncate"><?= e($it['label']) ?></span>
              <span class="bar-track">
                <span class="bar-fill" style="width:<?= $it['value'] ? max(3, round($it['value'] / $metricMax * 100)) : 0 ?>%"></span>
              </span>
              <span class="bar-num tabular <?= $it['value'] ? '' : 'faint' ?>"><?= (int) $it['value'] ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<style>
  .mg-wrap { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:22px 28px; }
  .mg-group { min-width:0; }
  .mg-head { display:flex; align-items:center; justify-content:space-between; gap:8px;
      padding-bottom:8px; margin-bottom:8px; border-bottom:1px solid var(--hairline, #e6e8ef); }
  .mg-title { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:700;
      text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted, #64748b); }
  .mg-sum { font-weight:700; font-size:15px; }
  .group-row .group-th { text-align:center; font-size:11px; font-weight:700; text-transform:uppercase;
      letter-spacing:.04em; color:var(--ink-muted, #64748b); background:var(--surface-2, #f6f8fc);
      border-bottom:2px solid var(--hairline, #e6e8ef); }
  /* Mini progress bar inside a KPI card */
  .stat-bar { height:5px; border-radius:999px; background:var(--navy-100, #E7EBF3); overflow:hidden; margin:9px 0 3px; }
  .stat-bar > span { display:block; height:100%; border-radius:999px; transition:width .4s ease; }
  /* Department-breakdown heatmap */
  .heat-cell { border-radius:6px; transition:transform .1s; }
  .heat-cell:hover { transform:scale(1.12); }
  .heat-cell.faint { color:var(--ink-faint, #94a3b8); }
  .heat-legend { display:inline-flex; align-items:center; gap:5px; font-size:11px; color:var(--ink-muted, #64748b); }
  .heat-legend .heat-swatch { width:16px; height:12px; border-radius:3px; display:inline-block; }
  /* Target Progress — whole-scope summary */
  .tp-overall { display:flex; align-items:center; gap:16px; margin-bottom:16px; }
  .tp-pct { font-size:34px; font-weight:800; line-height:1; letter-spacing:-.02em; }
  .tp-overall-body { flex:1; min-width:0; }
  .tp-bar { height:8px; border-radius:999px; background:var(--navy-100, #E7EBF3); overflow:hidden; margin-bottom:6px; }
  .tp-bar > span { display:block; height:100%; border-radius:999px; transition:width .4s ease; }
  .tp-dist { display:flex; height:11px; border-radius:999px; overflow:hidden; background:var(--navy-100, #E7EBF3); gap:2px; }
  .tp-dist > span { display:block; }
  .tp-legend { display:flex; flex-wrap:wrap; gap:16px; margin:9px 0 2px; font-size:12px; color:var(--ink-muted, #64748b); }
  .tp-legend i { width:9px; height:9px; border-radius:3px; display:inline-block; margin-right:5px; vertical-align:middle; }
  .tp-legend b { color:var(--ink, #0f172a); font-weight:700; }
  .tp-depts-head { margin-top:16px; display:flex; align-items:center; justify-content:space-between;
      font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  .tp-depts-head span { font-weight:500; text-transform:none; letter-spacing:0; opacity:.7; }
  .tp-depts { margin-top:8px; padding-top:4px; max-height:210px; overflow-y:auto;
      display:flex; flex-direction:column; gap:2px; }
  .tp-dept-link { text-decoration:none; color:inherit; padding:4px 8px; margin:0 -8px; border-radius:8px;
      transition:background .12s; }
  .tp-dept-link:hover { background:var(--navy-50, #f2f5fb); }
  .tp-dept-name { display:flex; align-items:center; gap:8px; font-weight:500; }
  .tp-dept-count { font-size:11px; font-weight:600; color:var(--ink-muted, #64748b);
      background:var(--navy-100, #E7EBF3); border-radius:999px; padding:1px 7px; }
  /* Clickable department rows in the Departments table */
  .drill-row { cursor:pointer; transition:background .12s; }
  .drill-row:hover { background:var(--navy-50, #f2f5fb); }
  .drill-link { color:inherit; text-decoration:none; }
  .drill-row:hover .drill-link { color:var(--brand, #FF4F01); }
  /* Review Status — compact */
  .rs-bar { display:flex; height:14px; border-radius:999px; overflow:hidden; background:var(--navy-100, #E7EBF3); gap:2px; margin-bottom:16px; }
  .rs-bar > span { display:block; }
  .rs-legend { display:flex; flex-direction:column; gap:11px; }
  .rs-row { display:flex; align-items:center; gap:9px; font-size:13px; }
  .rs-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
  .rs-nm { flex:1; }
  .rs-n { font-weight:700; }
  .rs-pc { min-width:40px; text-align:right; }
  /* Department Breakdown — storytelling */
  .story-legend { display:inline-flex; gap:16px; font-size:12px; color:var(--ink-muted, #64748b); }
  .story-legend i { width:10px; height:10px; border-radius:3px; display:inline-block; margin-right:5px; vertical-align:middle; }
  .story-lead { font-size:14.5px; line-height:1.65; margin:0 0 18px; color:var(--ink, #0f172a); max-width:70ch; }
  .story-lead b { font-weight:700; }
  .story-insights { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px; margin-bottom:22px; }
  .story-card { display:flex; align-items:center; gap:12px; padding:13px 14px; border:1px solid var(--hairline, #e6e8ef); border-radius:12px; }
  .si-ic { width:38px; height:38px; border-radius:10px; display:grid; place-items:center; flex-shrink:0; }
  .si-k { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted, #64748b); }
  .si-v { font-size:18px; font-weight:800; line-height:1.15; }
  .si-sub { font-size:12px; color:var(--ink-muted, #64748b); margin-top:1px; }
  .story-bars { display:flex; flex-direction:column; gap:2px; }
  .sb-row { display:grid; grid-template-columns:118px 1fr 44px; align-items:center; gap:12px;
      padding:7px 8px; margin:0 -8px; border-radius:8px; text-decoration:none; color:inherit; transition:background .12s; }
  .sb-row:hover { background:var(--navy-50, #f2f5fb); }
  .sb-name { font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .sb-track { height:16px; background:var(--navy-100, #E7EBF3); border-radius:999px; overflow:hidden; }
  .sb-bar { display:flex; height:100%; border-radius:999px; overflow:hidden; min-width:4px; transition:width .4s ease; }
  .sb-bar .seg { display:block; }
  .sb-total { text-align:right; font-weight:700; font-size:13px; }
  .story-detail { margin-top:20px; border-top:1px solid var(--hairline, #e6e8ef); padding-top:4px; }
  .story-detail > summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--ink-muted, #64748b);
      padding:10px 0; list-style:none; display:inline-flex; align-items:center; gap:7px; }
  .story-detail > summary::-webkit-details-marker { display:none; }
  .story-detail > summary::marker { content:""; }
  .story-detail > summary:hover { color:var(--brand, #FF4F01); }
</style>


<!-- ==========================================================================
     How the targets are doing (whole scope), with a compact review-status bar
     ======================================================================= -->
<?php
  $ts        = $data['targetSummary'];
  $breakdown = $data['statusBreakdown'];
  $totalCount = array_sum($breakdown);
  // Health colour for an aggregate percentage.
  $tpTone = fn (int $p) => $p >= 75 ? '#059669' : ($p >= 40 ? '#FF4F01' : '#DC2626');
?>
<div class="mt-5 grid-2-1">

  <!-- Target Progress — the WHOLE set of targets, summarised -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Target Progress</div>
        <div class="card-sub"><?= (int) $ts['count'] ?> target<?= $ts['count'] === 1 ? '' : 's' ?> in scope &middot; overall achievement</div>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('targets.php')) ?>">Open Targets</a>
    </div>

    <div class="card-body">
      <?php if ($ts['count'] === 0): ?>

        <div class="empty">
          <div class="ic"><?= icon('target', 20) ?></div>
          <p>No targets set for this scope</p>
          <div class="note">Set one on the Targets page to track progress here.</div>
        </div>

      <?php else: ?>

        <!-- Headline: one number for every target combined -->
        <div class="tp-overall">
          <div class="tp-pct" style="color:<?= $tpTone($ts['percent']) ?>"><?= (int) $ts['percent'] ?>%</div>
          <div class="tp-overall-body">
            <div class="tp-bar"><span style="width:<?= min(100, (int) $ts['percent']) ?>%;background:<?= $tpTone($ts['percent']) ?>"></span></div>
            <div class="card-sub"><?= number_format($ts['achieved']) ?> of <?= number_format($ts['target']) ?> achieved across every target</div>
          </div>
        </div>

        <!-- Distribution: how many targets are Met / On track / Behind -->
        <div class="tp-dist" title="Met / On track / Behind">
          <?php if ($ts['met']): ?><span style="flex:<?= $ts['met'] ?>;background:#059669"></span><?php endif; ?>
          <?php if ($ts['onTrack']): ?><span style="flex:<?= $ts['onTrack'] ?>;background:#FF4F01"></span><?php endif; ?>
          <?php if ($ts['behind']): ?><span style="flex:<?= $ts['behind'] ?>;background:#DC2626"></span><?php endif; ?>
        </div>
        <div class="tp-legend">
          <span><i style="background:#059669"></i> Met <b><?= (int) $ts['met'] ?></b></span>
          <span><i style="background:#FF4F01"></i> On track <b><?= (int) $ts['onTrack'] ?></b></span>
          <span><i style="background:#DC2626"></i> Behind <b><?= (int) $ts['behind'] ?></b></span>
        </div>

        <!-- Per-department rollup (only when more than one is in scope) -->
        <?php if (count($ts['byDepartment']) > 1): ?>
          <div class="tp-depts-head card-sub">By department <span>click to drill in</span></div>
          <div class="tp-depts">
            <?php foreach ($ts['byDepartment'] as $d): ?>
              <?php $dc = $tpTone((int) $d['percent']); ?>
              <a class="bar-row thin tp-dept-link" href="<?= e($deptUrl($d['department'])) ?>">
                <span class="tp-dept-name truncate"><?= e($d['department']) ?><span class="tp-dept-count"><?= (int) $d['count'] ?></span></span>
                <span class="bar-track"><span class="bar-fill" style="width:<?= min(100, (int) $d['percent']) ?>%;background:<?= $dc ?>"></span></span>
                <span class="bar-num tabular" style="color:<?= $dc ?>"><?= (int) $d['percent'] ?>%</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>


  <!-- Review status — the approval breakdown, kept small -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Review Status</div>
        <div class="card-sub">Records by stage</div>
      </div>
      <span class="tabular card-sub"><?= (int) $totalCount ?></span>
    </div>

    <div class="card-body">
      <?php if ($totalCount === 0): ?>
        <div class="card-sub">No records yet.</div>
      <?php else: ?>
        <div class="rs-bar">
          <?php foreach ($statusColours as $status => $colour): ?>
            <?php if ($breakdown[$status] > 0): ?>
              <span style="flex:<?= $breakdown[$status] ?>;background:<?= $colour ?>" title="<?= $status ?>: <?= (int) $breakdown[$status] ?>"></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div class="rs-legend">
          <?php foreach ($statusColours as $status => $colour): ?>
            <div class="rs-row">
              <span class="rs-dot" style="background:<?= $colour ?>"></span>
              <span class="rs-nm"><?= $status ?></span>
              <span class="rs-n tabular"><?= (int) $breakdown[$status] ?></span>
              <span class="rs-pc tabular faint"><?= round($breakdown[$status] / $totalCount * 100) ?>%</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>


<!-- ==========================================================================
     Fixed target vs what was actually achieved  (Admin / Director only)
     ======================================================================= -->
<?php if ($isOversight): ?>
  <?php
    $attainRows = $data['targetAttainment']['rows'];
    $attain     = $data['targetAttainment']['summary'];

    /*
     * The three bands the Targets page already uses — met, halfway, behind —
     * drawn in this page's status palette so the card sits beside the pipeline
     * doughnut without two greens fighting each other.
     */
    $attainTone = fn (int $percent) => $percent >= 100 ? '#059669' : ($percent >= 50 ? '#FF4F01' : '#DC2626');
    $attainBand = fn (int $percent) => $percent >= 100 ? 'Met' : ($percent >= 50 ? 'Halfway or better' : 'Behind');
  ?>

<div class="mt-5 card" id="targetChart">
  <div class="card-head">
    <div>
      <div class="card-title">Attainment by Target</div>
      <div class="card-sub">
        Each target's achievement summed across departments &middot; <?= e($scopeLabel) ?>
      </div>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('targets.php')) ?>">Open Targets</a>
  </div>

  <?php
    $tFilters = $data['targetFilters'];
    $tOptions = $data['targetOptions'];
    $tActive  = array_filter($tFilters);   // any chart filter currently applied?

    // Each select is the same markup with a different list behind it.
    $chartFilters = [
        ['t_dept',   'dept',   'All departments', $tOptions['departments']],
        ['t_year',   'year',   'All years',       $tOptions['years']],
        ['t_metric', 'metric', 'All metrics',     $tOptions['metrics']],
        ['t_status', 'status', 'All statuses',    $tOptions['statuses']],
    ];
  ?>

  <!--
    The chart's own filters. They submit as a GET form to the same page, so the
    dashboard's department/status scope is carried along in the two hidden
    fields rather than being thrown away every time the chart is narrowed. The
    fragment brings you back to this card instead of the top of the page.
  -->
  <div class="filter-bar" style="border-bottom:1px solid var(--hairline)">
    <form method="get" action="<?= e(url('dashboard.php')) ?>#targetChart" class="chart-filters">
      <input type="hidden" name="department" value="<?= e($data['scope']['department'] ?? '') ?>">
      <input type="hidden" name="status"     value="<?= e($data['scope']['status'] ?? '') ?>">

      <span class="card-sub" style="display:flex;align-items:center;gap:6px">
        <?= icon('filter', 14) ?> Filter
      </span>

      <?php foreach ($chartFilters as [$param, $key, $allLabel, $choices]): ?>
        <select class="select" name="<?= $param ?>" onchange="this.form.submit()"
                aria-label="<?= e($allLabel) ?>" <?= empty($choices) ? 'disabled' : '' ?>>
          <option value=""><?= e($allLabel) ?></option>
          <?php foreach ($choices as $choice): ?>
            <option value="<?= e($choice) ?>" <?= ($tFilters[$key] ?? null) === $choice ? 'selected' : '' ?>>
              <?= e($choice) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endforeach; ?>

      <?php if ($tActive): ?>
        <a class="btn btn-ghost btn-sm"
           href="<?= e(url('dashboard.php')
                 . '?department=' . urlencode((string) ($data['scope']['department'] ?? ''))
                 . '&status=' . urlencode((string) ($data['scope']['status'] ?? ''))) ?>#targetChart">
          Clear
        </a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card-body">
    <?php if (empty($attainRows)): ?>

      <!-- Filtered down to nothing reads very differently from having no
           targets at all, so the two say different things. -->
      <div class="empty">
        <div class="ic"><?= icon($tActive ? 'filter' : 'target', 20) ?></div>
        <?php if ($tActive): ?>
          <p>No target matches these filters</p>
          <div class="note">Widen one of them, or clear the filters to see every target again.</div>
        <?php else: ?>
          <p>Nothing to measure yet</p>
          <div class="note">Set a target on the Targets page and it is tracked here against approved records.</div>
        <?php endif; ?>
      </div>

    <?php else: ?>

      <?php $underReview = (int) $attain['targets'] - (int) $attain['frozen']; ?>

      <!-- Summary tiles: the headline reading, then how the targets stand -->
      <div class="attain-tiles">
        <div class="attain-tile hero">
          <div class="k">Overall attainment</div>
          <div class="v" style="color:<?= $attainTone($attain['percent']) ?>"><?= (int) $attain['percent'] ?>%</div>
          <div class="tile-bar">
            <span style="width:<?= min(100, (int) $attain['percent']) ?>%;background:<?= $attainTone($attain['percent']) ?>"></span>
          </div>
          <div class="sub"><?= (int) $attain['achieved'] ?> of <?= (int) $attain['target'] ?> records</div>
        </div>
        <div class="attain-tile">
          <div class="k">Targets met</div>
          <div class="v"><?= (int) $attain['met'] ?> <span class="of">/ <?= (int) $attain['targets'] ?></span></div>
          <div class="sub">reached 100%</div>
        </div>
        <div class="attain-tile">
          <div class="k">Frozen</div>
          <div class="v"><?= (int) $attain['frozen'] ?> <span class="of">/ <?= (int) $attain['targets'] ?></span></div>
          <div class="sub">approved commitments</div>
        </div>
        <div class="attain-tile">
          <div class="k">Under review</div>
          <div class="v"><?= $underReview ?></div>
          <div class="sub">not frozen yet</div>
        </div>
      </div>

      <?php if ($attain['targets'] > count($attainRows)): ?>
        <div class="card-sub" style="margin:2px 0 12px">
          Every target folded across departments &middot; furthest behind first
        </div>
      <?php endif; ?>

      <!-- Scale: every bar's track runs 0 → the target, so 100% = target met -->
      <div class="attain-scale">
        <span>0</span><span class="col2">50%</span><span>Target</span>
      </div>

      <!-- One bar per TARGET TYPE, summed over every department: the institution
           picture of what is being achieved and what is falling behind. -->
      <div class="attain-list attain-scroll">
        <?php foreach ($data['targetAttainment']['byMetric'] as $m): ?>
          <?php
            $pct    = (int) $m['percent'];
            $barPct = max(2, min(100, $pct));
            $tone   = $attainTone($pct);
          ?>
          <div class="attain-row">
            <div class="attain-info">
              <div class="nm"><?= e($m['metric']) ?></div>
              <div class="scope">
                <span class="st" style="color:<?= $tone ?>"><?= e($attainBand($pct)) ?></span>
                &middot; <?= (int) $m['met'] ?>/<?= (int) $m['count'] ?> dept<?= $m['count'] === 1 ? '' : 's' ?> met
              </div>
            </div>

            <div class="attain-track<?= $pct > 100 ? ' beat' : '' ?>"
                 title="<?= (int) $m['achieved'] ?> of <?= (int) $m['target'] ?> (<?= $pct ?>%)">
              <span class="attain-fill" style="width:<?= $barPct ?>%;background:<?= $tone ?>"></span>
            </div>

            <div class="attain-nums">
              <div class="pct" style="color:<?= $tone ?>"><?= $pct ?>%</div>
              <div class="frac"><?= (int) $m['achieved'] ?> / <?= (int) $m['target'] ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="attain-foot">
        <span class="attain-key"><span class="sw" style="background:#059669"></span> Met</span>
        <span class="attain-key"><span class="sw" style="background:#FF4F01"></span> Halfway or better</span>
        <span class="attain-key"><span class="sw" style="background:#DC2626"></span> Behind</span>
      </div>

    <?php endif; ?>
  </div>
</div>
<?php endif; ?>


<!-- ==========================================================================
     Every department, and how many records of each kind it has
     ======================================================================= -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Department Breakdown</div>
      <div class="card-sub">The story of what each department contributes</div>
    </div>
    <?php if (!empty($rows)): ?>
      <div class="story-legend">
        <span><i style="background:#2563EB"></i>Faculty</span>
        <span><i style="background:#FF4F01"></i>Activities</span>
        <span><i style="background:#059669"></i>Student</span>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <?php if (empty($rows)): ?>

      <div class="empty">
        <div class="ic"><?= icon('building', 20) ?></div>
        <p>No records to break down yet</p>
      </div>

    <?php else: ?>

      <?php
        $metrics = $data['matrix']['metrics'];

        // Fold each metric into its category so a department reads as a mix of
        // Faculty / Activities / Student, not seventeen separate numbers.
        $metricGroup = [];
        foreach ($metrics as $m) { $metricGroup[$m['key']] = $m['group']; }

        $catTotals = ['faculty' => 0, 'activity' => 0, 'student' => 0];
        $comp = [];
        foreach ($rows as $row) {
            $c = ['faculty' => 0, 'activity' => 0, 'student' => 0];
            foreach ($row['counts'] as $k => $n) { $c[$metricGroup[$k] ?? 'faculty'] += $n; }
            foreach ($c as $g => $n) { $catTotals[$g] += $n; }
            $comp[] = ['department' => $row['department'], 'total' => $row['total'], 'cat' => $c];
        }
        usort($comp, fn($a, $b) => $b['total'] <=> $a['total']);

        $grand        = array_sum(array_column($comp, 'total'));
        $maxDeptTotal = max(1, ...array_column($comp, 'total'));
        $busiest      = $comp[0];

        $catMeta = ['faculty' => 'Faculty contributions', 'activity' => 'activities & outreach', 'student' => 'student records'];
        $leadKey = 'faculty'; $leadMax = -1;
        foreach ($catTotals as $g => $n) { if ($n > $leadMax) { $leadMax = $n; $leadKey = $g; } }
        $studentShare = $grand > 0 ? (int) round($catTotals['student'] / $grand * 100) : 0;

        // Most-used single metric, for a third insight.
        $metricLabel = [];
        foreach ($metrics as $m) { $metricLabel[$m['key']] = $m['label']; }
        $colTot = array_fill_keys(array_keys($metricLabel), 0);
        foreach ($rows as $row) { foreach ($row['counts'] as $k => $n) { $colTot[$k] += $n; } }
        arsort($colTot);
        $topMetricKey = array_key_first($colTot);
      ?>

      <!-- The narrative: the numbers, said in a sentence -->
      <p class="story-lead">
        Across <b><?= count($comp) ?></b> departments and <b><?= number_format($grand) ?></b> records,
        <b><?= e($busiest['department']) ?></b> is the most active with <b><?= (int) $busiest['total'] ?></b>.
        <b><?= ucfirst($catMeta[$leadKey]) ?></b> lead the mix, and student records make up
        <b><?= $studentShare ?>%</b> of all activity.
      </p>

      <!-- Insight chips -->
      <div class="story-insights">
        <div class="story-card">
          <div class="si-ic" style="background:#FEF2E9;color:#FF4F01"><?= icon('building', 18) ?></div>
          <div><div class="si-k">Most active</div><div class="si-v"><?= e($busiest['department']) ?></div><div class="si-sub"><?= (int) $busiest['total'] ?> records</div></div>
        </div>
        <div class="story-card">
          <div class="si-ic" style="background:#EAF0FE;color:#2563EB"><?= icon('layers', 18) ?></div>
          <div><div class="si-k">Leading area</div><div class="si-v" style="text-transform:capitalize"><?= e(explode(' ', $catMeta[$leadKey])[0]) ?></div><div class="si-sub"><?= (int) $catTotals[$leadKey] ?> records</div></div>
        </div>
        <div class="story-card">
          <div class="si-ic" style="background:#E7F6EF;color:#059669"><?= icon('users', 18) ?></div>
          <div><div class="si-k">Student share</div><div class="si-v"><?= $studentShare ?>%</div><div class="si-sub"><?= (int) $catTotals['student'] ?> student records</div></div>
        </div>
        <div class="story-card">
          <div class="si-ic" style="background:#EAF0FE;color:#2563EB"><?= icon('reports', 18) ?></div>
          <div><div class="si-k">Top metric</div><div class="si-v" style="font-size:15px"><?= e($metricLabel[$topMetricKey]) ?></div><div class="si-sub"><?= (int) $colTot[$topMetricKey] ?> records</div></div>
        </div>
      </div>

      <!-- Composition bars: each department's size and its faculty/activity/student mix -->
      <div class="story-bars">
        <?php foreach ($comp as $d): ?>
          <?php $c = $d['cat']; ?>
          <a class="sb-row" href="<?= e($deptUrl($d['department'])) ?>" title="Open <?= e($d['department']) ?> — <?= (int) $c['faculty'] ?> faculty · <?= (int) $c['activity'] ?> activities · <?= (int) $c['student'] ?> student">
            <div class="sb-name"><?= e($d['department']) ?></div>
            <div class="sb-track">
              <div class="sb-bar" style="width:<?= round($d['total'] / $maxDeptTotal * 100, 1) ?>%">
                <?php if ($c['faculty']): ?><span class="seg" style="flex:<?= $c['faculty'] ?>;background:#2563EB"></span><?php endif; ?>
                <?php if ($c['activity']): ?><span class="seg" style="flex:<?= $c['activity'] ?>;background:#FF4F01"></span><?php endif; ?>
                <?php if ($c['student']): ?><span class="seg" style="flex:<?= $c['student'] ?>;background:#059669"></span><?php endif; ?>
              </div>
            </div>
            <div class="sb-total tabular"><?= (int) $d['total'] ?></div>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- The exact numbers, on demand -->
      <details class="story-detail">
        <summary><?= icon('reports', 14) ?> Show the full per-metric table</summary>
        <?php
          $colTotals  = array_fill_keys(array_column($metrics, 'key'), 0);
          $grandTotal = 0;
          $cellMax = 0;
          foreach ($rows as $row) { foreach ($row['counts'] as $c2) { if ($c2 > $cellMax) { $cellMax = $c2; } } }
          $groupTitles = ['faculty' => 'Faculty Contributions', 'activity' => 'Activities & Outreach', 'student' => 'Student Records'];
          $groupSpan = [];
          foreach ($metrics as $metric) { $groupSpan[$metric['group']] = ($groupSpan[$metric['group']] ?? 0) + 1; }
        ?>
        <div class="table-wrap">
          <table class="data wide">
            <thead>
              <tr class="group-row">
                <th></th>
                <?php foreach ($groupTitles as $gk => $gt): ?>
                  <?php if (!empty($groupSpan[$gk])): ?><th class="num group-th" colspan="<?= (int) $groupSpan[$gk] ?>"><?= e($gt) ?></th><?php endif; ?>
                <?php endforeach; ?>
                <th></th>
              </tr>
              <tr>
                <th>Department</th>
                <?php foreach ($metrics as $metric): ?><th class="num"><?= e($metric['label']) ?></th><?php endforeach; ?>
                <th class="num">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php $grandTotal += $row['total']; ?>
                <tr>
                  <td class="fw-500 nowrap"><?= e($row['department']) ?></td>
                  <?php foreach ($metrics as $metric): ?>
                    <?php
                      $count = $row['counts'][$metric['key']] ?? 0;
                      $colTotals[$metric['key']] += $count;
                      $intensity = $cellMax > 0 ? $count / $cellMax : 0;
                      $style = $count > 0
                          ? 'background:rgba(37,99,235,' . round(0.10 + 0.55 * $intensity, 3) . ')'
                            . ($intensity > 0.55 ? ';color:#fff;font-weight:600' : '')
                          : '';
                    ?>
                    <td class="num tabular heat-cell <?= $count ? '' : 'faint' ?>" style="<?= $style ?>" title="<?= e($metric['label']) ?>: <?= (int) $count ?>"><?= $count ?: '·' ?></td>
                  <?php endforeach; ?>
                  <td class="num tabular fw-600"><?= (int) $row['total'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td class="fw-600">All departments</td>
                <?php foreach ($metrics as $metric): ?><td class="num tabular fw-600"><?= $colTotals[$metric['key']] ?: '&mdash;' ?></td><?php endforeach; ?>
                <td class="num tabular fw-700"><?= (int) $grandTotal ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </details>

    <?php endif; ?>
  </div>
</div>


<!-- ==========================================================================
     Latest submissions, and who is on the system
     ======================================================================= -->
<div class="mt-5 <?= $isOversight ? 'grid-2-1' : 'grid-1' ?>">

  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Recent Activity</div>
        <div class="card-sub">Latest submissions</div>
      </div>
      <?php if ($isReviewer): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('approvals.php')) ?>">Review</a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <?php if (empty($data['recent'])): ?>

        <div class="card-sub">No activity yet.</div>

      <?php else: ?>

        <?php foreach ($data['recent'] as $record): ?>
          <div class="list-row">
            <div class="min-w-0">
              <div class="t truncate"><?= e($record['title']) ?></div>
              <div class="card-sub">
                <?= e($record['metric']) ?><?= $record['department'] ? ' &middot; ' . e($record['department']) : '' ?>
                &middot; <?= e(time_ago($record['at'])) ?>
              </div>
            </div>
            <span class="badge badge-<?= status_class($record['status']) ?>">
              <?= e($record['status']) ?>
            </span>
          </div>
        <?php endforeach; ?>

      <?php endif; ?>
    </div>
  </div>


  <?php if ($isOversight): ?>
    <!-- How many accounts of each role -->
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Team</div>
          <div class="card-sub">Accounts by role</div>
        </div>
        <span class="tabular card-sub"><?= array_sum($data['usersByRole']) ?> total</span>
      </div>

      <div class="card-body">
        <?php $maxRole = max(1, ...array_values($data['usersByRole'])); ?>

        <?php foreach ($data['usersByRole'] as $role => $count): ?>
          <div class="bar-row thin">
            <span class="bar-label"><?= e($role) ?></span>
            <span class="bar-track">
              <span class="bar-fill" style="width:<?= round($count / $maxRole * 100) ?>%"></span>
            </span>
            <span class="bar-num tabular"><?= (int) $count ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>


<?php if ($showDepartments): ?>
<script>
  /*
   * Sort a table by one of its columns.
   *
   * Every cell carries a data-value attribute holding the plain value to
   * compare, so the sort never has to read the styled contents. Clicking the
   * same heading twice reverses the order.
   */
  function sortTable(tableId, column) {
    var table = document.getElementById(tableId);
    var body  = table.tBodies[0];
    var rows  = Array.prototype.slice.call(body.rows);

    // Remember the direction on the table itself.
    var descending = table.dataset.sortedBy == column && table.dataset.direction != 'desc';
    table.dataset.sortedBy  = column;
    table.dataset.direction = descending ? 'desc' : 'asc';

    rows.sort(function (a, b) {
      var x = a.cells[column].dataset.value;
      var y = b.cells[column].dataset.value;

      // Numbers compare as numbers, anything else as text.
      var result = isNaN(x) || isNaN(y) ? x.localeCompare(y) : x - y;
      return descending ? -result : result;
    });

    rows.forEach(function (row) { body.appendChild(row); });
  }
</script>
<?php endif; ?>
