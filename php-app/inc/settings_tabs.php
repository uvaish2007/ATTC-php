<?php
/**
 * The Settings tab strip, shared by settings.php and password-requests.php.
 *
 * Password Requests keeps its own page — it has its own POST handler and its
 * own feature module — but as far as the Admin is concerned it belongs to
 * Settings, so both pages render this one strip. Set $settingsTab to the key
 * that should show as active before requiring this file.
 *
 * Every page that uses this is already behind require_role(['Admin']); the
 * strip decides nothing about access, it only draws links.
 */

require_once __DIR__ . '/../models/PasswordResetRequest.php';

$settingsTab = $settingsTab ?? 'metrics';

$settingsTabs = [
    'metrics' => ['label' => 'Metrics',    'href' => 'settings.php?tab=metrics'],
    'account' => ['label' => 'My Account', 'href' => 'settings.php?tab=account'],
    'system'  => ['label' => 'System',     'href' => 'settings.php?tab=system'],
];

// Last tab, after System — and only while the module is switched on, the same
// rule the sidebar applies to every other page.
if (module_is_active(module_for_path('password-requests.php'))) {
    $settingsTabs['password'] = ['label' => 'Password Requests', 'href' => 'password-requests.php'];
}

$settingsPwPending = password_reset_requests_pending_count();
?>
<div class="tabs">
  <?php foreach ($settingsTabs as $settingsKey => $settingsItem): ?>
    <a class="tab<?= $settingsTab === $settingsKey ? ' active' : '' ?>"
       href="<?= e(url($settingsItem['href'])) ?>"
       style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
      <span><?= e($settingsItem['label']) ?></span>
      <?php if ($settingsKey === 'password' && $settingsPwPending > 0): ?>
        <span class="tab-count" style="background:#FEF3C7;color:#92400E;font-weight:700"><?= (int) $settingsPwPending ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
