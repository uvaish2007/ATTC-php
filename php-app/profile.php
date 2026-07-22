<?php
/**
 * Profile — your own account.
 *
 * Anyone who is signed in can open this page. It shows who you are, lets you
 * fix your own name / phone / password, and summarises what you have
 * submitted. Role and department are set by the Admin, so they are read-only.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Record.php';

$user = require_login();

// ---- Handle the two forms ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'save_profile') {
        [$ok, $msg] = user_update_profile($user['id'], (string) input('name'), (string) input('phone'));
        flash($ok ? 'success' : 'error', $msg);

        // Keep the sidebar and topbar showing the new name straight away.
        if ($ok) {
            $_SESSION['user']['name'] = trim((string) input('name'));
        }

    } elseif ($action === 'change_password') {
        [$ok, $msg] = user_change_password(
            $user['id'],
            (string) input('current_password'),
            (string) input('new_password'),
            (string) input('confirm_password')
        );
        flash($ok ? 'success' : 'error', $msg);
    }

    redirect('/profile.php');
}

// ---- Everything the page shows -------------------------------------------
$me      = user_find($user['id']);
$counts  = user_record_counts($user['id']);
$records = my_records($user['id']);

// Only the 8 newest are listed; upload.php has the full history.
$recent = array_slice($records, 0, 8);

$cards = [
    ['Submitted in total', $counts['total'],     'layers', 'brand'],
    ['Approved',           $counts['Approved'],  'check',  'navy'],
    ['Awaiting review',    $counts['Submitted'], 'clock',  $counts['Submitted'] ? 'brand' : 'navy'],
    ['Needs a fix',        $counts['Rejected'],  'x',      'navy'],
];

$pageTitle  = 'Profile';
$breadcrumb = 'Profile';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Profile</h1>
    <div class="sub">Your account details and your submissions</div>
  </div>
</div>


<!-- Who you are -->
<div class="card">
  <div class="card-body profile-hero">
    <div class="avatar-xl"><?= e(initials($me['name'])) ?></div>

    <div class="min-w-0">
      <div class="profile-name"><?= e($me['name']) ?></div>
      <div class="profile-meta">
        <span class="badge badge-brand"><?= e($me['role']) ?></span>
        <?php if ($me['department']): ?>
          <span class="faint"><?= icon('building', 14) ?> <?= e($me['department']) ?></span>
        <?php endif; ?>
        <span class="faint"><?= icon('mail', 14) ?> <?= e($me['email']) ?></span>
        <?php if ($me['phone']): ?>
          <span class="faint"><?= icon('phone-icon', 14) ?> <?= e($me['phone']) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-sub mt-2">
        Member since <?= e(date('d M Y', strtotime($me['created_at']))) ?>
      </div>
    </div>
  </div>
</div>


<!-- What you have submitted -->
<div class="stat-grid grid-4 mt-5">
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


<div class="mt-5 grid-1-1">

  <!-- Editable details -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Edit your details</div>
        <div class="card-sub">Your role and department are set by the Admin</div>
      </div>
    </div>

    <form method="post" class="card-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_profile">

      <div class="field">
        <label>Name <span class="req">*</span></label>
        <input class="input" name="name" value="<?= e($me['name']) ?>" required>
      </div>

      <div class="field">
        <label>Phone</label>
        <input class="input" name="phone" value="<?= e($me['phone']) ?>" placeholder="Optional">
      </div>

      <div class="field">
        <label>Role</label>
        <input class="input" value="<?= e($me['role']) ?><?= $me['department'] ? ' · ' . e($me['department']) : '' ?>" disabled>
      </div>

      <button type="submit" class="btn btn-primary btn-sm"><?= icon('save') ?> Save Changes</button>
    </form>
  </div>

  <!-- Password -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Change password</div>
        <div class="card-sub">Enter the password you use now, then the new one twice</div>
      </div>
    </div>

    <form method="post" class="card-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">

      <div class="field">
        <label>Current password <span class="req">*</span></label>
        <input class="input" type="password" name="current_password" required>
      </div>

      <div class="field">
        <label>New password <span class="req">*</span></label>
        <input class="input" type="password" name="new_password" required minlength="6">
        <div class="hint">At least 6 characters.</div>
      </div>

      <div class="field">
        <label>Repeat new password <span class="req">*</span></label>
        <input class="input" type="password" name="confirm_password" required minlength="6">
      </div>

      <button type="submit" class="btn btn-primary btn-sm"><?= icon('key') ?> Update Password</button>
    </form>
  </div>

</div>


<!-- Recent submissions -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Your recent submissions</div>
      <div class="card-sub">
        <?php if (count($records) > count($recent)): ?>
          Newest <?= count($recent) ?> of <?= count($records) ?>
        <?php else: ?>
          <?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?>
        <?php endif; ?>
      </div>
    </div>
    <a class="btn btn-outline btn-sm" href="<?= e(url('upload.php')) ?>"><?= icon('upload', 15) ?> Upload Data</a>
  </div>

  <div class="card-body">
    <?php if (empty($recent)): ?>

      <div class="empty">
        <div class="ic"><?= icon('file-stack', 20) ?></div>
        <p>You have not submitted anything yet</p>
        <div class="note">Use Upload Data to add your first record.</div>
      </div>

    <?php else: ?>

      <?php foreach ($recent as $record): ?>
        <div class="list-row">
          <div class="min-w-0">
            <div class="t truncate"><?= e($record['_title']) ?></div>
            <div class="card-sub"><?= e($record['_type_label']) ?> · <?= e(time_ago($record['created_at'])) ?></div>
          </div>
          <span class="badge badge-<?= status_class($record['status']) ?>"><?= e($record['status']) ?></span>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
