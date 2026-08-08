<?php
/**
 * Faculty — the staff of one department.
 *
 * An HoD uses this to see who is in their department and how much each
 * person has submitted. It is read-only: adding or removing an account is
 * the Admin's job, on users.php.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Record.php';

$user = require_role(['HoD']);
require_module('faculty');

$department = (string) ($user['department'] ?? '');
$search     = trim((string) input('q', ''));

// An HoD with no department set can't be scoped to anything, so show nothing
// rather than the whole institution.
$people = $department !== '' ? users_in_department($department, $search ?: null) : [];

// One trip to the database for everybody's submission counts.
$counts = record_counts_for_users(array_column($people, 'id'));

// ---- Totals for the cards at the top ------------------------------------
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

$pageTitle  = 'Faculty';
$breadcrumb = 'Faculty';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Faculty</h1>
    <div class="sub">
      <?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?> ·
      <?= $department !== '' ? e($department) : 'no department assigned' ?>
    </div>
  </div>

  <div class="actions">
    <form method="get" class="flex gap-2 items-center">
      <input class="input input-sm" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or email…">
      <button type="submit" class="btn btn-outline btn-sm"><?= icon('search', 15) ?> Search</button>
      <?php if ($search !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('faculty.php')) ?>">Clear</a>
      <?php endif; ?>
    </form>
  </div>
</div>


<?php if ($department === ''): ?>

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


  <!-- The people themselves -->
  <div class="mt-5 card">
    <div class="card-head">
      <div>
        <div class="card-title">Department staff</div>
        <div class="card-sub">Counts include every record type, in any status</div>
      </div>
    </div>

    <div class="card-body">
      <?php if (empty($people)): ?>

        <div class="empty">
          <div class="ic"><?= icon('users', 20) ?></div>
          <p><?= $search !== '' ? 'Nobody matches that search' : 'No accounts in this department yet' ?></p>
          <div class="note">
            <?= $search !== ''
                  ? 'Try a shorter search, or clear it.'
                  : 'The Admin adds accounts and assigns them a department.' ?>
          </div>
        </div>

      <?php else: ?>

        <div class="table-wrap">
          <table class="data wide">
            <thead>
              <tr>
                <th>Person</th>
                <th>Role</th>
                <th>Contact</th>
                <th class="num">Records</th>
                <th class="num">Approved</th>
                <th class="num">Pending</th>
                <th>Account</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($people as $person): ?>
                <?php
                  $mine    = $counts[(int) $person['id']] ?? null;
                  $records = $mine['total']     ?? 0;
                  $ok      = $mine['Approved']  ?? 0;
                  $waiting = $mine['Submitted'] ?? 0;
                ?>
                <tr>
                  <td>
                    <div class="flex items-center gap-3">
                      <div class="avatar-dark avatar-sm"><?= e(initials($person['name'])) ?></div>
                      <div class="min-w-0">
                        <div class="fw-500 truncate"><?= e($person['name']) ?></div>
                        <div class="card-sub truncate"><?= e($person['email']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge badge-neutral"><?= e($person['role']) ?></span></td>
                  <td class="faint"><?= $person['phone'] ? e($person['phone']) : '—' ?></td>
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
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php endif; ?>
    </div>
  </div>


  <!-- Where to go next -->
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

<?php require __DIR__ . '/inc/footer.php'; ?>
