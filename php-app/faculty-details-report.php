<?php
/**
 * Faculty Details — one A4 document holding everything ATTS stores about a
 * single faculty member, opened from the Faculty Achievements page.
 *
 * Nothing here is a new data source. It reuses, unchanged:
 *   - can_user_view_faculty_report()  — the same IDOR gate the individual
 *     report uses, so a HoD/Coordinator can only open their own department
 *     and a Faculty member only their own record.
 *   - faculty_profile_details()       — the existing `users` row.
 *   - faculty_achievement_details()   — the existing FEAT-04 achievement query.
 *   - active_academic_year()          — FEAT-02's one global year.
 *   - em_filter_window() / em_meeting_for_datetime() — FEAT-07's EM1/EM2.
 *   - inc/report_layout.php           — the same A4 letterhead, grid and
 *     sign-off every other ATTS report download is built from, and the same
 *     "print the page, save as PDF" route (ATTS bundles no PDF library).
 *
 * What ATTS does NOT store, and is therefore reported as such rather than
 * invented: faculty photo, faculty signature, education records, experience
 * records, and personal fields (date of birth, gender, nationality, address).
 * The only document evidence ATTS holds is the proof file attached to each
 * achievement record, which the Document Status section reports.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';          // A4 letterhead + grid + sign-off
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';    // FEAT-07 EM1/EM2

$user = require_login();

// Subject of the document. Defaults to the signed-in user, exactly as
// individual-faculty-report.php does.
$facultyId = (int) input('id', 0) ?: (int) $user['id'];

// ---- IDOR gate: the existing rule, before a single row is read ------------
// Self, or Admin/Principal/Director/Dean, or a HoD/Coordinator of the same
// department. Anything else lands on the standard denied page.
if (!can_user_view_faculty_report($user, $facultyId)) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

$format = strtolower(trim((string) input('format', 'pdf')));
if (!in_array($format, ['pdf', 'word'], true)) {
    $format = 'pdf';
}

// FEAT-02: a year from the URL is honoured only if it is a real academic year;
// otherwise the global active year applies. No second year system.
$askedYear    = trim((string) input('academic_year', ''));
$academicYear = is_valid_academic_year($askedYear) ? $askedYear : active_academic_year();

// FEAT-07: the same All / EM1 / EM2 filter the achievements page passes on.
$em       = em_filter_value(input('em'));
$emWindow = em_filter_window($em, $academicYear);
$emSchedule = em_schedule_for_year($academicYear);

$profile = faculty_profile_details($facultyId);

// The document describes a member of teaching staff; those are the same roles
// the Faculty Achievements grid lists.
if (!$profile || !in_array($profile['role'], ['Faculty', 'Coordinator', 'HoD'], true)) {
    http_response_code(404);
    $pageTitle  = 'Faculty Details';
    $breadcrumb = 'Faculty Details';
    require __DIR__ . '/inc/header.php';
    echo '<div class="alert alert-error mt-4">No faculty record was found for this id. '
       . 'Faculty Details can only be produced for a Faculty member, Coordinator or HoD.</div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

// The faculty member's own passport photograph, uploaded on their Profile
// page. Embedded as a data: URI so that printing never depends on a second
// HTTP request, and so a missing or unreadable file simply falls back to the
// "Photo Not Available" box instead of a broken image.
$photoData = user_photo_data_uri(user_photo_filename($facultyId));

$data    = faculty_achievement_details($facultyId, $academicYear, null, $emWindow);
$records = $data['records'];
$summary = $data['summary'];

// ---- Document Status: the proof file attached to each achievement ---------
$proofUploaded = 0;
$proofMissing  = 0;   // a filename is recorded but the file is not on disk
$proofNone     = 0;
$statusCounts  = [];

foreach ($records as $r) {
    $file = trim((string) ($r['proof_file'] ?? ''));
    if ($file === '') {
        $proofNone++;
    } else {
        $meta = record_proof_meta((string) ($r['type_key'] ?? ''), (int) ($r['id'] ?? 0), $file);
        if ($meta && $meta['exists']) {
            $proofUploaded++;
        } else {
            $proofMissing++;
        }
    }
    $s = (string) ($r['status'] ?? '');
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}

$totalRecords = count($records);
$deptFull     = department_full_name($profile['department']) ?: 'Not assigned';
$today        = date('d.m.Y');
$fileStem     = 'faculty-details-' . strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $profile['name']), '-'))
                . '-' . date('Y-m-d');
$dash         = '—';
$val          = fn(?string $v): string => ($v !== null && trim($v) !== '') ? $v : 'Not available';

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

$meta = [
    ['Faculty Name', $profile['name']],
    ['Faculty ID', $profile['employee_id']],
    ['Designation', $profile['designation']],
    ['Department', $deptFull],
    ['Academic Year', $academicYear],
    ['Report Date', $today],
];
if ($em !== 'all') {
    $meta[] = ['Executive Meeting', em_filter_label($em, $academicYear)];
}

report_document_head('Faculty Details · ' . $profile['name']);
?>
<style>
  /* This document's own blocks. The table, letterhead and sign-off styles all
     come from inc/report_layout.php, so it matches every other ATTS report. */
  body { padding-bottom: 14mm; }          /* room for the repeating footer */

  .sec-h { font-size: 11pt; font-weight: bold; text-transform: uppercase; letter-spacing: .4px;
           background: #D9D9D9; border: 1px solid #000; padding: 4px 6px; margin: 14px 0 0; }
  .sec { break-inside: auto; }
  table.grid td.k { width: 26%; font-weight: bold; background: #F2F2F2; }
  table.grid tr { break-inside: avoid; }
  .note { font-size: 8.5pt; color: #333; margin-top: 4px; font-style: italic; }

  /* Passport-size photo area, kept whether or not a photo exists. */
  .photo-cell { width: 34mm; text-align: center; vertical-align: middle; }
  .photo-box { width: 30mm; height: 38mm; border: 1px dashed #777; margin: 0 auto;
               box-sizing: border-box; padding-top: 15mm; text-align: center;
               font-size: 8.5pt; color: #555; }
  .photo-box.has-photo { padding: 0; border-style: solid; border-color: #000; }
  .photo-box img { width: 100%; height: 100%; object-fit: cover; display: block; }

  /* Signature block */
  table.sig { width: 100%; margin-top: 16px; border-collapse: collapse; }
  table.sig td { vertical-align: bottom; padding: 0; }
  .sig-area { width: 60mm; height: 20mm; border: 1px dashed #777; text-align: center;
              box-sizing: border-box; padding-top: 8mm; font-size: 8.5pt; color: #555; }
  .sig-k { font-size: 9.5pt; font-weight: bold; margin-bottom: 4px; }
  .sig-n { font-size: 9pt; margin-top: 4px; }
  .decl { border: 1px solid #000; padding: 8px 10px; font-size: 9.5pt; line-height: 1.5; }

  /* Repeats at the foot of every printed page in the browser's print output. */
  .doc-foot { position: fixed; bottom: 0; left: 0; right: 0; border-top: 1px solid #999;
              padding-top: 3px; font-size: 8pt; color: #333; }
  .doc-foot td { border: 0; padding: 0; }
  .doc-foot .r { text-align: right; }

  @media screen { .doc-foot { position: static; margin-top: 18px; } }
  @media print  { .no-print { display: none !important; } }
</style>

<?php if ($format === 'pdf'): ?>
  <div class="no-print" style="position:sticky;top:0;background:#1A2547;color:#fff;padding:10px 16px;
       display:flex;align-items:center;justify-content:space-between;font-family:Arial,sans-serif;margin:-1.4cm -1.2cm 16px">
    <span style="font-size:13px">A4 document &mdash; use your browser's print dialog and choose <strong>Save as PDF</strong>.</span>
    <button onclick="window.print()" style="background:#FF4F01;color:#fff;border:0;border-radius:6px;
       padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer">Print / Save as PDF</button>
  </div>
<?php endif; ?>

<?php report_letterhead('Faculty Details', $meta); ?>

<!-- ============================ 1. PERSONAL DETAILS ====================== -->
<div class="sec">
  <div class="sec-h">1. Personal Details</div>
  <table class="grid">
    <tr>
      <td class="k">Faculty Name</td>
      <td><?= e($profile['name']) ?></td>
      <td class="photo-cell" rowspan="8">
        <?php if ($photoData !== null): ?>
          <div class="photo-box has-photo"><img src="<?= $photoData ?>" alt="Photograph of <?= e($profile['name']) ?>"></div>
        <?php else: ?>
          <div class="photo-box">Photo<br>Not Available</div>
        <?php endif; ?>
      </td>
    </tr>
    <tr><td class="k">Faculty ID</td><td><?= e($profile['employee_id']) ?></td></tr>
    <tr><td class="k">Designation</td><td><?= e($profile['designation']) ?></td></tr>
    <tr><td class="k">Department</td><td><?= e($deptFull) ?></td></tr>
    <tr><td class="k">Role in ATTS</td><td><?= e($profile['role']) ?></td></tr>
    <tr><td class="k">Email</td><td><?= e($val($profile['email'])) ?></td></tr>
    <tr><td class="k">Mobile</td><td><?= e($val($profile['phone'])) ?></td></tr>
    <tr>
      <td class="k">Account Status</td>
      <td><?= e($profile['status']) ?>
        <?php $created = strtotime((string) $profile['created_at']); ?>
        <?= $created ? ' &middot; ATTS account created on ' . e(date('d.m.Y', $created)) : '' ?>
      </td>
    </tr>
  </table>
  <div class="note">
    Faculty ID and Designation are derived from the ATTS user account (there is no stored employee number or
    designation field). The photograph is the one uploaded by this faculty member on their own Profile page.
    ATTS does not store date of birth, gender, nationality or address, so those fields are not shown.
  </div>
</div>

<!-- ============================ 2. EDUCATION DETAILS ===================== -->
<div class="sec">
  <div class="sec-h">2. Education Details</div>
  <table class="grid">
    <thead>
      <tr>
        <th>Qualification</th><th>Course / Specialization</th><th>Institution</th>
        <th>University</th><th>From</th><th>To</th>
      </tr>
    </thead>
    <tbody>
      <tr><td colspan="6" class="c">ATTS does not store education records for faculty.</td></tr>
    </tbody>
  </table>
</div>

<!-- ============================ 3. EXPERIENCE ============================ -->
<div class="sec">
  <div class="sec-h">3. Experience</div>
  <table class="grid">
    <thead>
      <tr>
        <th>Experience Type</th><th>Institution</th><th>Designation</th>
        <th>From</th><th>To</th><th>Responsibilities</th>
      </tr>
    </thead>
    <tbody>
      <tr><td colspan="6" class="c">ATTS does not store experience records for faculty.</td></tr>
    </tbody>
  </table>
</div>

<!-- ============================ 4. ACHIEVEMENTS ========================== -->
<div class="sec">
  <div class="sec-h">4. Faculty Achievements &mdash; <?= e($academicYear) ?><?= $em !== 'all' ? ' &middot; ' . e(em_filter_label($em, $academicYear)) : '' ?></div>

  <table class="grid">
    <thead>
      <tr><th style="width:74%">Achievement Category</th><th>Records</th></tr>
    </thead>
    <tbody>
      <?php if (empty($summary)): ?>
        <tr><td colspan="2" class="c">No achievement records for this academic year.</td></tr>
      <?php else: ?>
        <?php foreach ($summary as $catLabel => $count): ?>
          <tr><td><?= e($catLabel) ?></td><td class="num"><?= (int) $count ?></td></tr>
        <?php endforeach; ?>
        <tr><td class="target-name">Total Achievements</td><td class="num target-name"><?= (int) $totalRecords ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($totalRecords): ?>
    <table class="grid">
      <thead>
        <tr>
          <th style="width:5%">S.No</th>
          <th style="width:36%">Achievement / Title</th>
          <th style="width:17%">Category</th>
          <th style="width:11%">Status</th>
          <th style="width:9%">Year</th>
          <?php if ($emSchedule): ?><th style="width:7%">EM</th><?php endif; ?>
          <th style="width:10%">Submitted On</th>
          <th style="width:8%">Proof</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $i => $item): ?>
          <?php
            $file  = trim((string) ($item['proof_file'] ?? ''));
            $pMeta = $file !== '' ? record_proof_meta((string) ($item['type_key'] ?? ''), (int) ($item['id'] ?? 0), $file) : null;
            $ts    = strtotime((string) $item['created_at']);
            $emOf  = $emSchedule ? em_meeting_for_datetime((string) $item['created_at'], $academicYear) : null;
            $extra = '';
            foreach (['journal_name' => 'Journal', 'publisher_name' => 'Publisher',
                      'conference_name' => 'Conference', 'organized_by' => 'Organized by'] as $col => $label) {
                if (!empty($item['raw'][$col])) { $extra = $label . ': ' . $item['raw'][$col]; break; }
            }
          ?>
          <tr>
            <td class="num"><?= $i + 1 ?></td>
            <td>
              <?= e($item['title']) ?>
              <?php if ($extra !== ''): ?><div class="muted"><?= e($extra) ?></div><?php endif; ?>
            </td>
            <td><?= e($item['category']) ?></td>
            <td class="c"><?= e($item['status']) ?></td>
            <td class="c"><?= e($item['year']) ?></td>
            <?php if ($emSchedule): ?>
              <td class="c"><?= $emOf ? e(EM_MEETINGS[$emOf]) : $dash ?></td>
            <?php endif; ?>
            <td class="c"><?= $ts ? date('d.m.Y', $ts) : $dash ?></td>
            <td class="c"><?= $pMeta ? ($pMeta['exists'] ? 'Uploaded' : 'File missing') : 'Not uploaded' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($emSchedule): ?>
      <div class="note">EM shows the Executive Meeting window a record was submitted in, from the FEAT-07 schedule for <?= e($academicYear) ?>.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ============================ 5. DOCUMENT STATUS ======================= -->
<div class="sec">
  <div class="sec-h">5. Document Status</div>
  <table class="grid">
    <thead>
      <tr><th style="width:34%">Document</th><th style="width:22%">Status</th><th>Remarks</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Faculty Photo</td><td class="c">Not stored</td>
        <td>ATTS holds no faculty photograph.</td>
      </tr>
      <tr>
        <td>Faculty Signature</td><td class="c">Not stored</td>
        <td>ATTS holds no specimen signature.</td>
      </tr>
      <tr>
        <td>Appointment / Degree / Experience Certificates</td><td class="c">Not stored</td>
        <td>ATTS stores proof files against achievement records only, not personnel documents.</td>
      </tr>
      <tr>
        <td>Achievement Proof Attachments</td>
        <td class="c"><?= (int) $proofUploaded ?> of <?= (int) $totalRecords ?> uploaded</td>
        <td>
          <?php if ($proofMissing): ?>
            <?= (int) $proofMissing ?> record(s) name a proof file that is no longer on the server.
          <?php endif; ?>
          <?php if ($proofNone): ?>
            <?= (int) $proofNone ?> record(s) carry no proof file.
          <?php endif; ?>
          <?php if (!$proofMissing && !$proofNone): ?>
            Every listed achievement has its proof file on the server.
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Review Status of Achievements</td>
        <td class="c"><?= $totalRecords ? e((string) (int) ($statusCounts['Approved'] ?? 0)) . ' approved' : $dash ?></td>
        <td>
          <?php if ($totalRecords): ?>
            <?php $bits = []; foreach ($statusCounts as $s => $n) { $bits[] = e($n . ' ' . $s); } ?>
            <?= implode(' &middot; ', $bits) ?>. Status is the portal's own review outcome for each record.
          <?php else: ?>
            No achievement records in this academic year.
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<!-- ============================ 6. DECLARATION =========================== -->
<div class="sec">
  <div class="sec-h">6. Declaration</div>
  <div class="decl">
    This document has been generated from the records held in the ATTS IQAC portal for the faculty member named
    above, for the academic year stated. The achievements listed are the records submitted through the portal and
    carry the review status shown against each entry; no other verification is implied by this document.
    Sections marked &ldquo;not stored&rdquo; are information the portal does not hold.
    <br><br>
    I declare that the particulars given above relate to me and that the records submitted by me through the ATTS
    portal are true and correct to the best of my knowledge.
  </div>
</div>

<!-- ============================ 7. SIGNATURES ============================ -->
<div class="sec">
  <div class="sec-h">7. Faculty Signature</div>
  <table class="sig">
    <tr>
      <td>
        <div class="sig-k">Signature of the Faculty</div>
        <div class="sig-area">Signature Not Available</div>
        <div class="sig-n"><?= e($profile['name']) ?> &middot; <?= e($profile['employee_id']) ?></div>
      </td>
      <td class="r" style="text-align:right; vertical-align:bottom;">
        <div class="sig-k">Date</div>
        <div style="font-size:9.5pt;">____________________</div>
      </td>
    </tr>
  </table>
</div>

<?php report_signoff(report_signoff_columns(null, 'HEAD OF THE DEPARTMENT')); ?>

<table class="doc-foot">
  <tr>
    <td><?= e($profile['name']) ?> &middot; <?= e($profile['employee_id']) ?> &middot; <?= e($deptFull) ?></td>
    <td class="r"><?= e(REPORT_INSTITUTION) ?> &middot; Faculty Details &middot; <?= e($today) ?></td>
  </tr>
</table>

<?php report_document_foot(); ?>
