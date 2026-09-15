<?php
/**
 * Announcement Centre.
 *
 * Everyone reads notices here. The Director and Admin also write, pin,
 * schedule, archive and delete them, and can see who has read what.
 *
 * The whole page is one form-driven screen: filters go in the address bar,
 * actions are POSTed back to this same file.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Announcement.php';
require_once __DIR__ . '/models/Department.php';

$user      = require_login();
require_module('announcements');
$canManage = announcement_can_manage($user);

// -------------------------------------------------------------------------
// Actions
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) input('action');
    $id     = (int) input('id');

    // Where to go afterwards: back to the same filtered view. The query string
    // is only trusted if it holds nothing but ordinary query characters, so it
    // can never smuggle anything into the Location header.
    $back      = '/announcements.php';
    $backInput = (string) input('back', '');

    if ($backInput !== '' && preg_match('/^[\w=&%.+\-]+$/', $backInput)) {
        $back .= '?' . $backInput;
    }

    // --- anybody signed in ---
    if ($action === 'mark_read') {
        announcement_mark_read($id, (int) $user['id']);
        flash('success', 'Marked as read.');
        redirect($back);
    }

    if ($action === 'bookmark') {
        announcement_toggle_bookmark($id, (int) $user['id']);
        redirect($back);
    }

    // --- Director / Admin only ---
    if (!$canManage) {
        http_response_code(403);
        redirect('/denied.php');
    }

    if ($action === 'create') {
        [$ok, $msg, $newId] = announcement_create($_POST, (int) $user['id']);

        if ($ok && !empty($_FILES['attachments']['name'][0])) {
            foreach (announcement_save_files($newId, $_FILES['attachments']) as $problem) {
                flash('warning', $problem);
            }
        }

        if ($ok && ($_POST['status'] ?? '') === 'Draft') {
            $msg = 'Draft saved. Only you can see it until you publish it.';
        }

        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'update') {
        [$ok, $msg] = announcement_update($id, $_POST);

        if ($ok && !empty($_FILES['attachments']['name'][0])) {
            foreach (announcement_save_files($id, $_FILES['attachments']) as $problem) {
                flash('warning', $problem);
            }
        }

        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'pin') {
        announcement_pin($id);
        flash('success', 'Announcement pinned to the top.');

    } elseif ($action === 'unpin') {
        announcement_unpin($id);
        flash('success', 'Announcement unpinned.');

    } elseif ($action === 'archive') {
        [$ok, $msg] = announcement_set_status($id, 'Archived');
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'restore') {
        [$ok, $msg] = announcement_set_status($id, 'Published');
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'delete') {
        [$ok, $msg] = announcement_delete($id);
        flash($ok ? 'success' : 'error', $msg);
    }

    redirect($back);
}

// -------------------------------------------------------------------------
// Filters (all live in the address bar, so a filtered view can be shared)
// -------------------------------------------------------------------------
// -------------------------------------------------------------------------
// Calendar Anchors & Future Guard
// -------------------------------------------------------------------------
$curYear  = (int) date('Y');
$curMonth = (int) date('n');
$curDay   = (int) date('j');

$calYear  = (int) input('cal_year', input('year', $curYear));
$calMonth = (int) input('cal_month', input('month', $curMonth));
$calDay   = (int) input('cal_day', input('day', 0));

// STRICT FUTURE GUARD: Never allow future months or future years!
if ($calYear > $curYear || ($calYear === $curYear && $calMonth > $curMonth)) {
    $calYear  = $curYear;
    $calMonth = $curMonth;
    $calDay   = 0;
}

if ($calMonth < 1)  $calMonth = 1;
if ($calMonth > 12) $calMonth = 12;
if ($calYear < 2000) $calYear = 2000;

$daysInMonth = (int) date('t', mktime(0, 0, 0, $calMonth, 1, $calYear));
if ($calDay > $daysInMonth) {
    $calDay = 0;
}
if ($calYear === $curYear && $calMonth === $curMonth && $calDay > $curDay) {
    $calDay = 0; // cannot select future day in present month
}

$isCurrentMonth = ($calYear === $curYear && $calMonth === $curMonth);

// Previous Month (Down arrow)
$prevMonth = $calMonth - 1;
$prevYear  = $calYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear  = $calYear - 1;
}

// Next Month (Upper arrow)
$nextMonth = $calMonth + 1;
$nextYear  = $calYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear  = $calYear + 1;
}
$hasNextMonth = !($nextYear > $curYear || ($nextYear === $curYear && $nextMonth > $curMonth));

// Date navigation:
$maxDayAllowed = $isCurrentMonth ? $curDay : $daysInMonth;
$prevDay = $calDay > 1 ? $calDay - 1 : 0;
$hasPrevDay = ($calDay > 1);
$nextDay = ($calDay > 0 && $calDay < $maxDayAllowed) ? $calDay + 1 : ($calDay === 0 ? 1 : $calDay);
$hasNextDay = ($calDay > 0 && $calDay < $maxDayAllowed);

// -------------------------------------------------------------------------
// Filters
// -------------------------------------------------------------------------
$filters = [
    'search'   => trim((string) input('q', '')),
    'category' => trim((string) input('category', '')),
    'sort'     => trim((string) input('sort', 'newest')),
    'scope'    => trim((string) input('scope', 'all')),
    'page'     => (int) input('page', 1),
    'year'     => $calYear,
    'month'    => $calMonth,
    'day'      => $calDay,
];

// Helper to build URL preserving filters
if (!function_exists('ann_cal_url')) {
    function ann_cal_url(array $overrides = [], ?array $activeFilters = null): string {
        global $filters, $calYear, $calMonth, $calDay, $curYear, $curMonth;
        $f = $activeFilters ?? $filters ?? [];
        $y = array_key_exists('cal_year', $overrides) ? $overrides['cal_year'] : ($calYear ?? (int)date('Y'));
        $m = array_key_exists('cal_month', $overrides) ? $overrides['cal_month'] : ($calMonth ?? (int)date('n'));
        $d = array_key_exists('cal_day', $overrides) ? $overrides['cal_day'] : ($calDay ?? 0);
        $cY = $curYear ?? (int)date('Y');
        $cM = $curMonth ?? (int)date('n');

        $params = [
            'q'         => $f['search'] ?? '',
            'category'  => $f['category'] ?? '',
            'sort'      => ($f['sort'] ?? '') !== 'newest' ? ($f['sort'] ?? null) : null,
            'scope'     => ($f['scope'] ?? '') !== 'all' ? ($f['scope'] ?? null) : null,
            'cal_year'  => ($y && (int)$y !== $cY) ? (int)$y : null,
            'cal_month' => ($m && (int)$m !== $cM) ? (int)$m : null,
            'cal_day'   => $d ?: null,
        ];
        if ($y && $m && ($y !== $cY || $m !== $cM)) {
            $params['cal_year']  = $y;
            $params['cal_month'] = $m;
        }
        foreach ($overrides as $k => $v) {
            if (!in_array($k, ['cal_year', 'cal_month', 'cal_day'], true)) {
                $params[$k] = $v;
            }
        }
        $query = http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));
        return url('announcements.php' . ($query ? '?' . $query : ''));
    }
}

$backQuery = http_build_query(array_filter([
    'q'         => $filters['search'],
    'category'  => $filters['category'],
    'sort'      => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
    'scope'     => $filters['scope'] !== 'all' ? $filters['scope'] : null,
    'cal_year'  => $calYear !== $curYear ? $calYear : null,
    'cal_month' => $calMonth !== $curMonth ? $calMonth : null,
    'cal_day'   => $calDay ?: null,
    'page'      => $filters['page'] > 1 ? $filters['page'] : null,
]));

// -------------------------------------------------------------------------
// Opening one notice: announcements.php?view=12
// -------------------------------------------------------------------------
$openId = (int) (input('view') ?: input('id', 0));

if ($openId > 0 && announcement_find($openId, $user)) {
    announcement_count_view($openId);
}

// -------------------------------------------------------------------------
// Everything the page draws
// -------------------------------------------------------------------------
$ready       = announcements_ready();
$list        = announcements_list($user, $filters);
$stats       = announcement_stats($user);
$deadlines   = announcement_deadlines($user);
$recentFiles = announcement_recent_files();
$departments = $ready ? departments_all() : [];
$categories  = announcement_categories();

// The pinned notice is shown on its own above the list, so it is lifted out
// of the rows here (only on the first page, where it would otherwise appear).
$pinned = null;
foreach ($list['rows'] as $index => $row) {
    if ((int) $row['pinned'] === 1) {
        $pinned = $row;
        unset($list['rows'][$index]);
        break;
    }
}

// Calendar grid setup for the sidebar
$calDays  = announcement_calendar($user, $calYear, $calMonth);
$firstDay = (int) date('w', mktime(0, 0, 0, $calMonth, 1, $calYear));  // 0 = Sunday
$today    = ($isCurrentMonth) ? $curDay : 0;

// Colour of the priority badge.
$priorityClass = [
    'Urgent'    => 'urgent',
    'Important' => 'important',
    'Normal'    => 'normal',
];

$pageTitle  = 'Announcements';
$breadcrumb = 'Announcements';
require __DIR__ . '/inc/header.php';
?>

<style>
/* Priority Announcements: Urgent = Dark Red Box, Important = Dark Green Box, Normal = Default Color */
.ann-card.priority-urgent,
.ann-featured.priority-urgent {
  border: 2px solid #991b1b !important;
  border-left: 6px solid #7f1d1d !important;
  background: linear-gradient(180deg, #fff1f2 0%, #ffffff 130px) !important;
  box-shadow: 0 4px 14px rgba(153, 27, 27, 0.12) !important;
}
.ann-card.priority-urgent:hover,
.ann-featured.priority-urgent:hover {
  border-color: #7f1d1d !important;
  box-shadow: 0 8px 24px rgba(153, 27, 27, 0.22) !important;
  transform: translateY(-2px);
}
.ann-card.priority-urgent .ann-title-link:hover .ann-title,
.ann-featured.priority-urgent .ann-title-link:hover .ann-title {
  color: #991b1b !important;
}

.ann-card.priority-important,
.ann-featured.priority-important {
  border: 2px solid #166534 !important;
  border-left: 6px solid #14532d !important;
  background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 130px) !important;
  box-shadow: 0 4px 14px rgba(22, 101, 52, 0.12) !important;
}
.ann-card.priority-important:hover,
.ann-featured.priority-important:hover {
  border-color: #14532d !important;
  box-shadow: 0 8px 24px rgba(22, 101, 52, 0.22) !important;
  transform: translateY(-2px);
}
.ann-card.priority-important .ann-title-link:hover .ann-title,
.ann-featured.priority-important .ann-title-link:hover .ann-title {
  color: #166534 !important;
}

