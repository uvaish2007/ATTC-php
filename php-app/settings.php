<?php
/**
 * Settings — Admin only.
 *
 * The page has three tabs. Which one you see comes from the address bar,
 * e.g. settings.php?tab=account :
 *
 *   metrics  → the list of things the IQAC tracks (used by Targets)
 *   account  → your own name, phone and password
 *   system   → read-only facts about this installation
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Metric.php';
require_once __DIR__ . '/models/User.php';

$user = require_role(['Admin']);
require_module('settings');

// ---- Which tab is open? --------------------------------------------------
$tabs = [
    'metrics' => 'Metrics',
    'account' => 'My Account',
    'system'  => 'System',
];

$tab = (string) input('tab', 'metrics');

if (!isset($tabs[$tab])) {
    $tab = 'metrics';
}

// ---- Handle the forms ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'metric_create') {
        [$ok, $msg] = metric_create(
            (string) input('name'),
            (string) input('category'),
            (int) input('proof_required', 0)
        );
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'metric_update') {
        [$ok, $msg] = metric_update(
            (int) input('id'),
            (string) input('name'),
            (string) input('category'),
            (int) input('proof_required', 0),
            (int) input('status', 1)
        );
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'metric_delete') {
        [$ok, $msg] = metric_delete((int) input('id'));
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'save_profile') {
        [$ok, $msg] = user_update_profile($user['id'], (string) input('name'), (string) input('phone'));
        flash($ok ? 'success' : 'error', $msg);

        // Keep the name in the sidebar in step with the change.
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

    redirect('/settings.php?tab=' . $tab);
}

// ---- Data for whichever tab is showing -----------------------------------
$metrics    = metrics_all();
$categories = metric_categories();
$me         = user_find($user['id']);

$activeMetrics = 0;
foreach ($metrics as $metric) {
    if ((int) $metric['status'] === 1) {
        $activeMetrics++;
    }
}

$pageTitle  = 'Settings';
$breadcrumb = 'Settings';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Settings</h1>
    <div class="sub">Metrics, your account, and how this portal is set up</div>
  </div>
</div>

<!-- Tab bar: each tab is just a link back to this page -->
<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="tab<?= $tab === $key ? ' active' : '' ?>" href="<?= e(url('settings.php?tab=' . $key)) ?>">
      <?= e($label) ?>
    </a>
  <?php endforeach; ?>
</div>


<?php if ($tab === 'metrics'): ?>

  <!-- ===================== Metrics ===================== -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Tracked metrics</div>
        <div class="card-sub">
          <?= count($metrics) ?> metric<?= count($metrics) === 1 ? '' : 's' ?> ·
          <?= (int) $activeMetrics ?> active · these appear when setting a target
        </div>
      </div>
      <button class="btn btn-primary btn-sm" onclick="document.getElementById('addMetric').showModal()">
        <?= icon('plus') ?> Add Metric
      </button>
    </div>

    <div class="card-body pt-0">
      <?php if (empty($metrics)): ?>

        <div class="empty">
          <div class="ic"><?= icon('layers', 20) ?></div>
          <p>No metrics yet</p>
          <div class="note">Add one, for example "Journal Publications" under "Research".</div>
        </div>

      <?php else: ?>

        <div class="table-wrap">
          <table class="data wide">
            <thead>
              <tr>
                <th>Metric</th>
                <th>Category</th>
                <th>Proof</th>
                <th>Status</th>
                <th class="num">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($metrics as $metric): ?>
                <tr>
                  <td class="fw-500"><?= e($metric['name']) ?></td>
                  <td class="faint"><?= e($metric['category']) ?></td>
                  <td>
                    <?php if ((int) $metric['proof_required'] === 1): ?>
                      <span class="badge badge-brand">Required</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Optional</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int) $metric['status'] === 1): ?>
                      <span class="badge badge-success">Active</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Hidden</span>
                    <?php endif; ?>
                  </td>
                  <td class="num">
                    <div class="dept-actions" style="justify-content:flex-end">
                      <button class="mini-btn" title="Edit"
                        onclick='editMetric(<?= e(json_encode($metric)) ?>)'><?= icon('pencil', 15) ?></button>
                      <button class="mini-btn danger" title="Delete"
                        onclick='deleteMetric(<?= (int) $metric["id"] ?>, <?= htmlspecialchars(json_encode($metric["name"]), ENT_QUOTES) ?>)'><?= icon('trash', 15) ?></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php endif; ?>
    </div>
  </div>

  <div class="card mt-5">
    <div class="card-body">
      <div class="note-row">
        <div class="note-ic"><?= icon('info', 16) ?></div>
        <div>
          <div class="fw-500">About "Proof"</div>
          <div class="card-sub">
            Marking a metric as <strong>Required</strong> tells reviewers that submissions
            should carry a supporting document. The upload form shows it as a reminder —
            it does not block a submission that has no file attached.
          </div>
        </div>
      </div>
    </div>
  </div>


<?php elseif ($tab === 'account'): ?>

  <!-- ===================== My Account ===================== -->
  <div class="grid-1-1">

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Your details</div>
          <div class="card-sub">Shown on the sidebar and next to anything you approve</div>
        </div>
      </div>

      <form method="post" class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="account">
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
          <label>Email</label>
          <input class="input" value="<?= e($me['email']) ?>" disabled>
          <div class="hint">The email is the login name, so it is changed on the Users page.</div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm"><?= icon('save') ?> Save Changes</button>
      </form>
    </div>

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Change password</div>
          <div class="card-sub">You need your current password to set a new one</div>
        </div>
      </div>

      <form method="post" class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="account">
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


<?php else: ?>

  <!-- ===================== System ===================== -->
  <?php
    // Read a few facts about the running app. Nothing here can be edited from
    // the browser — the real settings live in php-app/.env
    $pdo = db();

    $tableCounts = [
        'Users'       => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'Departments' => (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn(),
        'Metrics'     => count($metrics),
        'Targets'     => (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn(),
    ];

    $application = [
        'App'           => 'ATTS · IQAC Portal',
        'Base URL'      => BASE_URL === '' ? '/ (server root)' : BASE_URL,
        'Time zone'     => date_default_timezone_get(),
        'Server time'   => date('d M Y, H:i'),
        'Debug mode'    => APP_DEBUG ? 'On (show errors)' : 'Off',
        'PHP version'   => PHP_VERSION,
    ];

    $database = [
        'Host'      => DB_HOST . ':' . DB_PORT,
        'Database'  => DB_NAME,
        'User'      => DB_USER,
        'Server'    => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'Driver'    => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    ];

    $uploadsWritable = is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR);
  ?>

  <div class="grid-1-1">

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Application</div>
          <div class="card-sub">Set in php-app/.env</div>
        </div>
        <div class="stat-ic navy"><?= icon('settings') ?></div>
      </div>
      <div class="card-body pt-0">
        <?php foreach ($application as $label => $value): ?>
          <div class="kv-row">
            <span class="k"><?= e($label) ?></span>
            <span class="v"><?= e($value) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Database</div>
          <div class="card-sub">Connected and answering</div>
        </div>
        <div class="stat-ic brand"><?= icon('layers') ?></div>
      </div>
      <div class="card-body pt-0">
        <?php foreach ($database as $label => $value): ?>
          <div class="kv-row">
            <span class="k"><?= e($label) ?></span>
            <span class="v"><?= e($value) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <div class="card mt-5">
    <div class="card-head">
      <div>
        <div class="card-title">Stored data</div>
        <div class="card-sub">Rows currently in the main tables</div>
      </div>
    </div>
    <div class="card-body pt-0">
      <div class="stat-grid grid-4">
        <?php foreach ($tableCounts as $label => $count): ?>
          <div class="stat">
            <div class="stat-top">
              <div class="stat-label"><?= e($label) ?></div>
            </div>
            <div class="stat-value tabular"><?= (int) $count ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card mt-5">
    <div class="card-body">
      <div class="note-row">
        <div class="note-ic"><?= icon($uploadsWritable ? 'check' : 'alert-triangle', 16) ?></div>
        <div>
          <div class="fw-500">Uploads folder</div>
          <div class="card-sub">
            <code><?= e(UPLOAD_DIR) ?></code> —
            <?= $uploadsWritable
                  ? 'writable, supporting documents can be saved.'
                  : 'NOT writable. Give the web server permission to write here, or file uploads will fail.' ?>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>


<!-- Add metric -->
<dialog class="modal" id="addMetric">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="metrics">
    <input type="hidden" name="action" value="metric_create">

    <div class="modal-head">
      <div>
        <h3>Add Metric</h3>
        <div class="msub">It becomes available when someone sets a target.</div>
      </div>
    </div>

    <div class="modal-body">
      <div class="field">
        <label>Metric name <span class="req">*</span></label>
        <input class="input" name="name" placeholder="e.g. Journal Publications" required>
      </div>

      <div class="field">
        <label>Category <span class="req">*</span></label>
        <input class="input" name="category" list="metric-categories" placeholder="e.g. Research" required>
        <datalist id="metric-categories">
          <?php foreach ($categories as $category): ?>
            <option value="<?= e($category) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <div class="hint">Metrics are grouped by category in the list.</div>
      </div>

      <div class="field">
        <label>Proof</label>
        <select class="select" name="proof_required">
          <option value="0">Optional</option>
          <option value="1">Required — remind the submitter to attach a document</option>
        </select>
      </div>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Add Metric</button>
    </div>
  </form>
</dialog>

<!-- Edit metric -->
<dialog class="modal" id="editMetric">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="metrics">
    <input type="hidden" name="action" value="metric_update">
    <input type="hidden" name="id" id="m-id">

    <div class="modal-head"><div><h3>Edit Metric</h3></div></div>

    <div class="modal-body">
      <div class="field">
        <label>Metric name <span class="req">*</span></label>
        <input class="input" name="name" id="m-name" required>
      </div>

      <div class="field">
        <label>Category <span class="req">*</span></label>
        <input class="input" name="category" id="m-category" list="metric-categories" required>
      </div>

      <div class="field">
        <label>Proof</label>
        <select class="select" name="proof_required" id="m-proof">
          <option value="0">Optional</option>
          <option value="1">Required</option>
        </select>
      </div>

      <div class="field">
        <label>Status</label>
        <select class="select" name="status" id="m-status">
          <option value="1">Active — can be chosen for a target</option>
          <option value="0">Hidden — keep the history, stop offering it</option>
        </select>
      </div>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
    </div>
  </form>
</dialog>

<!-- Delete metric -->
<dialog class="modal" id="delMetric" style="max-width:28rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="tab" value="metrics">
    <input type="hidden" name="action" value="metric_delete">
    <input type="hidden" name="id" id="d-id">

    <div class="modal-head"><div><h3>Delete metric?</h3></div></div>

    <div class="modal-body">
      <p class="modal-text">
        <strong id="d-name"></strong> will no longer be offered when setting a target.
        Targets already using it keep their own copy of the name.
      </p>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </div>
  </form>
</dialog>

<script>
  function editMetric(metric) {
    document.getElementById('m-id').value       = metric.id;
    document.getElementById('m-name').value     = metric.name;
    document.getElementById('m-category').value = metric.category;
    document.getElementById('m-proof').value    = metric.proof_required;
    document.getElementById('m-status').value   = metric.status;
    document.getElementById('editMetric').showModal();
  }

  function deleteMetric(id, name) {
    document.getElementById('d-id').value = id;
    document.getElementById('d-name').textContent = name;
    document.getElementById('delMetric').showModal();
  }
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
