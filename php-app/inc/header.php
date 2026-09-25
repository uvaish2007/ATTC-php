<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/../models/Announcement.php';
require_once __DIR__ . '/../models/Target.php';
require_once __DIR__ . '/../models/ExecutiveMeeting.php';   require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../models/User.php';        
$user   = $user ?? current_user();
$active = basename($_SERVER['SCRIPT_NAME']);

$atts_activeYear = active_academic_year();

$atts_emStatus = em_status($atts_activeYear);

$headerNotifications = fetch_header_notifications($user);
$unreadNotifCount    = count(array_filter($headerNotifications, fn($n) => !empty($n['unread'])));

$myPhotoFile = !empty($user['id']) ? user_photo_filename((int) $user['id']) : null;
$myPhotoUrl  = $myPhotoFile !== null
    ? url('photo.php?user=' . (int) $user['id'] . '&v=' . substr(md5($myPhotoFile), 0, 8))
    : null;

$navItems = navigation_for($user['role']);
$groups   = group_navigation($navItems);

<<<<<<< HEAD
=======
require_once __DIR__ . '/../models/EditRequest.php';
require_once __DIR__ . '/../models/PasswordResetRequest.php';   
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
$badgeCounts = [
    'approvals'     => pending_approvals_count($user),
    'announcements' => unread_announcements_count($user),
    'targets'       => (in_array($user['role'], ['Admin', 'Director', 'Principal', 'Dean'], true) ? targets_pending_count($atts_activeYear) : 0)
                       + ($user['role'] === 'Admin' ? unlock_pending_count() : 0),
<<<<<<< HEAD
=======
    'edit_requests' => edit_requests_pending_count($user),
    // FEAT-11 — only the Admin decides password requests, so only the Admin is
    // told how many are waiting. The count is not even queried for anyone else.
    'password_requests' => $user['role'] === 'Admin' ? password_reset_requests_pending_count() : 0,
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
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
  <meta name="theme-color" content="#131D3B">
  <?php require __DIR__ . '/favicon.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <!-- Inter when the machine is online; Segoe UI is the fallback, so the portal
       looks the same offline on a lab PC. -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
  <script>
  function toggleNotificationPanel(evt) {
    if (evt) evt.stopPropagation();
    const panel = document.getElementById('notif_panel');
    if (!panel) return;
    const isHidden = panel.style.display === 'none' || panel.style.display === '';
    panel.style.display = isHidden ? 'block' : 'none';
  }

  document.addEventListener('click', function(evt) {
    const panel = document.getElementById('notif_panel');
    const btn = document.getElementById('notif_bell_btn');
    if (panel && !panel.contains(evt.target) && !btn.contains(evt.target)) {
      panel.style.display = 'none';
    }
  });

  function markAllNotificationsRead(evt) {
    if (evt) evt.preventDefault();
    const form = document.getElementById('mark_all_read_form');
    const csrfToken = form ? (form.querySelector('input[name="csrf"]')?.value || '') : '';
    
    fetch('<?= e(url("inc/mark_notifications_read.php")) ?>', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: 'csrf=' + encodeURIComponent(csrfToken) + '&action=mark_all_notifications_read&ajax=1'
    })
    .then(res => res.json())
    .then(data => {
      const badge = document.getElementById('notif_badge_dot');
      if (badge) badge.style.display = 'none';
      const countBadge = document.getElementById('notif_count_badge');
      if (countBadge) countBadge.style.display = 'none';
      
      document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
      document.querySelectorAll('.notif-item-dot').forEach(el => el.remove());
      if (form) form.style.display = 'none';
    })
    .catch(() => {
      if (form) form.submit();
    });
  }
  </script>
</head>
<body>
<div class="app">

  <div class="scrim" id="navScrim" aria-hidden="true"></div>

  <aside class="sidebar" id="appSidebar">
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
<<<<<<< HEAD
              $isActive = ($item['path'] === $active);
