<?php
/**
 * App shell: <head>, sidebar, topbar. A page sets $pageTitle (and optionally
 * $pageSubtitle / $breadcrumb) then require()s this, renders its content, and
 * ends with footer.php.
 *
 * Assumes require_login() has already run and $user is available.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/../models/Announcement.php';
require_once __DIR__ . '/../models/Target.php';

$user   = $user ?? current_user();
$active = basename($_SERVER['SCRIPT_NAME']);

$navItems = navigation_for($user['role']);
$groups   = group_navigation($navItems);

$badgeCounts = [
    'approvals'     => pending_approvals_count($user),
    'announcements' => unread_announcements_count($user),
    // Targets waiting on a decision. Only the two roles that can decide are
    // told about them; for anyone else the count is noise. For an Admin the
    // badge also counts unlock requests, since those are actioned on the same
    // Targets page.
    'targets'       => (in_array($user['role'], ['Admin', 'Director'], true) ? targets_pending_count() : 0)
                       + ($user['role'] === 'Admin' ? unlock_pending_count() : 0),
];

$pageTitle    = $pageTitle    ?? 'Dashboard';
$breadcrumb   = $breadcrumb   ?? $pageTitle;
$flashes      = take_flashes();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> · ATTS IQAC</title>
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-logo">A</div>
      <div>
        <div class="brand-title">ATTS</div>
        <div class="brand-sub">IQAC Portal</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <?php foreach ($groups as $section => $items): ?>
        <div>
          <div class="nav-section-label"><?= e($section) ?></div>
          <div class="nav-list">
            <?php foreach ($items as $item):
              $isActive = ($item['path'] === $active);
              $badge = isset($item['badge']) ? ($badgeCounts[$item['badge']] ?? 0) : 0;
            ?>
              <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e(nav_href($item['path'])) ?>" title="<?= e($item['label']) ?>">
                <?= icon($item['icon']) ?>
                <span class="nav-label"><?= e($item['label']) ?></span>
                <?php if ($badge > 0): ?><span class="nav-badge"><?= (int) $badge ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <div class="side-user">
        <div class="avatar"><?= e(initials($user['name'])) ?></div>
        <div class="meta">
          <div class="nm"><?= e($user['name']) ?></div>
          <div class="rl"><?= e($user['role']) ?></div>
        </div>
      </div>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="side-signout"><?= icon('logout') ?> Sign out</button>
      </form>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <nav class="breadcrumb" aria-label="Breadcrumb">
        <a class="root" href="<?= e(url('dashboard.php')) ?>">ATTS</a>
        <span class="sep"><?= icon('chevron', 14) ?></span>
        <span class="cur"><?= e($breadcrumb) ?></span>
      </nav>
      <div class="topbar-right">
        <button class="icon-btn" aria-label="Notifications"><?= icon('bell', 19) ?></button>
        <div class="topbar-div"></div>
        <div class="topbar-user">
          <div class="nm"><?= e($user['name']) ?></div>
          <div class="rl"><?= e($user['role']) ?><?= $user['department'] ? ' · ' . e($user['department']) : '' ?></div>
        </div>
        <div class="avatar-dark"><?= e(initials($user['name'])) ?></div>
      </div>
    </header>

    <main class="content">
      <div class="container">
        <?php foreach ($flashes as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