.ann-card.priority-normal,
.ann-featured.priority-normal {
  border: 1px solid var(--hairline) !important;
  background: var(--surface) !important;
  box-shadow: var(--shadow-card) !important;
}
.ann-card.priority-normal.unread {
  border-left: 3px solid var(--orange-500) !important;
}

.badge.badge-urgent,
.badge.badge-priority-urgent {
  background: #991b1b !important;
  color: #ffffff !important;
  border: 1px solid #7f1d1d !important;
  font-weight: 700 !important;
  letter-spacing: 0.3px;
  box-shadow: 0 1px 3px rgba(153, 27, 27, 0.25);
}

.badge.badge-important,
.badge.badge-priority-important {
  background: #166534 !important;
  color: #ffffff !important;
  border: 1px solid #14532d !important;
  font-weight: 700 !important;
  letter-spacing: 0.3px;
  box-shadow: 0 1px 3px rgba(22, 101, 52, 0.25);
}

.badge.badge-normal,
.badge.badge-priority-normal {
  background: var(--navy-50) !important;
  color: var(--navy-600) !important;
  border: 1px solid var(--navy-100) !important;
  font-weight: 600 !important;
}

dialog.modal.priority-urgent {
  border: 2px solid #991b1b !important;
}
dialog.modal.priority-urgent .modal-head {
  background: linear-gradient(180deg, #fff1f2 0%, #ffffff 100%) !important;
  border-bottom: 1px solid #fecaca !important;
}

dialog.modal.priority-important {
  border: 2px solid #166534 !important;
}
dialog.modal.priority-important .modal-head {
  background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%) !important;
  border-bottom: 1px solid #bbf7d0 !important;
}

/* Calendar Navigation & Clickable Dates */
.cal-nav-btn {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  border: 1px solid var(--hairline);
  background: var(--surface);
  color: var(--navy-800);
  cursor: pointer;
  transition: all 0.15s ease;
  padding: 0;
  text-decoration: none;
}
.cal-nav-btn:hover:not(.disabled) {
  background: var(--orange-50);
  border-color: var(--orange-300);
  color: var(--orange-600);
}
.cal-nav-btn.disabled,
.cal-nav-btn:disabled {
  opacity: 0.25 !important;
  cursor: not-allowed !important;
  pointer-events: none;
  background: var(--navy-50) !important;
}

