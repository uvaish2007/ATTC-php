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
    'Journals'    => 'Journals',   'Books'   => 'Books',  'Conferences' => 'Conf.',
    'Patents'     => 'Patents',    'FDP'     => 'FDP',    'MoUs'        => 'MoUs',
    'Events'      => 'Events',     'NPTEL'   => 'NPTEL',
    'Internships' => 'Interns',    'Placements' => 'Placed',
];

// The counter cards. Some only make sense for certain roles.
// label, value, icon, icon colour, small caption
$cards = [];
$cards[] = ['Total Records', $stats['totalRecords'], 'layers', 'brand', $scopeLabel];

if ($isReviewer) {
    $pending = $stats['pendingApprovals'];
    $cards[] = ['Pending Approvals', $pending, 'clock', $pending ? 'brand' : 'navy',
                $pending ? 'Awaiting review' : 'All clear'];
}
if ($isOversight) {
    $cards[] = ['Departments', $stats['departments'], 'building', 'navy', 'Active departments'];
}

$cards[] = ['Metrics', $stats['metrics'], 'settings', 'navy', 'Record types'];

if ($isOversight) {
    $cards[] = ['Users', $stats['users'], 'users', 'navy', 'Registered accounts'];
}

$cards[] = ['Targets', $stats['targets'], 'target', 'navy', 'Configured targets'];

// The department table only earns its place when there are several to compare.
$rows            = $data['matrix']['rows'];
$showDepartments = $isOversight && count($rows) > 1;
?>

<div class="page-head">
  <div>
    <h1><?= e($pageTitle) ?></h1>
    <div class="sub">
      <?= $isOversight ? 'Institution-wide overview' : 'Department overview' ?>
      &middot; <?= e($scopeLabel) ?><?= $data['scope']['status'] ? ' &middot; ' . e($data['scope']['status']) : '' ?>
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


<!-- Counters -->
<div class="stat-grid <?= $isOversight ? 'grid-6' : 'grid-4' ?>">

  <?php foreach ($cards as [$label, $value, $iconName, $tone, $caption]): ?>
    <div class="stat">
      <div class="stat-top">
        <div class="stat-label"><?= e($label) ?></div>
        <div class="stat-ic <?= $tone ?>"><?= icon($iconName) ?></div>
      </div>
      <div class="stat-value tabular"><?= (int) $value ?></div>
      <div class="stat-desc"><?= e($caption) ?></div>
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
                <tr>
                  <td class="faint tabular" data-value="<?= $index + 1 ?>"><?= $index + 1 ?></td>
                  <td class="fw-500 nowrap" data-value="<?= e($row['department']) ?>"><?= e($row['department']) ?></td>
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


  <!-- Records by metric, drawn as an SVG column chart -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Records by Metric</div>
        <div class="card-sub">Totals for the current scope</div>
      </div>
      <span class="tabular card-sub">
        <?= array_sum(array_map('intval', $data['chartData']['values'])) ?> total
      </span>
    </div>

    <div class="card-body">
      <?php
        // ---- Put each label beside its value ----
        $bars = [];
        foreach ($data['chartData']['labels'] as $i => $label) {
            $bars[] = [$label, (int) $data['chartData']['values'][$i]];
        }

        $maxValue = max(1, ...array_column($bars, 1));

        // Round the top of the scale up to a tidy number. Every value in this
        // ladder divides by 4, so the four grid lines below always land on
        // whole numbers rather than something like 1.25.
        $scaleTop = $maxValue;
        foreach ([4, 8, 12, 20, 40, 80, 200, 400, 800, 2000, 4000, 8000] as $step) {
            if ($step >= $maxValue) {
                $scaleTop = $step;
                break;
            }
        }

        // ---- Drawing area, in the coordinates of the viewBox below ----
        $left     = 40;    // room for the numbers down the left
        $right    = 716;
        $top      = 24;
        $baseline = 244;

        $slot     = ($right - $left) / max(1, count($bars));
        $barWidth = min(24, $slot - 16);   // thin bars, never fatter than 24
      ?>

      <svg class="chart" viewBox="0 0 720 288" role="img"
           aria-label="Number of records for each metric">

        <!-- Grid lines, one every quarter of the scale -->
        <?php for ($i = 0; $i <= 4; $i++): ?>
          <?php
            $value = $scaleTop / 4 * $i;
            $y     = $baseline - ($baseline - $top) * ($i / 4);
          ?>
          <line class="chart-grid" x1="<?= $left ?>" y1="<?= round($y, 1) ?>" x2="<?= $right ?>" y2="<?= round($y, 1) ?>"></line>
          <text class="chart-axis" x="<?= $left - 8 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= round($value) ?></text>
        <?php endfor; ?>

        <!-- One column per metric -->
        <?php foreach ($bars as $index => [$label, $value]): ?>
          <?php
            $x      = $left + $slot * $index + ($slot - $barWidth) / 2;
            $height = ($baseline - $top) * ($value / $scaleTop);
            $barTop = $baseline - $height;
            $middle = $x + $barWidth / 2;
          ?>

          <?php if ($value > 0): ?>
            <path class="chart-bar" d="<?= bar_path($x, $barTop, $barWidth, $baseline) ?>">
              <title><?= e($label) ?>: <?= (int) $value ?></title>
            </path>
            <text class="chart-value" x="<?= round($middle, 1) ?>" y="<?= round($barTop - 8, 1) ?>" text-anchor="middle"><?= (int) $value ?></text>
          <?php endif; ?>

          <text class="chart-axis" x="<?= round($middle, 1) ?>" y="<?= $baseline + 20 ?>" text-anchor="middle">
            <?= e($shortNames[$label] ?? $label) ?>
          </text>
        <?php endforeach; ?>

        <!-- The baseline itself -->
        <line class="chart-base" x1="<?= $left ?>" y1="<?= $baseline ?>" x2="<?= $right ?>" y2="<?= $baseline ?>"></line>
      </svg>
    </div>
  </div>

