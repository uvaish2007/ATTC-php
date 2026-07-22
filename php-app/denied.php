<?php
require_once __DIR__ . '/inc/auth.php';

$user = require_login();

http_response_code(403);

$pageTitle  = 'Access Denied';
$breadcrumb = 'Access Denied';
require __DIR__ . '/inc/header.php';
?>

<div class="card">
  <div class="empty" style="padding:80px 24px">
    <div class="ic" style="background:#FEF2F2; color:#DC2626; width:56px; height:56px"><?= icon('x', 24) ?></div>
    <p style="font-size:18px; font-weight:600; color:var(--ink)">Access Denied</p>
    <div class="note">Your role (<?= e($user['role']) ?>) does not have permission to view that page.</div>
    <a class="btn btn-outline btn-sm" style="margin-top:20px" href="<?= e(url('dashboard.php')) ?>">Back to Dashboard</a>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