a.cal-day {
  cursor: pointer;
  text-decoration: none;
  display: block;
  transition: transform 0.15s, background 0.15s, color 0.15s;
}
a.cal-day:hover {
  background: var(--navy-100);
  color: var(--navy-900);
  transform: scale(1.08);
}
.cal-day.today {
  background: var(--navy-900) !important;
  color: #ffffff !important;
  font-weight: 700;
}
.cal-day.selected {
  background: var(--orange-500) !important;
  color: #ffffff !important;
  font-weight: 700 !important;
  box-shadow: 0 2px 8px rgba(255, 79, 1, 0.4) !important;
}
.cal-day.selected::after {
  background: #ffffff !important;
}
.cal-day.disabled-future {
  opacity: 0.3 !important;
  cursor: not-allowed !important;
}
#announcements_notices_col,
#announcements_calendar_card,
#announcements_stats {
  transition: opacity 0.18s ease;
}
.cal-loading {
  opacity: 0.45 !important;
  pointer-events: none !important;
  cursor: wait !important;
}
</style>

<div class="page-head">
  <div>
    <h1>Announcements</h1>
    <div class="sub">Notices, circulars and deadlines from the Principal's office</div>
  </div>

  <?php if ($canManage && $ready): ?>
    <div class="actions">
      <button class="btn btn-primary btn-sm" onclick="newAnnouncement()">
        <?= icon('plus') ?> New Announcement
      </button>
    </div>
  <?php endif; ?>
</div>