</div>


<!-- ==========================================================================
     Where records sit in the review process, and how targets are doing
     ======================================================================= -->
<div class="mt-5 grid-1-1">

  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Submission Pipeline</div>
        <div class="card-sub">Every record by review status</div>
      </div>
    </div>

    <div class="card-body">
      <?php
        $breakdown  = $data['statusBreakdown'];
        $totalCount = array_sum($breakdown);
      ?>

      <?php if ($totalCount === 0): ?>

        <div class="empty">
          <div class="ic"><?= icon('file-stack', 20) ?></div>
          <p>No records yet</p>
          <div class="note">Submissions appear here as soon as somebody uploads one.</div>
        </div>

      <?php else: ?>

        <div class="donut-wrap">
          <?php
            // The ring is one circle per status. Each is drawn as a dash whose
            // length is that status's share of the circle, then pushed round by
            // however much has already been drawn.
            $radius        = 60;
            $circumference = 2 * M_PI * $radius;   // ≈ 377
            $drawn         = 0;                    // how far round we have got
          ?>

          <svg class="donut" viewBox="0 0 160 160" role="img"
               aria-label="Records by review status">
            <!-- The track behind the slices -->
            <circle cx="80" cy="80" r="<?= $radius ?>" class="donut-track"></circle>

            <g transform="rotate(-90 80 80)"><!-- start at 12 o'clock -->
              <?php foreach ($statusColours as $status => $colour): ?>
                <?php if ($breakdown[$status] > 0): ?>
                  <?php
                    $length = $circumference * ($breakdown[$status] / $totalCount);
                    $gap    = 2;   // a hairline of background between slices
                  ?>
                  <circle cx="80" cy="80" r="<?= $radius ?>" class="donut-slice"
                          stroke="<?= $colour ?>"
                          stroke-dasharray="<?= round(max(0.5, $length - $gap), 2) ?> <?= round($circumference, 2) ?>"
                          stroke-dashoffset="<?= round(-$drawn, 2) ?>">
                    <title><?= $status ?>: <?= (int) $breakdown[$status] ?></title>
                  </circle>
                  <?php $drawn += $length; ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </g>

            <text x="80" y="76" text-anchor="middle" class="donut-total"><?= (int) $totalCount ?></text>
            <text x="80" y="94" text-anchor="middle" class="donut-caption">records</text>
          </svg>

          <div class="legend">
            <?php foreach ($statusColours as $status => $colour): ?>
              <div class="legend-row">
                <span class="legend-dot" style="background:<?= $colour ?>"></span>
                <span class="nm"><?= $status ?></span>
                <span class="n tabular"><?= (int) $breakdown[$status] ?></span>
                <span class="pc tabular faint"><?= round($breakdown[$status] / $totalCount * 100) ?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      <?php endif; ?>
    </div>
  </div>


  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Target Progress</div>
        <div class="card-sub">Achieved against what was set</div>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('targets.php')) ?>">Open Targets</a>
    </div>

    <div class="card-body">
      <?php if (empty($data['targetProgress'])): ?>

        <div class="empty">
          <div class="ic"><?= icon('target', 20) ?></div>
          <p>No targets set for this scope</p>
          <div class="note">Set one on the Targets page to track progress here.</div>
        </div>

      <?php else: ?>

        <!-- The scale every bar below is measured against -->
        <div class="scale">
          <?php foreach ([0, 25, 50, 75, 100] as $tick): ?>
            <span><?= $tick ?></span>
          <?php endforeach; ?>
        </div>

        <?php foreach ($data['targetProgress'] as $target): ?>
          <div class="meter-row">
            <div class="meter-head">
              <span class="fw-500 truncate"><?= e($target['metric']) ?></span>
              <span class="card-sub nowrap">
                <?= (int) $target['achieved'] ?> / <?= (int) $target['target'] ?>
              </span>
            </div>
            <div class="meter-track" title="<?= (int) $target['percent'] ?>% of target">
              <span class="meter-fill" style="width:<?= (int) $target['percent'] ?>%"></span>
            </div>
            <div class="meter-foot card-sub">
              <?= e($target['department']) ?><?= $target['year'] ? ' &middot; ' . e($target['year']) : '' ?>
              &middot; <?= (int) $target['percent'] ?>%
            </div>
          </div>
        <?php endforeach; ?>

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

    // Targets store the metric's full name; these fit under a column.
    $targetShort = [
        'Journal Publications'    => 'Journals',  'Book & Book Chapters' => 'Books',
        'Conference Publications' => 'Conf.',     'Patents & Copyrights' => 'Patents',
        'MoU Signed'              => 'MoUs',      'FDP Participation'    => 'FDP',
        'NPTEL'                   => 'NPTEL',     'Students Internship'  => 'Interns',
        'Placements'              => 'Placed',
    ];

    /*
     * Trim a label to the width its column can hold. SVG text does not wrap or
     * clip, so a long department name would otherwise run straight over its
     * neighbour once there are several targets on the chart.
     */
    $fit = function (string $text, float $width, float $perChar): string {
        $max = max(3, (int) floor($width / $perChar));
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    };
  ?>