=======
              if ($active === 'approvals.php' || $active === 'edit-requests.php') {
                $isActive = (strtok($item['path'], '?') === 'approvals.php');
              } elseif ($active === 'password-requests.php') {
                $isActive = (strtok($item['path'], '?') === 'settings.php');
              } else {
                $isActive = (strtok($item['path'], '?') === $active);
              }
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
              $badge = isset($item['badge']) ? ($badgeCounts[$item['badge']] ?? 0) : 0;
              $locked = !module_is_active(module_for_path($item['path']));
            ?>
              <?php if ($locked): ?>
                <a class="nav-link locked" href="#" title="<?= e($item['label']) ?> — Coming Soon"
                   onclick="showLockedModule(<?= htmlspecialchars(json_encode($item['label']), ENT_QUOTES) ?>); return false;">
                  <?= icon($item['icon']) ?>
                  <span class="nav-label"><?= e($item['label']) ?></span>
                  <span class="nav-lock"><?= icon('lock', 14) ?></span>
                </a>
              <?php else: ?>
                <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e(nav_href($item['path'])) ?>" title="<?= e($item['label']) ?>">
                  <?= icon($item['icon']) ?>
                  <span class="nav-label"><?= e($item['label']) ?></span>
                  <?php if ($badge > 0): ?><span class="nav-badge"><?= (int) $badge ?></span><?php endif; ?>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <div class="side-user">
        <?php if ($myPhotoUrl !== null): ?>
          <img class="avatar avatar-photo" src="<?= e($myPhotoUrl) ?>" alt="Your profile photo">
        <?php else: ?>
          <div class="avatar"><?= e(initials($user['name'])) ?></div>
        <?php endif; ?>
        <div class="meta">
          <div class="nm" title="<?= e($user['name']) ?>"><?= e($user['name']) ?></div>
          <div class="rl"><?= e($user['role']) ?><?= !empty($user['department']) ? ' · ' . e($user['department']) : '' ?></div>
        </div>
        <form method="post" action="<?= e(url('logout.php')) ?>" class="side-logout-form">
          <?= csrf_field() ?>
          <button type="submit" class="side-logout" title="Logout" aria-label="Logout"><?= icon('logout', 17) ?></button>
        </form>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="icon-btn nav-toggle" id="navToggle"
                aria-label="Open navigation" aria-controls="appSidebar" aria-expanded="false">
          <?= icon('menu', 20) ?>
        </button>
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <button type="button" class="topbar-back-btn" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href='<?= e(url('dashboard.php')) ?>'; }" title="Go back to past page" aria-label="Back">
            <span class="topbar-back-arrow"><?= icon('arrow-left', 16) ?></span>
            <span class="topbar-back-text">Back</span>
          </button>
          <?php if ($breadcrumb === 'Dashboard'): ?>
            <span class="topbar-dash-label">ATTS DASHBOARD</span>
          <?php else: ?>
            <a class="root" href="<?= e(url('dashboard.php')) ?>">ATTS DASHBOARD</a>
            <span class="sep"><?= icon('chevron', 14) ?></span>
            <span class="cur" title="<?= e($breadcrumb) ?>"><?= e($breadcrumb) ?></span>
          <?php endif; ?>
        </nav>
      </div>
      <div class="topbar-right">
        <span class="year-badge" title="ATTS is operating on academic year <?= e($atts_activeYear) ?>. Set by the Admin; every role sees this same year until it is changed.">
          <?= icon('calendar', 13) ?> <span class="yb-label">Academic Year:</span> <?= e($atts_activeYear) ?>
          <?php if (academic_year_is_locked($atts_activeYear)): ?>
            <?php $latestExec = executive_meeting_latest($atts_activeYear); ?>
            <span class="yb-lock" style="color:#ffffff;"><?= icon('lock', 11) ?> Locked<?= $latestExec ? ' (M#' . e($latestExec['meeting_number']) . ')' : '' ?></span>
          <?php else: ?>
            <span class="yb-lock" style="color:rgba(255,255,255,0.85);border-left:1px solid rgba(255,255,255,0.3);"><?= icon('unlock', 11) ?> Cycle Open</span>
          <?php endif; ?>
        </span>
        <?php if ($atts_emStatus['configured']): ?>
          <?php $emTone = $atts_emStatus['current'] ? 'active' : ($atts_emStatus['em1_locked'] ? 'locked' : 'upcoming'); ?>
          <span class="em-badge em-<?= e($emTone) ?>" title="<?= e($atts_emStatus['detail']) ?>">
            <span class="em-dot"></span><?= e($atts_emStatus['label']) ?>
          </span>
        <?php endif; ?>
        <span class="role-badge" title="You are signed in as <?= e($user['role']) ?>"><?= icon('shield', 13) ?> <?= e($user['role']) ?></span>

        <div class="notif-wrapper">
          <button type="button" class="icon-btn notif-bell-btn" id="notif_bell_btn" aria-label="Notifications" onclick="toggleNotificationPanel(event)">
            <?= icon('bell', 19) ?>
            <?php if ($unreadNotifCount > 0): ?>
              <span class="notif-badge-dot" id="notif_badge_dot"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
          </button>

          <div class="notif-panel" id="notif_panel" style="display:none;">
            <div class="notif-header">
              <div class="notif-title">
                <span>Notifications</span>
                <?php if ($unreadNotifCount > 0): ?>
                  <span class="badge badge-primary" id="notif_count_badge" style="font-size:11px; padding:2px 6px; border-radius:10px;"><?= $unreadNotifCount ?> new</span>
                <?php endif; ?>
              </div>
              <?php if ($unreadNotifCount > 0): ?>
                <form method="post" action="<?= e(url('inc/mark_notifications_read.php')) ?>" id="mark_all_read_form" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_all_notifications_read">
                  <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/dashboard.php') ?>">
                  <button type="submit" class="notif-mark-read-btn" onclick="markAllNotificationsRead(event)">Mark all as read</button>
                </form>
              <?php endif; ?>
            </div>

            <div class="notif-body" id="notif_body">
              <?php if (empty($headerNotifications)): ?>
                <div class="notif-empty">
                  <div class="notif-empty-icon"><?= icon('bell', 24) ?></div>
                  <div class="notif-empty-text">No new notifications</div>
                </div>
              <?php else: ?>
                <?php foreach ($headerNotifications as $n): ?>
                  <a href="<?= e($n['link']) ?>" class="notif-item <?= !empty($n['unread']) ? 'unread' : '' ?>">
                    <div class="notif-item-icon notif-icon-<?= e($n['type']) ?>">
                      <?= icon($n['icon'] ?? 'bell', 15) ?>
                    </div>
                    <div class="notif-item-content">
                      <div class="notif-item-title"><?= e($n['title']) ?></div>
                      <div class="notif-item-desc"><?= e($n['description']) ?></div>
                      <div class="notif-item-time"><?= e($n['time']) ?></div>
                    </div>
                    <?php if (!empty($n['unread'])): ?>
                      <span class="notif-item-dot"></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="topbar-div"></div>
        <div class="topbar-user">
          <div class="nm"><?= e($user['name']) ?></div>
          <div class="rl"><?= e($user['role']) ?><?= $user['department'] ? ' · ' . e($user['department']) : '' ?></div>
        </div>
        <?php if ($myPhotoUrl !== null): ?>
          <img class="avatar-dark avatar-photo" src="<?= e($myPhotoUrl) ?>" alt="Your profile photo">
        <?php else: ?>
          <div class="avatar-dark"><?= e(initials($user['name'])) ?></div>
        <?php endif; ?>
      </div>
    </header>

    <main class="content">
      <div class="container">
        <?php foreach ($flashes as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
