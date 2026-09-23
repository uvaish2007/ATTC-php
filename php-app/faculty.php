<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Record.php';

$user = require_role(['HoD', 'Coordinator', 'Admin', 'Principal', 'Director', 'Dean']);
require_module('faculty');

$seesEveryone = in_array($user['role'], ['Admin', 'Principal', 'Director', 'Dean'], true);

$department = (string) ($user['department'] ?? '');
$search     = trim((string) input('q', ''));

// Only an oversight role may narrow the list to a department of its choice.
// Everybody else is pinned to their own, whatever the query string says.
$deptFilter = $seesEveryone ? trim((string) input('dept', '')) : $department;

// Teaching staff only — Admin and Principal accounts are not faculty.
$teachingRoles = ['HoD', 'Coordinator', 'Faculty'];

if ($seesEveryone) {
    $people = users_all(null, $deptFilter !== '' ? $deptFilter : null, $search ?: null);
    $people = array_values(array_filter(
        $people,
        static fn(array $p): bool => in_array($p['role'], $teachingRoles, true)
    ));

    usort($people, static function (array $a, array $b) use ($teachingRoles): int {
        return [$a['department'] ?? '', array_search($a['role'], $teachingRoles, true), $a['name']]
           <=> [$b['department'] ?? '', array_search($b['role'], $teachingRoles, true), $b['name']];
    });
} else {
    $people = $department !== '' ? users_in_department($department, $search ?: null) : [];
}

