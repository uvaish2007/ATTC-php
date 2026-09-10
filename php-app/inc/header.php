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
require_once __DIR__ . '/notifications.php';

$user   = $user ?? current_user();
$active = basename($_SERVER['SCRIPT_NAME']);

$headerNotifications = fetch_header_notifications($user);
$unreadNotifCount    = count(array_filter($headerNotifications, fn($n) => !empty($n['unread'])));

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
  <style>
  .notif-wrapper { position: relative; display: inline-block; }
  .notif-bell-btn { position: relative; cursor: pointer; }
  .notif-badge-dot {
    position: absolute;
    top: -3px;
    right: -3px;
    background: #ff4f01;
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    min-width: 17px;
    height: 17px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  }
  .notif-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 320px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    box-shadow: 0 12px 30px -5px rgba(15, 23, 42, 0.18), 0 4px 12px -2px rgba(15, 23, 42, 0.08);
    z-index: 99999;
    overflow: hidden;
    font-family: inherit;
    text-align: left;
  }
  .notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
  }
  .notif-title {
    font-weight: 700;
    font-size: 13px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .notif-mark-read-btn {
    background: none;
    border: none;
    color: #2563eb;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
  }
  .notif-mark-read-btn:hover {
    text-decoration: underline;
    color: #1d4ed8;
  }
  .notif-body {
    max-height: 360px;
    overflow-y: auto;
  }
  .notif-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none;
    color: inherit;
    transition: background 0.15s ease;
    position: relative;
  }
  .notif-item:last-child {
    border-bottom: none;
  }
  .notif-item:hover {
    background: #f8fafc;
  }
  .notif-item.unread {
    background: #f0f7ff;
  }
  .notif-item.unread:hover {
    background: #e0f2fe;
  }
  .notif-item-icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: #e2e8f0;
    color: #475569;
    margin-top: 2px;
  }
  .notif-icon-approval { background: #fee2e2; color: #dc2626; }
  .notif-icon-announcement { background: #e0e7ff; color: #4338ca; }
  .notif-icon-deadline { background: #fef3c7; color: #d97706; }
  .notif-icon-target { background: #dcfce7; color: #16a34a; }

  .notif-item-content {
    flex: 1;
    min-width: 0;
  }
  .notif-item-title {
    font-weight: 600;
    font-size: 12px;
    color: #0f172a;
    line-height: 1.3;
  }
  .notif-item-desc {
    font-size: 11px;
    color: #475569;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .notif-item-time {
    font-size: 10px;
    color: #94a3b8;
    margin-top: 3px;
  }
  .notif-item-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #2563eb;
    flex-shrink: 0;
    margin-top: 4px;
  }
  .notif-empty {
    padding: 24px 14px;
    text-align: center;
    color: #64748b;
  }
  .notif-empty-icon {
    margin-bottom: 6px;
    opacity: 0.5;
  }
  .notif-empty-text {
    font-size: 13px;
    font-weight: 500;
  }
  </style>
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
        <span class="role-badge" title="You are signed in as <?= e($user['role']) ?>"><?= icon('shield', 13) ?> <?= e($user['role']) ?></span>
        
        <div class="notif-wrapper" style="position:relative; display:inline-block;">
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
        <div class="avatar-dark"><?= e(initials($user['name'])) ?></div>
      </div>
    </header>

    <main class="content">
      <div class="container">
        <?php foreach ($flashes as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
