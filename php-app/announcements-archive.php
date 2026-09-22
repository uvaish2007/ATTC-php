<?php
/**
 * FEAT-12 — Announcement Archive (Admin only).
 *
 * The historical database copy of every announcement that has expired, read
 * from announcements_archive rather than from announcements. Announcements
 * themselves are still managed on announcements.php; nothing on this page
 * writes to that screen's state except Restore, which brings one archived
 * notice back.
 *
 * Authorisation is server-side and unconditional: require_role(['Admin'])
 * runs before anything is read or written, so nothing here depends on a menu
 * entry or a button being hidden. The archive id in a POST is only ever an
 * integer looked up through a prepared statement, an archive record that has
 * already been restored is refused by the model whatever the browser sends,
 * and Restore is POST-only — a GET can never restore anything.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Announcement.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin']);
require_module('announcements-archive');

// -------------------------------------------------------------------------
// Actions
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string) input('action') === 'restore') {
        [$ok, $msg] = announcement_restore_from_archive(
            (int) input('archive_id'),
            (int) $user['id'],
            (string) input('new_expires_at')
        );
        flash($ok ? 'success' : 'error', $msg);
    }

    // Back to the view the decision was made from. Only ordinary query
    // characters are carried over, so nothing can be smuggled into Location.
    $back      = '/announcements-archive.php';
    $backInput = (string) input('back', '');

    if ($backInput !== '' && preg_match('/^[\w=&%.+\-:]+$/', $backInput)) {
        $back .= '?' . $backInput;
    }

    redirect($back);
}

// -------------------------------------------------------------------------
// Bring the archive up to date before reading it, so a notice that expired
// since the last page view is already in here.
// -------------------------------------------------------------------------
announcement_sync_expired();

$archiveReady = announcements_archive_ready();

// -------------------------------------------------------------------------
// Filters (all live in the address bar, so a filtered view can be shared)
// -------------------------------------------------------------------------
$sorts = [
    'archived_new' => 'Newest archived',
    'archived_old' => 'Oldest archived',
    'expiry_new'   => 'Newest expiry',
    'expiry_old'   => 'Oldest expiry',
    'title'        => 'Title (A–Z)',
];

$filters = [
    'search'         => trim((string) input('q', '')),
    'category'       => trim((string) input('category', '')),
    'department'     => trim((string) input('department', '')),
    'restore_status' => trim((string) input('state', '')),
    'archived_from'  => trim((string) input('from', '')),
    'archived_to'    => trim((string) input('to', '')),
    'sort'           => trim((string) input('sort', 'archived_new')),
    'page'           => (int) input('page', 1),
    'per_page'       => 10,
];

// Unknown values are corrected here as well as in the model, so the form shows
// the filter that is actually being applied.
if (!isset($sorts[$filters['sort']]))                                 $filters['sort'] = 'archived_new';
if (!in_array($filters['restore_status'], ['Archived', 'Restored'], true)) $filters['restore_status'] = '';
if (!in_array($filters['category'], announcement_categories(), true))  $filters['category'] = '';

$list        = announcements_archive_list($filters);
$counts      = announcement_archive_counts();
$categories  = announcement_categories();
$departments = $archiveReady ? departments_all() : [];

// Attachments for the notices on this page, in one query. They still belong to
// the original announcement — see announcement_archive_attachments().
$attachments = announcement_archive_attachments(array_column($list['rows'], 'original_announcement_id'));

// The query string a Restore POST returns to, so the Admin lands back on the
// same filtered page.
$backQuery = http_build_query(array_filter([
    'q'          => $filters['search'],
    'category'   => $filters['category'],
    'department' => $filters['department'],
    'state'      => $filters['restore_status'],
    'from'       => $filters['archived_from'],
    'to'         => $filters['archived_to'],
    'sort'       => $filters['sort'] !== 'archived_new' ? $filters['sort'] : null,
    'page'       => $filters['page'] > 1 ? $filters['page'] : null,
]));

$activeFilters = ($filters['search'] !== '' ? 1 : 0) + ($filters['category'] !== '' ? 1 : 0)
               + ($filters['department'] !== '' ? 1 : 0) + ($filters['restore_status'] !== '' ? 1 : 0)
               + ($filters['archived_from'] !== '' ? 1 : 0) + ($filters['archived_to'] !== '' ? 1 : 0)
               + ($filters['sort'] !== 'archived_new' ? 1 : 0);

$fmt = static fn(?string $value): string => $value ? date('d-m-Y H:i', strtotime($value)) : '—';

$pageTitle  = 'Announcement Archive';
$breadcrumb = 'Announcement Archive';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Announcement Archive</h1>
    <div class="sub">Historical archive of expired announcements</div>
  </div>
  <div class="actions">
    <a class="btn btn-outline btn-sm" href="<?= e(url('announcements.php')) ?>">
      <?= icon('megaphone', 14) ?> Announcements
    </a>
  </div>
</div>

<?php if (!$archiveReady): ?>

  <!-- The archive table has not been created yet -->
  <div class="card">
    <div class="card-body">
      <div class="note-row">
        <div class="note-ic"><?= icon('alert-triangle', 16) ?></div>
        <div>
          <div class="fw-500">One setup step is left</div>
          <div class="card-sub">
            The announcement archive table has not been created yet. Run this once, from the
            <code>php-app</code> folder:
            <br><br>
            <code>mysql -u root -p <?= e(DB_NAME) ?> &lt; sql/announcements_archive.sql</code>
            <br><br>
            Until then announcements still expire exactly as before — nothing else in the portal
            is affected, and no announcement is lost.
          </div>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- ======================= Summary cards ======================= -->
  <?php
    $cards = [
        ['Archived Announcements', $counts['total'],    'archive', 'brand'],
        ['Still Archived',         $counts['archived'], 'clock',   'navy'],
        ['Restored',               $counts['restored'], 'refresh', $counts['restored'] ? 'brand' : 'navy'],
    ];
  ?>
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

  <!-- ======================= Search and filters ======================= -->
  <form method="get" class="fbar mt-5">
    <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

    <label class="fb-field fb-search<?= $filters['search'] !== '' ? ' is-set' : '' ?>">
      <?= icon('search', 15) ?>
      <input type="search" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Search title or message…" aria-label="Search the archive">
      <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
    </label>

    <label class="fb-field<?= $filters['category'] !== '' ? ' is-set' : '' ?>"><span class="fb-k">Category</span>
      <select name="category" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= e($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= e($category) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field<?= $filters['department'] !== '' ? ' is-set' : '' ?>"><span class="fb-k">Department</span>
      <select name="department" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($departments as $dept): ?>
          <?php $dname = is_array($dept) ? (string) ($dept['name'] ?? '') : (string) $dept; ?>
          <?php if ($dname === '') continue; ?>
          <option value="<?= e($dname) ?>" <?= $filters['department'] === $dname ? 'selected' : '' ?>><?= e($dname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field<?= $filters['restore_status'] !== '' ? ' is-set' : '' ?>"><span class="fb-k">State</span>
      <select name="state" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="Archived" <?= $filters['restore_status'] === 'Archived' ? 'selected' : '' ?>>Archived</option>
        <option value="Restored" <?= $filters['restore_status'] === 'Restored' ? 'selected' : '' ?>>Restored</option>
      </select>
    </label>

    <label class="fb-field<?= $filters['archived_from'] !== '' ? ' is-set' : '' ?>"><span class="fb-k">Archived from</span>
      <input type="date" name="from" value="<?= e($filters['archived_from']) ?>" onchange="this.form.submit()">
    </label>

    <label class="fb-field<?= $filters['archived_to'] !== '' ? ' is-set' : '' ?>"><span class="fb-k">to</span>
      <input type="date" name="to" value="<?= e($filters['archived_to']) ?>" onchange="this.form.submit()">
    </label>

    <label class="fb-field<?= $filters['sort'] !== 'archived_new' ? ' is-set' : '' ?>"><span class="fb-k">Sort</span>
      <select name="sort" onchange="this.form.submit()">
        <?php foreach ($sorts as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <span class="fbar-end">
      <?php if ($activeFilters): ?>
        <span class="fbar-count"><?= (int) $activeFilters ?> active</span>
        <a class="fbar-clear" href="<?= e(url('announcements-archive.php')) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note">Newest archived first</span>
      <?php endif; ?>
    </span>
  </form>

  <?php if (empty($list['rows'])): ?>

    <div class="card">
      <div class="empty">
        <div class="ic"><?= icon('archive', 20) ?></div>
        <p><?= $activeFilters ? 'No archived announcements match these filters' : 'No expired announcements have been archived yet.' ?></p>
        <div class="note">
          <?php if ($activeFilters): ?>
            Try widening the search or clearing the filters.
          <?php else: ?>
            An announcement is copied here automatically once its expiry date passes.
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php else: ?>

    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Archived Announcements</div>
          <div class="card-sub">
            Copied here automatically when a notice expired &middot;
            showing <?= count($list['rows']) ?> of <?= (int) $list['total'] ?>
          </div>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th style="width:80px">Orig. ID</th>
              <th>Announcement</th>
              <th style="width:130px">Category</th>
              <th style="width:150px">Department</th>
              <th style="width:135px">Expired</th>
              <th style="width:135px">Archived</th>
              <th style="width:105px">State</th>
              <th style="width:170px" class="num">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($list['rows'] as $row): ?>
            <?php
              $archiveId  = (int) $row['archive_id'];
              $originalId = (int) $row['original_announcement_id'];
              $restored   = $row['restore_status'] === 'Restored';
              $files      = $attachments[$originalId] ?? [];

              // Everything the details dialog shows, handed over as one JSON
              // payload. htmlspecialchars keeps it inside the attribute.
              $payload = [
                  'archive_id'   => $archiveId,
                  'original_id'  => $originalId,
                  'title'        => (string) $row['title'],
                  'body'         => (string) $row['body'],
                  'category'     => (string) $row['category'],
                  'priority'     => (string) $row['priority'],
                  'audience'     => (string) $row['audience'],
                  'department'   => (string) ($row['department'] ?: 'All departments'),
                  'status'       => (string) $row['original_status'],
                  'published'    => $fmt($row['publish_at'] ?? null),
                  'expires'      => $fmt($row['expires_at'] ?? null),
                  'created'      => $fmt($row['created_at'] ?? null),
                  'archived'     => $fmt($row['archived_at'] ?? null),
                  'author'       => (string) ($row['created_by_name'] ?: 'Unknown'),
                  'views'        => (int) $row['views'],
                  'require_read' => (int) $row['require_read'] ? 'Yes' : 'No',
                  'restored'     => $restored,
                  'restored_at'  => $fmt($row['restored_at'] ?? null),
                  'restored_by'  => (string) ($row['restored_by_name'] ?: ''),
                  'restored_id'  => (int) ($row['restored_announcement_id'] ?? 0),
                  'live'         => (int) $row['original_exists'] > 0,
                  'needs_expiry' => announcement_archive_needs_expiry($row),
                  'files'        => array_map(static fn($f) => [
                      'id'   => (int) $f['id'],
                      'name' => (string) $f['file_name'],
                      'size' => human_size((int) $f['size_bytes']),
                  ], $files),
              ];
            ?>
            <tr id="ar-<?= $archiveId ?>">
              <td class="tabular">#<?= $originalId ?></td>
              <td>
                <div class="min-w-0">
                  <div class="truncate" style="font-weight:600" title="<?= e($row['title']) ?>"><?= e($row['title']) ?></div>
                  <div class="card-sub truncate"><?= e(excerpt((string) $row['body'], 90)) ?></div>
                </div>
              </td>
              <td><span class="badge badge-neutral"><?= e($row['category']) ?></span></td>
              <td class="card-sub"><?= e($row['department'] ?: 'All departments') ?></td>
              <td class="card-sub tabular"><?= e($fmt($row['expires_at'] ?? null)) ?></td>
              <td class="card-sub tabular"><?= e($fmt($row['archived_at'] ?? null)) ?></td>
              <td>
                <span class="badge badge-<?= $restored ? 'success' : 'warning' ?>"><?= $restored ? 'Restored' : 'Archived' ?></span>
              </td>
              <td class="num">
                <button type="button" class="btn btn-<?= $restored ? 'outline' : 'primary' ?> btn-sm"
                        onclick='archiveDetails(<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                  View Details
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($list['pages'] > 1): ?>
      <div class="pager">
        <?php for ($p = 1; $p <= $list['pages']; $p++): ?>
          <?php
            $pageQuery = http_build_query(array_filter([
                'q'          => $filters['search'],
                'category'   => $filters['category'],
                'department' => $filters['department'],
                'state'      => $filters['restore_status'],
                'from'       => $filters['archived_from'],
                'to'         => $filters['archived_to'],
                'sort'       => $filters['sort'] !== 'archived_new' ? $filters['sort'] : null,
                'page'       => $p > 1 ? $p : null,
            ]));
          ?>
          <a class="page-link <?= $p === $list['page'] ? 'active' : '' ?>"
             href="<?= e(url('announcements-archive.php' . ($pageQuery ? '?' . $pageQuery : ''))) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <!-- ======================= Details / restore dialog ======================= -->
  <dialog class="modal" id="archiveDlg" style="max-width:46rem">
    <form method="post" id="restoreForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="restore">
      <input type="hidden" name="archive_id" id="ad-archive-id">
      <input type="hidden" name="back" value="<?= e($backQuery) ?>">

      <div class="modal-head">
        <div>
          <h3 id="ad-title">Archived Announcement</h3>
          <div class="msub">Original announcement <span id="ad-orig" class="tabular"></span> &middot; historical archive copy</div>
        </div>
        <span class="badge" id="ad-state"></span>
      </div>

      <div class="modal-body">
        <div style="display:grid;grid-template-columns:140px 1fr;gap:9px 14px;font-size:13.5px">
          <div class="card-sub">Category</div>        <div id="ad-category"></div>
          <div class="card-sub">Priority</div>        <div id="ad-priority"></div>
          <div class="card-sub">Audience</div>        <div id="ad-audience"></div>
          <div class="card-sub">Department</div>      <div id="ad-department"></div>
          <div class="card-sub">Original status</div> <div id="ad-status"></div>
          <div class="card-sub">Created</div>         <div id="ad-created" class="tabular"></div>
          <div class="card-sub">Published</div>       <div id="ad-published" class="tabular"></div>
          <div class="card-sub">Expiry date</div>     <div id="ad-expires" class="tabular"></div>
          <div class="card-sub">Archived</div>        <div id="ad-archived" class="tabular"></div>
          <div class="card-sub">Created by</div>      <div id="ad-author"></div>
          <div class="card-sub">Views</div>           <div id="ad-views" class="tabular"></div>
          <div class="card-sub">Read required</div>   <div id="ad-require"></div>
        </div>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--hairline)">
          <div class="card-sub" style="margin-bottom:6px">Message</div>
          <div id="ad-body" style="white-space:pre-wrap;font-size:13.5px;line-height:1.6"></div>
        </div>

        <div id="ad-files-wrap" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--hairline);display:none">
          <div class="card-sub" style="margin-bottom:6px">Attachments</div>
          <div id="ad-files" style="display:flex;flex-direction:column;gap:6px;font-size:13px"></div>
        </div>

        <!-- Shown when the original announcement row has been deleted -->
        <div id="ad-gone" style="display:none;margin-top:16px">
          <div class="note-row">
            <div class="note-ic"><?= icon('info', 16) ?></div>
            <div class="card-sub">
              The original announcement has been deleted from the active table. Restoring will
              recreate it from this archive copy under a new announcement ID. Any files that were
              attached to the original were removed with it and cannot be brought back.
            </div>
          </div>
        </div>

        <!-- Already restored: read-only, and the form offers nothing -->
        <div id="ad-restored" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--hairline)">
          <div style="display:grid;grid-template-columns:140px 1fr;gap:9px 14px;font-size:13.5px">
            <div class="card-sub">Restored</div>    <div id="ad-restored-at" class="tabular"></div>
            <div class="card-sub">Restored by</div> <div id="ad-restored-by"></div>
            <div class="card-sub">Restored as</div> <div id="ad-restored-id" class="tabular"></div>
          </div>
          <div class="card-sub" style="margin-top:10px">
            This archived announcement has already been restored. It is kept here permanently for
            the audit trail and cannot be restored a second time.
          </div>
        </div>

        <!-- Restore controls -->
        <div id="ad-actions" style="display:none;margin-top:18px;padding-top:16px;border-top:1px solid var(--hairline)">
          <div class="field" id="ad-expiry-field">
            <label for="ad-new-expiry">
              New expiry date <span class="req" id="ad-expiry-req">*</span>
            </label>
            <input class="input" type="datetime-local" name="new_expires_at" id="ad-new-expiry">
            <div class="card-sub" style="margin-top:5px" id="ad-expiry-note"></div>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Close</button>
        <a class="btn btn-outline btn-sm" id="ad-open-live" href="#" style="display:none">Open announcement</a>
        <button type="submit" class="btn btn-primary btn-sm" id="ad-restore">
          <?= icon('refresh', 14) ?> Restore Announcement
        </button>
      </div>
    </form>
  </dialog>

  <script>
  var ARCHIVE_DOWNLOAD = <?= json_encode(url('download.php?file=')) ?>;
  var ARCHIVE_VIEW     = <?= json_encode(url('announcements.php?view=')) ?>;

  function archiveDetails(d) {
    document.getElementById('ad-archive-id').value = d.archive_id;
    document.getElementById('ad-title').textContent = d.title;
    document.getElementById('ad-orig').textContent  = '#' + d.original_id;

    document.getElementById('ad-category').textContent   = d.category;
    document.getElementById('ad-priority').textContent   = d.priority;
    document.getElementById('ad-audience').textContent   = d.audience;
    document.getElementById('ad-department').textContent = d.department;
    document.getElementById('ad-status').textContent     = d.status;
    document.getElementById('ad-created').textContent    = d.created;
    document.getElementById('ad-published').textContent  = d.published;
    document.getElementById('ad-expires').textContent    = d.expires;
    document.getElementById('ad-archived').textContent   = d.archived;
    document.getElementById('ad-author').textContent     = d.author;
    document.getElementById('ad-views').textContent      = d.views;
    document.getElementById('ad-require').textContent    = d.require_read;
    document.getElementById('ad-body').textContent       = d.body;

    var badge = document.getElementById('ad-state');
    badge.textContent = d.restored ? 'Restored' : 'Archived';
    badge.className   = 'badge badge-' + (d.restored ? 'success' : 'warning');

    /* Attachments still live with the original announcement, so they are only
       offered while that row exists. */
    var filesWrap = document.getElementById('ad-files-wrap');
    var filesBox  = document.getElementById('ad-files');
    filesBox.textContent = '';

    if (d.files && d.files.length) {
      filesWrap.style.display = 'block';
      d.files.forEach(function (f) {
        var a = document.createElement('a');
        a.href = ARCHIVE_DOWNLOAD + f.id;
        a.textContent = f.name + ' (' + f.size + ')';
        filesBox.appendChild(a);
      });
    } else {
      filesWrap.style.display = 'none';
    }

    document.getElementById('ad-gone').style.display = d.live ? 'none' : 'block';

    var openLive = document.getElementById('ad-open-live');
    if (d.live) {
      openLive.style.display = 'inline-flex';
      openLive.href = ARCHIVE_VIEW + d.original_id;
    } else {
      openLive.style.display = 'none';
    }

    /* A restored record is read-only: the expiry input and the Restore button
       are both taken out of the form, not merely hidden. The model refuses an
       already-restored archive_id in any case — this only keeps the page
       honest about what it offers. */
    var restoredBox = document.getElementById('ad-restored');
    var actions     = document.getElementById('ad-actions');
    var restoreBtn  = document.getElementById('ad-restore');
    var expiry      = document.getElementById('ad-new-expiry');

    restoredBox.style.display = d.restored ? 'block' : 'none';
    actions.style.display     = d.restored ? 'none'  : 'block';
    restoreBtn.style.display  = d.restored ? 'none'  : 'inline-flex';
    expiry.disabled           = d.restored;

    if (d.restored) {
      document.getElementById('ad-restored-at').textContent = d.restored_at;
      document.getElementById('ad-restored-by').textContent = d.restored_by || 'Admin';
      document.getElementById('ad-restored-id').textContent = d.restored_id ? '#' + d.restored_id : '—';
    } else {
      expiry.value = '';

      /* Never restore an expired notice with a date already in the past: the
         next expiry sweep would send it straight back. The date is asked for,
         never invented. */
      var mustChoose = !!d.needs_expiry;
      expiry.required = mustChoose;

      var now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
      expiry.min = now.toISOString().slice(0, 16);

      document.getElementById('ad-expiry-req').style.display = mustChoose ? 'inline' : 'none';
      document.getElementById('ad-expiry-note').textContent = mustChoose
        ? 'The original expiry date (' + d.expires + ') has passed. Choose a new expiry date before restoring.'
        : (d.expires === '—'
            ? 'This announcement had no expiry date and will be restored without one. Set a date here to give it one.'
            : 'The original expiry date (' + d.expires + ') is still in the future and will be kept. Set a date here to change it.');
    }

    document.getElementById('archiveDlg').showModal();
  }

  /* The server checks this again — this only saves a round trip. */
  document.getElementById('restoreForm').addEventListener('submit', function (ev) {
    var expiry = document.getElementById('ad-new-expiry');

    if (expiry.required && expiry.value.trim() === '') {
      ev.preventDefault();
      expiry.focus();
      alert('The original expiry date has passed. Choose a new expiry date before restoring.');
      return;
    }

    if (expiry.value.trim() !== '' && new Date(expiry.value) <= new Date()) {
      ev.preventDefault();
      expiry.focus();
      alert('The new expiry date must be in the future.');
    }
  });
  </script>

<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
