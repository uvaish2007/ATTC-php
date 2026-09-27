<?php
$firstName = explode(' ', trim($user['name']))[0] ?: 'there';
$stats     = $data['stats'];

$statusColours = [
    'Approved'     => '#059669',
    'Dean Pending' => '#F59E0B',
    'HOD Pending'  => '#2563EB',
    'Submitted'    => '#7C3AED',
    'Rejected'     => '#DC2626',
    'Draft'        => '#6B7FA8',
];

$cards = [
    ['My Submissions', $stats['totalRecords'], 'file-stack', 'brand', "Records you've filed"],
    ['Approved',       $stats['approved'],     'check',      'navy',  'Accepted records'],
    ['Pending',        $stats['pending'],      'clock',      $stats['pending'] ? 'brand' : 'navy', 'Awaiting review'],
    ['Rejected',       $stats['rejected'],     'x',          'navy',  'Need attention'],
];
?>

<div class="page-head">
  <div>
    <h1>Welcome, <?= e($firstName) ?></h1>
    <div class="sub">Your submissions and their status</div>
  </div>
  <div class="actions">
    <form method="get" class="fbar fbar-bare">
      <label class="fb-field" title="Filter by Academic Year">
        <?= icon('calendar', 14) ?><span class="fb-k">Academic Year</span>
        <select name="academic_year" onchange="this.form.submit()">
          <?php foreach (($data['years'] ?? academic_years()) as $y): ?>
            <option value="<?= e($y) ?>" <?= ($data['scope']['year'] ?? '') === $y ? 'selected' : '' ?>>
              <?= e($y) ?><?= $y === ($data['activeYear'] ?? '') ? ' (Active)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php ?>
      <label class="fb-field" title="Executive Meeting duration — filter records by EM1 or EM2 period">
        <span class="fb-k">EM Duration</span>
        <select name="em" onchange="this.form.submit()">
          <option value="all" <?= ($data['scope']['em'] ?? 'all') === 'all' ? 'selected' : '' ?>>All</option>
          <?php foreach (array_keys(EM_MEETINGS) as $emKey): ?>
            <option value="<?= e($emKey) ?>" <?= ($data['scope']['em'] ?? '') === $emKey ? 'selected' : '' ?>>
              <?= e(em_filter_label($emKey, $data['scope']['year'] ?? null)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
    <a class="btn btn-primary" href="<?= e(nav_href('upload.php?reset=1')) ?>">
      <?= icon('upload') ?> Submit a Record
    </a>
  </div>
</div>

<?php require __DIR__ . '/em_status_card.php'; ?>

<!-- Four counters across the top -->
<div class="stat-grid grid-4">

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

<div class="mt-5 grid-2-1">

  <!-- What I sent in recently -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">My Recent Submissions</div>
        <div class="card-sub">The last records you filed</div>
      </div>
    </div>

    <div class="card-body">
      <?php if (empty($data['recent'])): ?>

        <div class="empty">
          <div class="ic"><?= icon('file-stack', 20) ?></div>
          <p>You haven't submitted anything yet</p>
        </div>

      <?php else: ?>

        <?php foreach ($data['recent'] as $record): ?>
          <div class="list-row">
            <div class="min-w-0">
              <div class="t truncate"><?= e($record['title']) ?></div>
              <div class="card-sub">
                <?= e($record['metric']) ?> &middot; <?= e(time_ago($record['at'])) ?>
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

  <!-- How many of each kind -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">By Type</div>
        <div class="card-sub">Your records per category</div>
      </div>
    </div>

    <div class="card-body">
      <?php $submitted = array_filter($data['totals'], fn($n) => $n > 0); ?>

      <?php if (!$submitted): ?>

        <div class="card-sub">Nothing submitted yet.</div>

      <?php else: ?>

        <?php $mostOfOne = max($submitted); ?>

        <?php foreach ($submitted as $key => $count): ?>
          <div class="bar-row thin">
            <span class="bar-label truncate"><?= e($data['metricLabels'][$key] ?? $key) ?></span>
            <span class="bar-track">
              <span class="bar-fill" style="width:<?= round($count / $mostOfOne * 100) ?>%"></span>
            </span>
            <span class="bar-num tabular"><?= (int) $count ?></span>
          </div>
        <?php endforeach; ?>

      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Where your records stand, and what to do next -->
<div class="mt-5 grid-1-1">

  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Your Submissions by Status</div>
        <div class="card-sub">Where each record has got to</div>
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
          <p>Nothing to show yet</p>
          <div class="note">Your first upload will appear here.</div>
        </div>

      <?php else: ?>

        <div class="donut-wrap">
          <?php

            $radius        = 60;
            $circumference = 2 * M_PI * $radius;
            $drawn         = 0;
          ?>

          <svg class="donut" viewBox="0 0 160 160" role="img"
               aria-label="Your records by review status">
            <circle cx="80" cy="80" r="<?= $radius ?>" class="donut-track"></circle>

            <g transform="rotate(-90 80 80)"><!-- start at 12 o'clock -->
              <?php foreach ($statusColours as $status => $colour): ?>
                <?php $cnt = (int) ($breakdown[$status] ?? 0); ?>
                <?php if ($cnt > 0): ?>
                  <?php $length = $circumference * ($cnt / $totalCount); ?>
                  <circle cx="80" cy="80" r="<?= $radius ?>" class="donut-slice"
                          stroke="<?= $colour ?>"
                          stroke-dasharray="<?= round(max(0.5, $length - 2), 2) ?> <?= round($circumference, 2) ?>"
                          stroke-dashoffset="<?= round(-$drawn, 2) ?>">
                    <title><?= $status ?>: <?= $cnt ?></title>
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
              <?php $cnt = (int) ($breakdown[$status] ?? 0); ?>
              <div class="legend-row">
                <span class="legend-dot" style="background:<?= $colour ?>"></span>
                <span class="nm"><?= $status ?></span>
                <span class="n tabular"><?= $cnt ?></span>
                <span class="pc tabular faint"><?= $totalCount > 0 ? round($cnt / $totalCount * 100) : 0 ?>%</span>
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
        <div class="card-title">What next</div>
        <div class="card-sub">The things you can do from here</div>
      </div>
    </div>

    <div class="card-body">
      <?php
        $next = [
            ['Submit a record', 'Add a publication, FDP, patent or any entry.', 'upload',    'upload.php'],
            ['Read announcements', 'Notices and deadlines from the Principal\'s office.', 'megaphone', 'announcements.php'],
            ['Your profile', 'Update your details or change your password.', 'user', 'profile.php'],
        ];
      ?>

      <?php foreach ($next as [$label, $description, $iconName, $page]): ?>
        <a class="list-row" href="<?= e(nav_href($page)) ?>">
          <span class="flex items-center gap-3">
            <span class="stat-ic navy"><?= icon($iconName, 16) ?></span>
            <span class="min-w-0">
              <span class="t"><?= e($label) ?></span>
              <span class="card-sub"><?= e($description) ?></span>
            </span>
          </span>
          <?= icon('chevron', 14) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

</div>