$deptChoices = [];
if ($seesEveryone) {
    $deptChoices = db()
        ->query("SELECT DISTINCT department FROM users
                  WHERE department IS NOT NULL AND department <> ''
                    AND role IN ('HoD','Coordinator','Faculty')
                  ORDER BY department")
        ->fetchAll(PDO::FETCH_COLUMN);
}

$counts = record_counts_for_users(array_column($people, 'id'));
user_photos_preload(array_column($people, 'id'));

// Group the list by department, the way the Users page does, so a roll of
// sixty names reads as seventeen departments instead of one long table.
require_once __DIR__ . '/models/Department.php';

// users.department holds a code on some rows ("AERO") and a full name on
// others ("AGRICULTURE"), so index each department under both and resolve a
// person by whichever they carry.
$deptKey   = static fn(string $v): string => mb_strtolower(trim($v));
$deptIndex = [];
$deptOrder = [];
foreach (departments_all() as $i => $d) {
    foreach ([$d['code'] ?? '', $d['name'] ?? ''] as $alias) {
        $alias = $deptKey((string) $alias);
        if ($alias === '') {
            continue;
        }
        $deptIndex[$alias] = $d;
        $deptOrder[$alias] = $i;
    }
}

$roleRank = ['HoD' => 0, 'Coordinator' => 1, 'Faculty' => 2];

$facultyGroups = [];
foreach ($people as $person) {
    $rawDept = trim((string) ($person['department'] ?? ''));
    $alias   = $deptKey($rawDept);
    $known   = $deptIndex[$alias] ?? null;

    // One key per department, whichever spelling the person's row used.
    $key = $rawDept === ''
        ? '__unassigned'
        : ($known ? $deptKey((string) $known['code']) : $alias);

    if (!isset($facultyGroups[$key])) {
        if ($key === '__unassigned') {
            $facultyGroups[$key] = ['kind' => 'unassigned', 'name' => 'No department set', 'code' => null];
        } elseif ($known) {
            $facultyGroups[$key] = ['kind' => 'department', 'name' => $known['name'],
                                    'code' => $known['code']];
        } else {
            $facultyGroups[$key] = ['kind' => 'unlisted', 'name' => $rawDept, 'code' => null];
        }
        $facultyGroups[$key] += ['key' => $key, 'people' => [], 'roles' => [],
                                 'records' => 0, 'approved' => 0, 'pending' => 0];
    }

    $facultyGroups[$key]['people'][] = $person;

    $role = (string) $person['role'];
    $facultyGroups[$key]['roles'][$role] = ($facultyGroups[$key]['roles'][$role] ?? 0) + 1;

    $mine = $counts[(int) $person['id']] ?? null;
    $facultyGroups[$key]['records']  += $mine['total']     ?? 0;
    $facultyGroups[$key]['approved'] += $mine['Approved']  ?? 0;
    $facultyGroups[$key]['pending']  += $mine['Submitted'] ?? 0;
}

// Departments in their configured order; anything off the list, then the
// unassigned bucket, last.
uasort($facultyGroups, static function (array $a, array $b) use ($deptOrder): int {
    $rank = static fn(array $g): int => $g['kind'] === 'unassigned' ? 2 : ($g['kind'] === 'unlisted' ? 1 : 0);
    return [$rank($a), $deptOrder[$a['key']] ?? PHP_INT_MAX, $a['name']]
       <=> [$rank($b), $deptOrder[$b['key']] ?? PHP_INT_MAX, $b['name']];
});

foreach ($facultyGroups as &$g) {
    usort($g['people'], static fn($x, $y) => [$roleRank[$x['role']] ?? 9, $x['name']]
                                         <=> [$roleRank[$y['role']] ?? 9, $y['name']]);
    $g['noHod'] = empty($g['roles']['HoD']) && $g['kind'] === 'department';
}
unset($g);


$totals = ['records' => 0, 'Approved' => 0, 'Submitted' => 0];

foreach ($counts as $row) {
    $totals['records']   += $row['total'];
    $totals['Approved']  += $row['Approved'];
    $totals['Submitted'] += $row['Submitted'];
}

$cards = [
    ['People',          count($people),         'users',     'navy'],
    ['Records',         $totals['records'],     'layers',    'brand'],
    ['Approved',        $totals['Approved'],    'check',     'navy'],
    ['Awaiting review', $totals['Submitted'],   'clock',     $totals['Submitted'] ? 'brand' : 'navy'],
];

$activeFilters = ($search !== '' ? 1 : 0) + ($seesEveryone && $deptFilter !== '' ? 1 : 0);

// Collapsed by default once there are enough groups to be worth scanning;
// a filtered or searched list stays open because it is already narrow.
$openByDefault = $activeFilters > 0 || count($facultyGroups) <= 3;

$pageTitle  = 'Faculty';
$breadcrumb = 'Faculty';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Faculty</h1>
    <div class="sub">
      <?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?> ·
      <?php if ($seesEveryone): ?>
        <?= $deptFilter !== '' ? e($deptFilter) : 'all departments' ?>
      <?php else: ?>
        <?= $department !== '' ? e($department) : 'no department assigned' ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<form method="get" class="fbar">
  <label class="fb-field fb-search">
    <?= icon('search', 15) ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or email…" aria-label="Search faculty">
    <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
  </label>

  <?php if ($seesEveryone && !empty($deptChoices)): ?>
    <label class="fb-field"><span class="fb-k">Department</span>
      <select name="dept" onchange="this.form.submit()" aria-label="Filter by department">
        <option value="">All departments</option>
        <?php foreach ($deptChoices as $choice): ?>
          <option value="<?= e($choice) ?>" <?= $deptFilter === $choice ? 'selected' : '' ?>><?= e($choice) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

    <span class="fbar-end">
      <?php if ($activeFilters): ?>
        <span class="fbar-count"><?= (int) $activeFilters ?> active</span>
        <a class="fbar-clear" href="<?= e(url('faculty.php')) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note"><?= $seesEveryone ? 'Every department' : 'Everyone in your department' ?></span>
      <?php endif; ?>
    </span>
</form>

<?php if (!$seesEveryone && $department === ''): ?>

  <div class="alert alert-warning">
    Your account has no department assigned, so there is nothing to show here.
    Ask the Admin to set your department on the Users page.
  </div>

<?php else: ?>

  <!-- Counters -->
  <div class="stat-grid grid-4">
    <?php foreach ($cards as [$label, $value, $iconName, $tone]): ?>
      <div class="stat">
        <div class="stat-top">
          <div class="stat-label"><?= e($label) ?></div>
          <div class="stat-ic <?= $tone ?>"><?= icon($iconName) ?></div>
        </div>
        <div class="stat-value tabular"><?= (int) $value ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($people)): ?>

    <div class="mt-5 card">
      <div class="card-body">
        <div class="empty">
          <div class="ic"><?= icon('users', 20) ?></div>
          <p><?= $activeFilters ? 'Nobody matches those filters' : 'No faculty accounts yet' ?></p>
          <div class="note">
            <?= $activeFilters
                  ? 'Try a shorter search, or clear the filters.'
                  : 'The Admin adds accounts and assigns them a department.' ?>
          </div>
        </div>
      </div>
    </div>

  <?php else: ?>

    <div class="ug-toolbar mt-5">
      <span class="ug-caption">
        <?= icon('users', 14) ?>
        <?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?>
        across <?= count($facultyGroups) ?> <?= count($facultyGroups) === 1 ? 'group' : 'groups' ?>
        &middot; counts include every record type, in any status
      </span>
      <button type="button" class="btn btn-ghost btn-sm" id="facToggle"><?= icon('layers', 14) ?> Collapse all</button>
    </div>

    <?php foreach ($facultyGroups as $g): ?>
      <details class="card tg-group ug-group" data-ug-key="<?= e($g['key']) ?>" <?= $openByDefault ? 'open' : '' ?>>
        <summary>
          <?php if ($g['kind'] === 'unassigned'): ?>
            <span class="ug-code warn" aria-hidden="true"><?= icon('alert-triangle', 17) ?></span>
          <?php elseif ($g['kind'] === 'unlisted'): ?>
            <span class="ug-code warn" aria-hidden="true"><?= icon('alert-triangle', 17) ?></span>
          <?php else: ?>
            <span class="ug-code" aria-hidden="true"><?= e($g['code'] ?: mb_substr($g['name'], 0, 4)) ?></span>
          <?php endif; ?>

          <span class="ug-head">
            <span class="ug-name">
              <?= e($g['name']) ?>
              <?php if ($g['kind'] === 'unlisted'): ?>
                <span class="badge badge-warning">Not on the Departments list</span>
              <?php endif; ?>
              <?php if ($g['noHod']): ?>
                <span class="badge badge-warning" title="Nobody in this department can review its records">
                  <?= icon('alert-triangle', 11) ?> No HoD
                </span>
              <?php endif; ?>
            </span>
            <span class="ug-roles">
              <?php
                $bits = [];
                foreach ($roleRank as $r => $_) {
                    if (!empty($g['roles'][$r])) {
                        $bits[] = '<b>' . (int) $g['roles'][$r] . '</b> ' . e($r);
                    }
                }
              ?>
              <?= implode(' <span class="ug-dot">&middot;</span> ', $bits) ?>
            </span>
          </span>

          <span class="ug-meta">
            <?php if ($g['pending'] > 0): ?>
              <span class="badge badge-info"><?= (int) $g['pending'] ?> awaiting</span>
            <?php endif; ?>
            <span class="ug-count">
              <b><?= (int) $g['records'] ?></b>
              <span class="ug-count-word">record<?= $g['records'] !== 1 ? 's' : '' ?></span>
            </span>
          </span>
        </summary>

        <div class="table-wrap">
          <table class="data wide ug-table">
            <thead>
              <tr>
                <th>Person</th>
                <th>Role</th>
                <th>Contact</th>
                <th class="num">Records</th>
                <th class="num">Approved</th>
                <th class="num">Pending</th>
                <th>Account</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($g['people'] as $person): ?>
                <?php
                  $personId = (int) $person['id'];
                  $mine     = $counts[$personId] ?? null;
                  $records  = $mine['total']     ?? 0;
                  $ok       = $mine['Approved']  ?? 0;
                  $waiting  = $mine['Submitted'] ?? 0;

                  $photoFile = user_photo_filename($personId);
                  $photoUrl  = $photoFile !== null
                      ? url('photo.php?user=' . $personId . '&v=' . substr(md5($photoFile), 0, 8))
                      : null;
                ?>
                <tr>
                  <td>
                    <div class="flex items-center gap-3">
                      <?php if ($photoUrl !== null): ?>
                        <img class="avatar-dark avatar-sm avatar-photo" src="<?= e($photoUrl) ?>"
                             alt="Photo of <?= e($person['name']) ?>">
                      <?php else: ?>
                        <div class="avatar-dark avatar-sm"><?= e(initials($person['name'])) ?></div>
                      <?php endif; ?>
                      <div class="min-w-0">
                        <div class="ug-person truncate"><?= e($person['name']) ?></div>
                        <div class="card-sub truncate"><?= e($person['email']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge badge-neutral"><?= e($person['role']) ?></span></td>
                  <td class="faint"><?= $person['phone'] ? e($person['phone']) : '&mdash;' ?></td>
                  <td class="num tabular fw-600"><?= (int) $records ?></td>
                  <td class="num tabular"><?= (int) $ok ?></td>
                  <td class="num tabular">
                    <?php if ($waiting > 0): ?>
                      <span class="badge badge-info"><?= (int) $waiting ?></span>
                    <?php else: ?>
                      <span class="faint">0</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int) $person['status'] === 1): ?>
                      <span class="badge badge-success">Active</span>
                    <?php else: ?>
                      <span class="badge badge-danger">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a class="btn btn-outline btn-sm" target="_blank" rel="noopener"
                       href="<?= e(url('faculty-details-report.php?id=' . $personId)) ?>"
                       title="Open <?= e($person['name']) ?>'s Faculty Details as an A4 document">
                      <?= icon('user', 14) ?> Details PDF
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endforeach; ?>

  <?php endif; ?>

  <!-- Where to go next -->
  <?php if (!$seesEveryone): ?>
    <div class="mt-5 grid-1-1">
      <a class="card link-card" href="<?= e(url('approvals.php')) ?>">
        <div class="stat-ic brand"><?= icon('approvals') ?></div>
        <div>
          <div class="card-title">Review submissions</div>
          <div class="card-sub"><?= (int) $totals['Submitted'] ?> waiting for your approval</div>
        </div>
        <span class="link-card-go"><?= icon('chevron', 16) ?></span>
      </a>

      <a class="card link-card" href="<?= e(url('reports.php')) ?>">
        <div class="stat-ic navy"><?= icon('reports') ?></div>
        <div>
          <div class="card-title">Department report</div>
          <div class="card-sub">Download <?= e($department) ?> records as Excel, Word or PDF</div>
        </div>
        <span class="link-card-go"><?= icon('chevron', 16) ?></span>
      </a>
    </div>
  <?php endif; ?>