<?php if (!$ready): ?>

  <!-- The tables have not been created yet -->
  <div class="card">
    <div class="card-body">
      <div class="note-row">
        <div class="note-ic"><?= icon('alert-triangle', 16) ?></div>
        <div>
          <div class="fw-500">One setup step is left</div>
          <div class="card-sub">
            The announcement tables have not been created yet. Run this once, from the
            <code>php-app</code> folder:
            <br><br>
            <code>mysql -u root -p <?= e(DB_NAME) ?> &lt; sql/announcements.sql</code>
            <br><br>
            Then reload this page. Nothing else in the portal is affected until you do.
          </div>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- ======================= Summary cards ======================= -->
  <?php
    $cards = [
        ['Total Announcements', $stats['total'],    'megaphone', 'brand'],
        ['Active Now',          $stats['active'],   'check',     'navy'],
        [$canManage ? 'Past Expired (Stored in DB)' : 'Expiring Soon', $canManage ? ($stats['expired'] ?? 0) : $stats['expiring'], 'clock', ($canManage ? ($stats['expired'] ?? 0) : $stats['expiring']) ? 'brand' : 'navy'],
        [$canManage ? 'Unread by Faculty' : 'Unread by You', $stats['unread'], 'eye', $stats['unread'] ? 'brand' : 'navy'],
    ];
  ?>

  <div class="stat-grid grid-4" id="announcements_stats">
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


  <!-- ======================= Search and filters ======================= -->
  <div class="mt-5 card">
    <div class="card-body filter-bar">
      <form method="get" id="filterForm" class="filter-row">

        <label class="search-box">
          <?= icon('search', 16) ?>
          <input type="text" name="q" id="searchBox" value="<?= e($filters['search']) ?>"
                 placeholder="Search announcements…" autocomplete="off">
        </label>

        <select class="select" name="category" onchange="this.form.submit()" aria-label="Category">
          <option value="">All categories</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= e($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>>
              <?= e($category) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select class="select" name="sort" onchange="this.form.submit()" aria-label="Sort by">
          <?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first',
                          'viewed' => 'Most viewed',  'unread' => 'Unread first'] as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>

        <select class="select" name="scope" onchange="this.form.submit()" aria-label="Show">
          <?php
            $scopes = ['all' => 'Active Announcements', 'bookmarked' => 'Bookmarked'];
            if ($canManage) {
                $scopes['expired']  = 'Past Expired (Stored in DB)';
                $scopes['archived'] = 'Archived Notices';
                $scopes['mine']     = 'Posted by me';
            }
          ?>
          <?php foreach ($scopes as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filters['scope'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>

        <?php if ($filters['search'] !== '' || $filters['category'] !== '' || $filters['scope'] !== 'all'): ?>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('announcements.php')) ?>">Clear</a>
        <?php endif; ?>
      </form>
    </div>
  </div>


  <div class="mt-5 grid-2-1">

    <!-- ======================= The notices ======================= -->
    <div id="announcements_notices_col">

      <?php if (!$isCurrentMonth || $calDay > 0): ?>
        <!-- Calendar filter active banner -->
        <div class="card" style="background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);border-left:4px solid var(--orange-500);border-radius:10px;padding:12px 18px;margin-bottom:18px;box-shadow:0 2px 6px rgba(0,0,0,0.03)">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div style="display:flex;align-items:center;gap:12px">
              <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,79,1,0.12);color:var(--orange-600);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <?= icon('calendar', 20) ?>
              </div>
              <div>
                <div style="font-weight:700;font-size:14px;color:var(--navy-900)">
                  <?php if ($calDay > 0): ?>
                    Showing Announcements for <?= e(date('d F Y', mktime(0, 0, 0, $calMonth, $calDay, $calYear))) ?>
                  <?php else: ?>
                    Showing Announcements for <?= e(date('F Y', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?> (Past Month)
                  <?php endif; ?>
                </div>
                <div style="font-size:12px;color:var(--navy-600);margin-top:2px">
                  <?= $calDay > 0
                        ? 'Filtered by specific date. Future dates and months are restricted.'
                        : 'Viewing announcements for this past month. Future months are strictly restricted.' ?>
                </div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <?php if ($calDay > 0): ?>
                <a class="btn btn-outline btn-sm cal-async-link" href="<?= ann_cal_url(['cal_day' => null, 'page' => null]) ?>">
                  View All <?= e(date('M Y', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?>
                </a>
              <?php endif; ?>
              <a class="btn btn-primary btn-sm cal-async-link" href="<?= ann_cal_url(['cal_year' => $curYear, 'cal_month' => $curMonth, 'cal_day' => null, 'page' => null]) ?>">
                Return to Present Month (<?= e(date('M Y')) ?>)
              </a>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($filters['scope'] === 'expired'): ?>
        <!-- Institutional database archive banner for past expired announcements -->
        <div class="card" style="background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);border-left:4px solid #ef4444;border-radius:10px;padding:14px 18px;margin-bottom:18px;box-shadow:0 2px 6px rgba(0,0,0,0.03)">
          <div style="display:flex;align-items:center;gap:12px">
            <div style="width:36px;height:36px;border-radius:8px;background:rgba(239,68,68,0.12);color:#dc2626;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <?= icon('clock', 20) ?>
            </div>
            <div>
              <div style="font-weight:700;font-size:14px;color:var(--navy-900)">Past Expired Announcements Database Archive</div>
              <div style="font-size:12px;color:var(--navy-600);margin-top:2px">
                All announcements whose deadline or scheduled date has completed are automatically preserved and saved in the institutional database. They are kept permanently for audit records, IQAC compliance, and historical reference.
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($pinned): ?>
        <!-- The one notice everybody should see first -->
        <div class="card ann-featured priority-<?= strtolower($pinned['priority'] ?? 'normal') ?> <?= (int) $pinned['is_read'] === 0 ? 'unread' : '' ?>">
          <div class="card-body">
            <div class="ann-top">
              <span class="badge badge-brand"><?= icon('pin', 12) ?> Pinned</span>
              <span class="badge badge-<?= $priorityClass[$pinned['priority']] ?? 'normal' ?>"><?= e($pinned['priority']) ?></span>
              <span class="badge badge-neutral"><?= e($pinned['category']) ?></span>
              <?php if ((int) $pinned['is_read'] === 0): ?>
                <span class="badge badge-info">New</span>
              <?php endif; ?>
            </div>

            <h2 class="ann-title"><?= e($pinned['title']) ?></h2>
            <p class="ann-preview"><?= e(excerpt($pinned['body'], 220)) ?></p>

            <div class="ann-meta">
              <span><?= icon('user', 14) ?> <?= e($pinned['author_name'] ?: 'Office') ?></span>
              <span><?= icon('calendar', 14) ?> <?= e(date('d M Y', strtotime($pinned['created_at']))) ?></span>
              <?php if ($pinned['expires_at']): ?>
                <span class="<?= strtotime($pinned['expires_at']) < time() ? '' : 'due' ?>">
                  <?= icon('clock', 14) ?> Deadline <?= e(date('d M Y', strtotime($pinned['expires_at']))) ?>
                  (<?= e(time_until($pinned['expires_at'])) ?>)
                </span>
              <?php endif; ?>
              <?php if ((int) $pinned['file_count'] > 0): ?>
                <span><?= icon('paperclip', 14) ?> <?= (int) $pinned['file_count'] ?> attachment<?= $pinned['file_count'] == 1 ? '' : 's' ?></span>
              <?php endif; ?>
            </div>

            <div class="ann-actions">
              <a class="btn btn-primary btn-sm" href="<?= e(url('announcements.php?view=' . $pinned['id'] . ($backQuery ? '&' . $backQuery : ''))) ?>" onclick="if (openAnnModal(<?= (int) $pinned['id'] ?>)) { event.preventDefault(); return false; }">
                View Details
              </a>

              <?php if ((int) $pinned['is_read'] === 0): ?>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="id" value="<?= (int) $pinned['id'] ?>">
                  <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                  <button class="btn btn-outline btn-sm" type="submit"><?= icon('check', 15) ?> Mark as Read</button>
                </form>
              <?php else: ?>
                <span class="badge badge-success"><?= icon('check', 12) ?> Read</span>
              <?php endif; ?>

              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bookmark">
                <input type="hidden" name="id" value="<?= (int) $pinned['id'] ?>">
                <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                <button class="mini-btn <?= (int) $pinned['bookmarked'] ? 'on' : '' ?>" type="submit"
                        title="<?= (int) $pinned['bookmarked'] ? 'Remove bookmark' : 'Bookmark' ?>">
                  <?= icon('bookmark', 15) ?>
                </button>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>


      <?php if (empty($list['rows']) && !$pinned): ?>

        <div class="card">
          <div class="card-body">
            <div class="empty">
              <div class="ic"><?= icon('calendar', 20) ?></div>
              <p>
                <?= $calDay > 0
                      ? 'No announcements on ' . e(date('d F Y', mktime(0, 0, 0, $calMonth, $calDay, $calYear)))
                      : 'No announcements for ' . e(date('F Y', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?>
              </p>
              <div class="note">
                <?php if (!$isCurrentMonth || $calDay > 0): ?>
                  <a class="cal-async-link" href="<?= ann_cal_url(['cal_year' => $curYear, 'cal_month' => $curMonth, 'cal_day' => null, 'page' => null]) ?>"
                     style="color:var(--orange-600);font-weight:600;display:inline-flex;align-items:center;gap:4px">
                    <?= icon('refresh', 13) ?> Return to present month (<?= e(date('F Y')) ?>)
                  </a>
                <?php elseif ($filters['search'] !== ''): ?>
                  Nothing matches that search
                <?php else: ?>
                  <?= $canManage
                        ? 'Use "New Announcement" to publish the first one.'
                        : 'Notices from the Principal\'s office will appear here.' ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      <?php else: ?>

        <?php foreach ($list['rows'] as $row): ?>
          <div class="card ann-card priority-<?= strtolower($row['priority'] ?? 'normal') ?> <?= (int) $row['is_read'] === 0 ? 'unread' : '' ?>">
            <div class="card-body">

              <div class="ann-top">
                <span class="badge badge-<?= $priorityClass[$row['priority']] ?? 'normal' ?>"><?= e($row['priority']) ?></span>
                <span class="badge badge-neutral"><?= e($row['category']) ?></span>
                <span class="faint"><?= e($row['department'] ?: 'All departments') ?></span>

                <?php if ($row['_state'] === 'Expired'): ?>
                  <span class="badge badge-danger" style="display:inline-flex;align-items:center;gap:3px" title="Completed announcement safely stored in database">
                    <?= icon('clock', 11) ?> Expired &middot; Stored in DB
                  </span>
                <?php elseif ($row['_state'] !== 'Active'): ?>
                  <span class="badge badge-info"><?= e($row['_state']) ?></span>
                <?php endif; ?>
                <?php if ((int) $row['is_read'] === 0): ?>
                  <span class="dot-new" title="Not read yet"></span>
                <?php endif; ?>
              </div>

              <a class="ann-title-link" href="<?= e(url('announcements.php?view=' . $row['id'] . ($backQuery ? '&' . $backQuery : ''))) ?>" onclick="if (openAnnModal(<?= (int) $row['id'] ?>)) { event.preventDefault(); return false; }">
                <h3 class="ann-title"><?= e($row['title']) ?></h3>
              </a>

              <p class="ann-preview"><?= e(excerpt($row['body'], 160)) ?></p>

              <div class="ann-meta">
                <span><?= icon('user', 14) ?> <?= e($row['author_name'] ?: 'Office') ?></span>
                <span><?= icon('calendar', 14) ?> <?= e(time_ago($row['created_at'])) ?></span>
                <?php if ($row['expires_at'] && strtotime($row['expires_at']) < time()): ?>
                  <span style="color:#dc2626;font-weight:600" title="Completed date">
                    <?= icon('clock', 13) ?> Completed <?= e(date('d M Y', strtotime($row['expires_at']))) ?>
                  </span>
                <?php endif; ?>
                <?php if ((int) $row['file_count'] > 0): ?>
                  <span><?= icon('paperclip', 14) ?> <?= (int) $row['file_count'] ?></span>
                <?php endif; ?>
                <span><?= icon('eye', 14) ?> <?= (int) $row['views'] ?></span>

                <?php if ($canManage): ?>
                  <?php $reach = announcement_audience_size($row); ?>
                  <span title="<?= (int) $row['read_count'] ?> of <?= (int) $reach ?> have read this">
                    <?= icon('users', 14) ?>
                    <?= $reach > 0 ? round($row['read_count'] / $reach * 100) : 0 ?>% read
                  </span>
                <?php endif; ?>

                <?php if ((int) $row['bookmarked'] === 1): ?>
                  <span class="marked"><?= icon('bookmark', 14) ?> Bookmarked</span>
                <?php endif; ?>
              </div>

            </div>
          </div>
        <?php endforeach; ?>


        <?php if ($list['pages'] > 1): ?>
          <div class="pager">
            <?php for ($p = 1; $p <= $list['pages']; $p++): ?>
              <?php
                $pageQuery = http_build_query(array_filter([
                    'q'        => $filters['search'],
                    'category' => $filters['category'],
                    'sort'     => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
                    'scope'    => $filters['scope'] !== 'all' ? $filters['scope'] : null,
                    'page'     => $p > 1 ? $p : null,
                ]));
              ?>
              <a class="page-link <?= $p === $list['page'] ? 'active' : '' ?> cal-async-link"
                 href="<?= e(url('announcements.php' . ($pageQuery ? '?' . $pageQuery : ''))) ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>


    <!-- ======================= Right sidebar ======================= -->
    <div class="side-col">

      <!-- Deadlines -->
      <div class="card">
        <div class="card-head">
          <div><div class="card-title">Upcoming Deadlines</div></div>
        </div>
        <div class="card-body">
          <?php if (empty($deadlines)): ?>
            <div class="card-sub">Nothing due.</div>
          <?php else: ?>
            <?php foreach ($deadlines as $due): ?>
              <a class="list-row" href="<?= e(url('announcements.php?view=' . $due['id'])) ?>">
                <div class="min-w-0">
                  <div class="t truncate"><?= e($due['title']) ?></div>
                  <div class="card-sub"><?= e(date('d M Y', strtotime($due['expires_at']))) ?></div>
                </div>
                <span class="badge badge-<?= strtotime($due['expires_at']) - time() < 3 * 86400 ? 'warning' : 'neutral' ?>">
                  <?= e(time_until($due['expires_at'])) ?>
                </span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Calendar -->
      <div class="card mt-5" id="announcements_calendar_card">
        <div class="card-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:16px 20px 12px">
          <div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <div class="card-title" style="font-size:16px;font-weight:700;color:var(--navy-900);">
                <?= e(date('F Y', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?>
              </div>
              <?php if ($isCurrentMonth): ?>
                <span class="badge badge-brand" style="font-size:10px;font-weight:700;padding:2px 7px;">Present Month</span>
              <?php else: ?>
                <span class="badge badge-neutral" style="font-size:10px;font-weight:600;padding:2px 7px;background:var(--navy-100);">Past Month</span>
              <?php endif; ?>
            </div>
            <div class="card-sub" style="font-size:12px;margin-top:3px;">
              <?php if ($calDay > 0): ?>
                <span>Date: <strong><?= $calDay ?> <?= e(date('M Y', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?></strong></span>
                &middot; <a class="cal-async-link" href="<?= ann_cal_url(['cal_day' => null, 'page' => null]) ?>" style="color:var(--orange-600);font-weight:600;">All month</a>
              <?php else: ?>
                <span>Days with notices/deadlines marked</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Left and Right Month Navigation Arrows -->
          <div style="display:flex;flex-direction:row;gap:5px;align-items:center;" title="Change Month">
            <a class="cal-nav-btn cal-async-link" href="<?= ann_cal_url(['cal_year' => $prevYear, 'cal_month' => $prevMonth, 'cal_day' => null, 'page' => null]) ?>"
               title="Past Month (Left Arrow)" aria-label="Previous Month">
              <?= icon('chevron-left', 16) ?>
            </a>

            <?php if ($hasNextMonth): ?>
              <a class="cal-nav-btn cal-async-link" href="<?= ann_cal_url(['cal_year' => $nextYear, 'cal_month' => $nextMonth, 'cal_day' => null, 'page' => null]) ?>"
                 title="Next Month (Right Arrow)" aria-label="Next Month">
                <?= icon('chevron-right', 16) ?>
              </a>
            <?php else: ?>
              <button class="cal-nav-btn disabled" disabled
                      title="Future months are not available. Present month is the latest allowed."
                      aria-label="Future months not allowed">
                <?= icon('chevron-right', 16) ?>
              </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Date navigation stepper bar -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 18px;background:rgba(244,246,250,0.6);border-top:1px solid var(--hairline);border-bottom:1px solid var(--hairline);font-size:12px;">
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="color:var(--ink-muted);font-weight:600;">Date:</span>
            <?php if ($calDay > 0): ?>
              <span style="font-weight:700;color:var(--orange-600);"><?= $calDay ?> <?= e(date('M Y', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?></span>
            <?php else: ?>
              <span style="color:var(--ink-muted);">Entire Month</span>
            <?php endif; ?>
          </div>

          <div style="display:flex;align-items:center;gap:5px;">
            <!-- Left arrow for date (previous date) -->
            <?php if ($hasPrevDay): ?>
              <a class="cal-nav-btn cal-async-link" href="<?= ann_cal_url(['cal_day' => $prevDay, 'page' => null]) ?>"
                 title="Previous Date (Left Arrow)" style="width:24px;height:24px;">
                <?= icon('chevron-left', 13) ?>
              </a>
            <?php else: ?>
              <button class="cal-nav-btn disabled" disabled style="width:24px;height:24px;" title="No previous date">
                <?= icon('chevron-left', 13) ?>
              </button>
            <?php endif; ?>

            <!-- Right arrow for date (next date) -->
            <?php if ($hasNextDay && $calDay > 0): ?>
              <a class="cal-nav-btn cal-async-link" href="<?= ann_cal_url(['cal_day' => $nextDay, 'page' => null]) ?>"
                 title="Next Date (Right Arrow)" style="width:24px;height:24px;">
                <?= icon('chevron-right', 13) ?>
              </a>
            <?php else: ?>
              <button class="cal-nav-btn disabled" disabled style="width:24px;height:24px;" title="<?= ($isCurrentMonth && $calDay >= $curDay) ? 'Future dates are not available' : 'Select a date first or at end of month' ?>">
                <?= icon('chevron-right', 13) ?>
              </button>
            <?php endif; ?>

            <?php if (!$isCurrentMonth || $calDay > 0): ?>
              <a href="<?= ann_cal_url(['cal_year' => $curYear, 'cal_month' => $curMonth, 'cal_day' => null, 'page' => null]) ?>"
                 class="btn btn-ghost btn-sm cal-async-link" style="padding:1px 8px;height:24px;font-size:11px;font-weight:600;color:var(--orange-600);"
                 title="Reset to Present Month">
                Today
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div class="card-body" style="padding:16px 18px;">
          <div class="cal">
            <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dayName): ?>
              <div class="cal-head"><?= $dayName ?></div>
            <?php endforeach; ?>

            <?php for ($blank = 0; $blank < $firstDay; $blank++): ?>
              <div></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
              <?php
                $isFutureDay = ($isCurrentMonth && $day > $curDay);
                $isToday = ($isCurrentMonth && $day === $curDay);
                $isSelected = ($calDay === $day);
                $hasNotices = isset($calDays[$day]);
                $dayTitle = ($isToday ? 'Today · ' : '') . ($hasNotices ? (int)$calDays[$day] . ' announcement(s)/deadline(s)' : 'Day ' . $day);
                if ($isFutureDay) {
                    $dayTitle = 'Future date (no past announcements)';
                }
              ?>
              <?php if ($isFutureDay): ?>
                <div class="cal-day disabled-future" title="<?= e($dayTitle) ?>" style="opacity:0.25;cursor:not-allowed;">
                  <?= $day ?>
                </div>
              <?php else: ?>
                <a class="cal-day <?= $isToday ? 'today' : '' ?> <?= $isSelected ? 'selected' : '' ?> <?= $hasNotices ? 'has-due' : '' ?> cal-async-link"
                   href="<?= ann_cal_url(['cal_day' => ($isSelected ? null : $day), 'page' => null]) ?>"
                   title="<?= e($dayTitle) ?>"
                   style="<?= $isSelected ? 'background:var(--orange-500)!important;color:#fff!important;font-weight:700;' : '' ?>">
                  <?= $day ?>
                </a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="card mt-5">
        <div class="card-head">
          <div><div class="card-title">Quick Actions</div></div>
        </div>
        <div class="card-body">
          <?php
            $quick = [['Bookmarked', 'bookmark', 'announcements.php?scope=bookmarked']];
            if ($canManage) {
                $quick[] = ['Past Expired in DB', 'clock',     'announcements.php?scope=expired'];
                $quick[] = ['Archived',           'archive',   'announcements.php?scope=archived'];
                $quick[] = ['Posted by me',       'megaphone', 'announcements.php?scope=mine'];
            }
            $quick[] = ['Unread first', 'eye', 'announcements.php?sort=unread'];
          ?>
          <?php foreach ($quick as [$label, $iconName, $href]): ?>
            <a class="list-row" href="<?= e(url($href)) ?>">
              <span class="flex items-center gap-3"><?= icon($iconName, 15) ?> <span class="t"><?= e($label) ?></span></span>
              <?= icon('chevron', 14) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Attachments -->
      <div class="card mt-5">
        <div class="card-head">
          <div><div class="card-title">Recent Attachments</div></div>
        </div>
        <div class="card-body">
          <?php if (empty($recentFiles)): ?>
            <div class="card-sub">No files shared yet.</div>
          <?php else: ?>
            <?php foreach ($recentFiles as $file): ?>
              <a class="list-row" href="<?= e(url('download.php?file=' . $file['id'])) ?>">
                <div class="min-w-0">
                  <div class="t truncate"><?= e($file['file_name']) ?></div>
                  <div class="card-sub truncate"><?= e($file['title']) ?></div>
                </div>
                <span class="faint nowrap"><?= e(human_size((int) $file['size_bytes'])) ?></span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>


  <!-- ==========================================================================
       One dialog per notice on this page — the "full announcement" view
       ======================================================================= -->
  <?php
    $openRows = $list['rows'];
    if ($pinned) {
        array_unshift($openRows, $pinned);
    }

    // If ?view= points at something not on this page, load it on its own.
    $openIds = array_column($openRows, 'id');
    if ($openId > 0 && !in_array($openId, array_map('intval', $openIds), true)) {
        $extra = announcement_find($openId, $user);
        if ($extra) {
            $extra['file_count'] = count(announcement_files($openId));
            $extra['is_read']    = 1;
            $extra['bookmarked'] = 0;
            $extra['read_count'] = 0;
            $openRows[] = $extra;
        }
    }
  ?>

  <div id="announcements_modals_container">
  <?php foreach ($openRows as $row): ?>
    <?php $files = announcement_files((int) $row['id']); ?>

    <dialog class="modal modal-wide priority-<?= strtolower($row['priority'] ?? 'normal') ?>" id="ann-<?= (int) $row['id'] ?>">
      <div class="modal-head">
        <div class="min-w-0">
          <div class="ann-top">
            <span class="badge badge-<?= $priorityClass[$row['priority']] ?? 'normal' ?>"><?= e($row['priority']) ?></span>
            <span class="badge badge-neutral"><?= e($row['category']) ?></span>
            <?php if ($row['_state'] !== 'Active'): ?>
              <span class="badge badge-info"><?= e($row['_state']) ?></span>
            <?php endif; ?>
          </div>
          <h3><?= e($row['title']) ?></h3>
          <div class="msub">
            <?= e($row['author_name'] ?: 'Office') ?> ·
            <?= e(date('d M Y', strtotime($row['created_at']))) ?> ·
            <?= e($row['department'] ?: 'All departments') ?> ·
            <?= e($row['audience']) ?>
          </div>
        </div>
        <button class="mini-btn" onclick="this.closest('dialog').close()" aria-label="Close"><?= icon('x', 16) ?></button>
      </div>

      <div class="modal-body">

        <?php if ($row['expires_at']): ?>
          <?php $isNoticeExpired = (strtotime($row['expires_at']) < time()); ?>
          <div class="deadline-strip" style="<?= $isNoticeExpired ? 'background:#fef2f2;border-color:#fecaca;color:#991b1b;' : '' ?>">
            <?= icon('clock', 15) ?>
            <?php if ($isNoticeExpired): ?>
              <span>Completed &amp; Expired on <strong><?= e(date('d M Y, h:i A', strtotime($row['expires_at']))) ?></strong>
                &middot; <strong style="color:#b91c1c">Past announcement safely saved and stored in institutional database</strong></span>
            <?php else: ?>
              <span>Deadline <strong><?= e(date('d M Y, H:i', strtotime($row['expires_at']))) ?></strong>
                — <?= e(time_until($row['expires_at'])) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="rich"><?= format_text($row['body']) ?></div>

        <?php if (!empty($files)): ?>
          <div class="attach-list">
            <div class="attach-title"><?= icon('paperclip', 14) ?> Attachments</div>
            <?php foreach ($files as $file): ?>
              <a class="attach-row" href="<?= e(url('download.php?file=' . $file['id'])) ?>">
                <span class="attach-ic"><?= icon('file-text', 15) ?></span>
                <span class="min-w-0">
                  <span class="t truncate"><?= e($file['file_name']) ?></span>
                  <span class="card-sub"><?= e(human_size((int) $file['size_bytes'])) ?></span>
                </span>
                <span class="faint"><?= icon('download', 15) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>


        <?php if ($canManage): ?>
          <!-- Who has read it -->
          <?php $analytics = announcement_analytics((int) $row['id'], $row); ?>

          <div class="analytics">
            <div class="attach-title"><?= icon('pie', 14) ?> Read analytics</div>

            <div class="meter-head">
              <span class="fw-500"><?= (int) $analytics['read'] ?> of <?= (int) $analytics['audience'] ?> have read this</span>
              <span class="tabular fw-600"><?= (int) $analytics['percent'] ?>%</span>
            </div>
            <div class="meter-track">
              <span class="meter-fill" style="width:<?= (int) $analytics['percent'] ?>%"></span>
            </div>

            <?php if (!empty($analytics['byDepartment'])): ?>
              <div class="mt-2">
                <?php foreach ($analytics['byDepartment'] as $dept => $split): ?>
                  <div class="bar-row thin">
                    <span class="bar-label truncate"><?= e($dept) ?></span>
                    <span class="bar-track">
                      <span class="bar-fill" style="width:<?= $split['total'] ? round($split['read'] / $split['total'] * 100) : 0 ?>%"></span>
                    </span>
                    <span class="bar-num tabular"><?= (int) $split['read'] ?>/<?= (int) $split['total'] ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($analytics['unread'])): ?>
              <div class="mt-2 card-sub">
                Not read yet:
                <?php
                  $names = array_column(array_slice($analytics['unread'], 0, 6), 'name');
                  echo e(implode(', ', $names));
                  if (count($analytics['unread']) > 6) {
                      echo ' and ' . (count($analytics['unread']) - 6) . ' more';
                  }
                ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>

      <div class="modal-foot between">
        <div class="flex gap-2 items-center">
          <?php if ($canManage): ?>
            <button class="btn btn-outline btn-sm" onclick='editAnnouncement(<?= e(json_encode($row)) ?>)'>
              <?= icon('pencil', 15) ?> Edit
            </button>

            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= (int) $row['pinned'] ? 'unpin' : 'pin' ?>">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="back" value="<?= e($backQuery) ?>">
              <button class="btn btn-outline btn-sm" type="submit">
                <?= icon('pin', 15) ?> <?= (int) $row['pinned'] ? 'Unpin' : 'Pin' ?>
              </button>
            </form>

            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $row['status'] === 'Archived' ? 'restore' : 'archive' ?>">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="back" value="<?= e($backQuery) ?>">
              <button class="btn btn-outline btn-sm" type="submit">
                <?= icon('archive', 15) ?> <?= $row['status'] === 'Archived' ? 'Restore' : 'Archive' ?>
              </button>
            </form>

            <button class="mini-btn danger" title="Delete"
              onclick='deleteAnnouncement(<?= (int) $row["id"] ?>, <?= htmlspecialchars(json_encode($row["title"]), ENT_QUOTES) ?>)'>
              <?= icon('trash', 15) ?>
            </button>
          <?php endif; ?>
        </div>

        <div class="flex gap-2 items-center">
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bookmark">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="back" value="<?= e($backQuery) ?>">
            <button class="btn btn-outline btn-sm" type="submit">
              <?= icon('bookmark', 15) ?> <?= (int) $row['bookmarked'] ? 'Bookmarked' : 'Bookmark' ?>
            </button>
          </form>

          <?php if ((int) $row['is_read'] === 0): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="back" value="<?= e($backQuery) ?>">
              <button class="btn btn-primary btn-sm" type="submit">
                <?= icon('check', 15) ?>
                <?= (int) $row['require_read'] ? 'I have read this' : 'Mark as Read' ?>
              </button>
            </form>
          <?php else: ?>
            <span class="badge badge-success"><?= icon('check', 12) ?> Read</span>
          <?php endif; ?>
        </div>
      </div>
    </dialog>
  <?php endforeach; ?>
  </div>


  <?php if ($canManage): ?>
    <!-- ======================= Write / edit ======================= -->
    <dialog class="modal modal-wide" id="editor">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="f-action" value="create">
        <input type="hidden" name="id" id="f-id">
        <input type="hidden" name="back" value="<?= e($backQuery) ?>">
        <input type="hidden" name="status" id="f-status" value="Published">

        <div class="modal-head">
          <div>
            <h3 id="f-heading">New Announcement</h3>
            <div class="msub">It appears for the audience you choose, straight away or on a date you set.</div>
          </div>
          <button type="button" class="mini-btn" onclick="this.closest('dialog').close()" aria-label="Close"><?= icon('x', 16) ?></button>
        </div>

        <div class="modal-body">

          <div class="field">
            <label for="f-title">Title <span class="req">*</span></label>
            <input class="input" name="title" id="f-title" required maxlength="200"
                   placeholder="e.g. IQAC data submission for the current academic year">
          </div>

          <div class="field">
            <label for="f-body">Message <span class="req">*</span></label>
            <textarea class="input textarea" name="body" id="f-body" rows="9" required
                      placeholder="Write the notice here…"></textarea>
            <div class="hint">
              Leave a blank line between paragraphs. Start a line with <code>- </code> for a bullet,
              and wrap words in <code>**stars**</code> to make them bold.
            </div>
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="f-category">Category</label>
              <select class="select" name="category" id="f-category">
                <?php foreach ($categories as $category): ?>
                  <option value="<?= e($category) ?>"><?= e($category) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="f-priority">Priority</label>
              <select class="select" name="priority" id="f-priority">
                <?php foreach (announcement_priorities() as $priority): ?>
                  <option value="<?= e($priority) ?>"><?= e($priority) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="f-audience">Target audience</label>
              <select class="select" name="audience" id="f-audience">
                <option value="Everyone">Everyone</option>
                <option value="HoD">Heads of Department</option>
                <option value="Coordinator">Coordinators</option>
                <option value="Faculty">Faculty</option>
              </select>
            </div>

            <div class="field">
              <label for="f-department">Department</label>
              <select class="select" name="department" id="f-department">
                <option value="">All departments</option>
                <?php foreach ($departments as $dept): ?>
                  <option value="<?= e($dept['name']) ?>"><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="f-publish">Publish on</label>
              <input class="input" type="datetime-local" name="publish_at" id="f-publish">
              <div class="hint">Leave empty to publish immediately.</div>
            </div>

            <div class="field">
              <label for="f-expires">Deadline / expiry</label>
              <input class="input" type="datetime-local" name="expires_at" id="f-expires">
              <div class="hint">After this it stops showing for readers.</div>
            </div>
          </div>

          <div class="field">
            <label for="f-files">Attachments</label>
            <input class="input file" type="file" name="attachments[]" id="f-files" multiple
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg">
            <div class="hint">PDF, Word, Excel, PowerPoint or images. Up to 5 MB each.</div>
          </div>

          <div class="check-row">
            <label class="check">
              <input type="checkbox" name="pinned" id="f-pinned" value="1">
              <span>Pin to the top <span class="card-sub">(only one notice can be pinned)</span></span>
            </label>

            <label class="check">
              <input type="checkbox" name="require_read" id="f-require" value="1">
              <span>Require read confirmation <span class="card-sub">(readers get an "I have read this" button)</span></span>
            </label>
          </div>

        </div>

        <div class="modal-foot">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
          <button type="submit" class="btn btn-outline btn-sm" onclick="document.getElementById('f-status').value='Draft'">
            <?= icon('save', 15) ?> Save Draft
          </button>
          <button type="submit" class="btn btn-primary btn-sm" onclick="document.getElementById('f-status').value='Published'">
            <?= icon('send', 15) ?> Publish
          </button>
        </div>
      </form>
    </dialog>

    <!-- Delete confirmation -->
    <dialog class="modal" id="delAnn" style="max-width:28rem">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="del-ann-id">
        <input type="hidden" name="back" value="<?= e($backQuery) ?>">

        <div class="modal-head"><div><h3>Delete announcement?</h3></div></div>
        <div class="modal-body">
          <p class="modal-text">
            <strong id="del-ann-title"></strong> will be removed, along with its attachments
            and read receipts. This cannot be undone — use Archive if you only want it out of the way.
          </p>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>


  <script>
    /* Search as you type: wait until typing stops, then submit the filter form. */
    (function () {
      var box = document.getElementById('searchBox');
      if (!box) return;

      var timer;
      box.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          document.getElementById('filterForm').submit();
        }, 600);
      });
    })();

    <?php if ($canManage): ?>
    /* Open the editor empty, ready for a new notice. */
    function newAnnouncement() {
      document.getElementById('f-action').value  = 'create';
      document.getElementById('f-heading').textContent = 'New Announcement';
      document.getElementById('f-id').value       = '';
      document.getElementById('f-title').value    = '';
      document.getElementById('f-body').value     = '';
      document.getElementById('f-category').value = 'Academic';
      document.getElementById('f-priority').value = 'Normal';
      document.getElementById('f-audience').value = 'Everyone';
      document.getElementById('f-department').value = '';
      document.getElementById('f-publish').value  = '';
      document.getElementById('f-expires').value  = '';
      document.getElementById('f-pinned').checked  = false;
      document.getElementById('f-require').checked = false;
      document.getElementById('editor').showModal();
    }

    /* Open the editor filled in with an existing notice. */
    function editAnnouncement(row) {
      document.getElementById('f-action').value  = 'update';
      document.getElementById('f-heading').textContent = 'Edit Announcement';
      document.getElementById('f-id').value       = row.id;
      document.getElementById('f-title').value    = row.title;
      document.getElementById('f-body').value     = row.body;
      document.getElementById('f-category').value = row.category;
      document.getElementById('f-priority').value = row.priority;
      document.getElementById('f-audience').value = row.audience;
      document.getElementById('f-department').value = row.department || '';

      // The date inputs want "YYYY-MM-DDTHH:MM"; MySQL gives a space instead of a T.
      document.getElementById('f-publish').value = row.publish_at ? row.publish_at.replace(' ', 'T').slice(0, 16) : '';
      document.getElementById('f-expires').value = row.expires_at ? row.expires_at.replace(' ', 'T').slice(0, 16) : '';

      document.getElementById('f-pinned').checked  = row.pinned == 1;
      document.getElementById('f-require').checked = row.require_read == 1;

      // Close whichever detail dialog is open, then show the editor.
      document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });
      document.getElementById('editor').showModal();
    }

    function deleteAnnouncement(id, title) {
      document.getElementById('del-ann-id').value = id;
      document.getElementById('del-ann-title').textContent = title;
      document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });
      document.getElementById('delAnn').showModal();
    }
    <?php endif; ?>

    /* Open announcement modal without full page reload */
    function openAnnModal(id) {
      var d = document.getElementById('ann-' + id);
      if (d && typeof d.showModal === 'function') {
        document.querySelectorAll('dialog[open]').forEach(function (openD) { openD.close(); });
        d.showModal();
        return true;
      }
      return false;
    }

    /* Asynchronous Calendar Navigation (Smooth update without full page refresh) */
    function loadCalendarAsync(url, pushHistory) {
      var noticesCol = document.getElementById('announcements_notices_col');
      var calendarCard = document.getElementById('announcements_calendar_card');
      var modalsContainer = document.getElementById('announcements_modals_container');
      var curStats = document.getElementById('announcements_stats');

      if (!noticesCol || !calendarCard) {
        window.location.href = url;
        return;
      }

      // Close open modals when switching calendar view
      document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });

      // Visual smooth loading indicator
      noticesCol.classList.add('cal-loading');
      calendarCard.classList.add('cal-loading');

      fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');

        var newNotices = doc.getElementById('announcements_notices_col');
        var newCalendar = doc.getElementById('announcements_calendar_card');
        var newModals = doc.getElementById('announcements_modals_container');
        var newStats = doc.getElementById('announcements_stats');

        if (newNotices) noticesCol.innerHTML = newNotices.innerHTML;
        if (newCalendar) calendarCard.innerHTML = newCalendar.innerHTML;
        if (newModals && modalsContainer) modalsContainer.innerHTML = newModals.innerHTML;
        if (newStats && curStats) curStats.innerHTML = newStats.innerHTML;

        if (pushHistory && window.history && window.history.pushState) {
          window.history.pushState({ calUrl: url }, '', url);
        }
      })
      .catch(function (err) {
        console.warn('Calendar AJAX fallback:', err);
        window.location.href = url;
      })
      .finally(function () {
        noticesCol.classList.remove('cal-loading');
        calendarCard.classList.remove('cal-loading');
      });
    }

    // Intercept clicks on calendar and calendar-filter links
    document.addEventListener('click', function (e) {
      var link = e.target.closest('#announcements_calendar_card a, .cal-async-link, a[data-cal-nav]');
      if (!link) return;

      if (link.classList.contains('disabled') || link.getAttribute('disabled') !== null || link.getAttribute('aria-disabled') === 'true') {
        e.preventDefault();
        return;
      }

      var href = link.getAttribute('href');
      if (!href || href === '#' || href.indexOf('javascript:') === 0) return;

      e.preventDefault();
      loadCalendarAsync(href, true);
    });

    // Handle browser back/forward navigation seamlessly
    window.addEventListener('popstate', function (e) {
      var targetUrl = (e.state && e.state.calUrl) ? e.state.calUrl : window.location.href;
      loadCalendarAsync(targetUrl, false);
    });

    // Record initial history state
    if (window.history && window.history.replaceState && !window.history.state) {
      window.history.replaceState({ calUrl: window.location.href }, '', window.location.href);
    }

    <?php if ($openId > 0): ?>
    /* ?view=<id> in the address bar opens that announcement straight away. */
    (function () {
      var dialog = document.getElementById('ann-<?= (int) $openId ?>');
      if (dialog) dialog.showModal();
    })();
    <?php endif; ?>
  </script>

<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
