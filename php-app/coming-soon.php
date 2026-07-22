<?php
require_once __DIR__ . '/inc/auth.php';

$user = require_login();

$page = basename((string) input('page'));
$name = ucwords(str_replace(['.php', '-', '_'], ['', ' ', ' '], $page));

$pageTitle  = $name ?: 'Coming soon';
$breadcrumb = $pageTitle;
require __DIR__ . '/inc/header.php';
?>

<div class="card">
  <div class="empty" style="padding:80px 24px">
    <div class="ic" style="background:var(--orange-50); color:var(--orange-500); width:56px; height:56px"><?= icon('layers', 24) ?></div>
    <p style="font-size:18px; font-weight:600; color:var(--ink)"><?= e($pageTitle) ?></p>
    <div class="note">This page hasn't been built in the PHP version yet — it's part of the phased conversion.</div>
    <a class="btn btn-outline btn-sm" style="margin-top:20px" href="<?= e(url('dashboard.php')) ?>">Back to Dashboard</a>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