<?php endif; ?>

<script>
(function () {
  var groups = Array.prototype.slice.call(document.querySelectorAll('.ug-group'));
  var toggle = document.getElementById('facToggle');
  if (!groups.length || !toggle) return;

  var KEY = 'atts.faculty.collapsed';

  function label() {
    var open = groups.filter(function (g) { return g.open; }).length;
    toggle.innerHTML = open ? '<?= icon('layers', 14) ?> Collapse all'
                            : '<?= icon('layers', 14) ?> Expand all';
  }

  // Remember which departments were shut, so the list stays where you left it.
  try {
    var shut = JSON.parse(sessionStorage.getItem(KEY) || '[]');
    groups.forEach(function (g) {
      if (shut.indexOf(g.getAttribute('data-ug-key')) !== -1) g.open = false;
    });
  } catch (e) {}

  function remember() {
    try {
      sessionStorage.setItem(KEY, JSON.stringify(
        groups.filter(function (g) { return !g.open; })
              .map(function (g) { return g.getAttribute('data-ug-key'); })
      ));
    } catch (e) {}
  }

  groups.forEach(function (g) {
    g.addEventListener('toggle', function () { label(); remember(); });
  });

  toggle.addEventListener('click', function () {
    var anyOpen = groups.some(function (g) { return g.open; });
    groups.forEach(function (g) { g.open = !anyOpen; });
    label();
    remember();
  });

  label();
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