<div class="mt-5 card" id="targetChart">
  <div class="card-head">
    <div>
      <div class="card-title">Target vs Achieved</div>
      <div class="card-sub">
        Approved records counted against each fixed target &middot; <?= e($scopeLabel) ?>
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

      <div class="attain-head">
        <span class="big tabular" style="color:<?= $attainTone($attain['percent']) ?>"><?= (int) $attain['percent'] ?>%</span>
        <span class="card-sub">
          <?= (int) $attain['achieved'] ?> achieved of <?= (int) $attain['target'] ?> targeted
          &middot; <?= (int) $attain['met'] ?> of <?= (int) $attain['targets'] ?>
          target<?= $attain['targets'] !== 1 ? 's' : '' ?> met
          &middot; <?= (int) $attain['frozen'] ?> frozen
          <?php if ($attain['targets'] > count($attainRows)): ?>
            &middot; showing the <?= count($attainRows) ?> furthest behind
          <?php endif; ?>
        </span>
      </div>

      <?php
        // The same drawing box as the metric chart above, with room underneath
        // for the three label lines each pair carries.
        $left     = 40;
        $right    = 716;
        $top      = 24;
        $baseline = 244;

        $peak = 1;
        foreach ($attainRows as $row) {
            $peak = max($peak, $row['target'], $row['achieved']);
        }

        // Round the top of the scale up to a tidy number that divides by 4, so
        // the four grid lines land on whole numbers.
        $scaleTop = $peak;
        foreach ([4, 8, 12, 20, 40, 80, 200, 400, 800, 2000, 4000, 8000] as $step) {
            if ($step >= $peak) {
                $scaleTop = $step;
                break;
            }
        }

        // Both bars share one scale — that is what makes them comparable, and
        // it lets a beaten target simply out-grow its own plan bar.
        $slot     = ($right - $left) / max(1, count($attainRows));
        $pairGap  = 4;
        $barWidth = max(6, min(22, ($slot - 28 - $pairGap) / 2));
        $pairSpan = $barWidth * 2 + $pairGap;
      ?>

      <svg class="chart" viewBox="0 0 720 312" role="img"
           aria-label="Each fixed target beside the number of records approved against it">

        <!-- Grid lines, one every quarter of the scale -->
        <?php for ($i = 0; $i <= 4; $i++): ?>
          <?php
            $value = $scaleTop / 4 * $i;
            $y     = $baseline - ($baseline - $top) * ($i / 4);
          ?>
          <line class="chart-grid" x1="<?= $left ?>" y1="<?= round($y, 1) ?>" x2="<?= $right ?>" y2="<?= round($y, 1) ?>"></line>
          <text class="chart-axis" x="<?= $left - 8 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= round($value) ?></text>
        <?php endfor; ?>

        <!-- What the numbers down the left actually count -->
        <text class="chart-axis-title" transform="rotate(-90 12 <?= round(($top + $baseline) / 2) ?>)"
              x="12" y="<?= round(($top + $baseline) / 2) ?>" text-anchor="middle">RECORDS</text>

        <!-- One pair of columns per target: the plan, then what landed -->
        <?php foreach ($attainRows as $index => $row): ?>
          <?php
            $groupLeft = $left + $slot * $index + ($slot - $pairSpan) / 2;
            $middle    = $groupLeft + $pairSpan / 2;
            $achievedX = $groupLeft + $barWidth + $pairGap;

            $targetTop   = $baseline - ($baseline - $top) * min(1, $row['target']   / $scaleTop);
            $achievedTop = $baseline - ($baseline - $top) * min(1, $row['achieved'] / $scaleTop);

            $tone  = $attainTone($row['percent']);
            $scope = trim($row['department'] . ($row['year'] ? ' · ' . $row['year'] : ''));
            $state = $row['frozen'] ? 'frozen' : strtolower($row['status']);
          ?>

          <g class="chart-group">
            <!-- Hover band, drawn first so it sits behind the columns -->
            <rect class="chart-hit" x="<?= round($left + $slot * $index, 1) ?>" y="<?= $top ?>"
                  width="<?= round($slot, 1) ?>" height="<?= $baseline - $top ?>">
              <title>
                <?= e($row['metric']) ?><?= $scope ? ' — ' . e($scope) : '' ?>
                &#10;target <?= (int) $row['target'] ?>, achieved <?= (int) $row['achieved'] ?> (<?= (int) $row['percent'] ?>%)
                &#10;<?= e($row['status']) ?><?= $row['frozen'] ? ' — frozen' : ' — not frozen yet' ?>
              </title>
            </rect>

            <?php if ($row['target'] > 0): ?>
              <!-- Solid once frozen; an outline while it is still being agreed -->
              <path class="chart-bar-plan<?= $row['frozen'] ? '' : ' provisional' ?>"
                    d="<?= bar_path($groupLeft, $targetTop, $barWidth, $baseline) ?>">
                <title><?= e($row['metric']) ?> — fixed target: <?= (int) $row['target'] ?> (<?= e($row['status']) ?>)</title>
              </path>
            <?php endif; ?>

            <?php if ($row['achieved'] > 0): ?>
              <path d="<?= bar_path($achievedX, $achievedTop, $barWidth, $baseline) ?>" fill="<?= $tone ?>">
                <title>
                  <?= e($row['metric']) ?> — achieved: <?= (int) $row['achieved'] ?>
                  approved record<?= $row['achieved'] !== 1 ? 's' : '' ?>
                </title>
              </path>
            <?php endif; ?>

            <!-- The percentage rides above whichever of the two bars is taller -->
            <?php if ($row['target'] > 0): ?>
              <text class="chart-pct" x="<?= round($middle, 1) ?>" y="<?= round(min($targetTop, $achievedTop) - 8, 1) ?>"
                    text-anchor="middle" fill="<?= $tone ?>"><?= (int) $row['percent'] ?>%</text>
            <?php endif; ?>

            <text class="chart-axis" x="<?= round($middle, 1) ?>" y="<?= $baseline + 20 ?>" text-anchor="middle">
              <?= e($fit($targetShort[$row['metric']] ?? $row['metric'], $slot - 6, 6.2)) ?>
            </text>
            <text class="chart-note" x="<?= round($middle, 1) ?>" y="<?= $baseline + 36 ?>" text-anchor="middle">
              <?= e($fit($scope, $slot - 6, 5.4)) ?><?= $row['exact'] ? '' : ' *' ?>
            </text>
            <text class="chart-value" x="<?= round($middle, 1) ?>" y="<?= $baseline + 54 ?>" text-anchor="middle">
              <?= (int) $row['achieved'] ?> / <?= (int) $row['target'] ?>
            </text>
          </g>
        <?php endforeach; ?>

        <!-- The baseline itself -->
        <line class="chart-base" x1="<?= $left ?>" y1="<?= $baseline ?>" x2="<?= $right ?>" y2="<?= $baseline ?>"></line>
      </svg>

      <div class="attain-foot">
        <span class="attain-key"><span class="sw" style="background:var(--navy-200)"></span> Fixed target (frozen)</span>
        <span class="attain-key"><span class="sw outline"></span> Still under review</span>
        <span class="attain-key"><span class="sw" style="background:#059669"></span> Met</span>
        <span class="attain-key"><span class="sw" style="background:#FF4F01"></span> Halfway or better</span>
        <span class="attain-key"><span class="sw" style="background:#DC2626"></span> Behind</span>
        <?php if ($attain['approximate']): ?>
          <span>* counted across the whole institution — those records carry no department or year</span>
        <?php endif; ?>
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
      <div class="card-sub">Records by department and metric</div>
    </div>
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

        // Running totals for the bottom row.
        $colTotals  = array_fill_keys(array_column($metrics, 'key'), 0);
        $grandTotal = 0;
      ?>

      <div class="table-wrap">
        <table class="data wide">

          <thead>
            <tr>
              <th>Department</th>
              <?php foreach ($metrics as $metric): ?>
                <th class="num"><?= e($metric['label']) ?></th>
              <?php endforeach; ?>
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
                  ?>
                  <td class="num tabular <?= $count ? '' : 'faint' ?>"><?= $count ?: '&mdash;' ?></td>
                <?php endforeach; ?>

                <td class="num tabular fw-600"><?= (int) $row['total'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>

          <tfoot>
            <tr>
              <td class="fw-600">All departments</td>
              <?php foreach ($metrics as $metric): ?>
                <td class="num tabular fw-600"><?= $colTotals[$metric['key']] ?: '&mdash;' ?></td>
              <?php endforeach; ?>
              <td class="num tabular fw-700"><?= (int) $grandTotal ?></td>
            </tr>
          </tfoot>

        </table>
      </div>

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
