<?php
/**
 * Institutional & Department Achievement Upload Forms (39 Structured Categories)
 * Included from upload.php when $selectedType starts with 'inst_'
 */
?>

<?php if ($selectedType === 'inst_pass_percentage'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field">
    <label>Academic Year <span class="card-sub">— Locked by Admin</span></label>
    <input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>.">
  </div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field">
    <label>Programme / Course <span class="req">*</span></label>
    <input class="input" name="programme" id="parentProgramme" placeholder="e.g. B.E. Computer Science and Engineering" required>
  </div>
  <div class="field">
    <label>Class / Year <span class="req">*</span></label>
    <select class="select" name="class_year" id="parentClassYear" required>
      <option value="">Select Year</option>
      <option value="I Year">I Year</option>
      <option value="II Year">II Year</option>
      <option value="III Year">III Year</option>
      <option value="IV Year">IV Year</option>
    </select>
  </div>
  <div class="field">
    <label>Semester <span class="req">*</span></label>
    <select class="select" name="semester" id="parentSemester" required>
      <option value="">Select Semester</option>
      <option value="Semester 1">Semester 1</option>
      <option value="Semester 2">Semester 2</option>
      <option value="Semester 3">Semester 3</option>
      <option value="Semester 4">Semester 4</option>
      <option value="Semester 5">Semester 5</option>
      <option value="Semester 6">Semester 6</option>
      <option value="Semester 7">Semester 7</option>
      <option value="Semester 8">Semester 8</option>
    </select>
  </div>

  <!-- Multi-row University Pass Percentage Table -->
  <div class="field" style="grid-column:span 2; margin-top:10px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
      <div>
        <label style="font-weight:700; font-size:14px; margin-bottom:2px;">Subject-wise &amp; Class-wise Pass Details <span class="req">*</span></label>
        <div class="card-sub" style="font-size:12px;">Add rows for each subject or student. Total Members, Passed Members, and Pass % are calculated automatically.</div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="btnAddPassRow" style="display:inline-flex; align-items:center; gap:6px;">
        <?= icon('plus', 14) ?> Add Row
      </button>
    </div>

    <div class="table-wrap" style="overflow-x:auto; border:1px solid var(--border,#e2e8f0); border-radius:8px; background:#fff;">
      <table class="data" id="passPercentageTable" style="margin:0; width:100%; min-width:980px;">
        <thead>
          <tr style="background:var(--bg-subtle, #f8fafc);">
            <th style="width:45px; text-align:center;">S.No</th>
            <th style="width:115px;">Subject Code <span class="req">*</span></th>
            <th>Subject Name <span class="req">*</span></th>
            <th style="width:115px;">Register No</th>
            <th style="width:140px;">Student Name</th>
            <th style="width:95px;">Pass Status</th>
            <th style="width:90px;">Total <span class="req">*</span></th>
            <th style="width:90px;">Passed <span class="req">*</span></th>
            <th style="width:95px; text-align:right;">Pass %</th>
            <th style="width:50px; text-align:center;">Action</th>
          </tr>
        </thead>
        <tbody id="passRowsContainer">
          <!-- Dynamically populated -->
        </tbody>
      </table>
    </div>

    <!-- Live Calculated Summary Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-top:14px;">
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:12px 14px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--ink-muted,#64748b); font-weight:600;">Total Subjects</div>
        <div id="summaryTotalSubjects" style="font-size:20px; font-weight:700; color:var(--ink,#1e293b); margin-top:4px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:12px 14px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--ink-muted,#64748b); font-weight:600;">Total Students</div>
        <div id="summaryTotalStudents" style="font-size:20px; font-weight:700; color:var(--ink,#1e293b); margin-top:4px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:12px 14px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--ink-muted,#64748b); font-weight:600;">Total Passed</div>
        <div id="summaryTotalPassed" style="font-size:20px; font-weight:700; color:#16a34a; margin-top:4px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:12px 14px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--ink-muted,#64748b); font-weight:600;">Overall Pass %</div>
        <div id="summaryOverallPct" style="font-size:20px; font-weight:700; color:#2563eb; margin-top:4px;">0.00%</div>
      </div>
    </div>
  </div>

  <script>
  (function() {
    var container = document.getElementById('passRowsContainer');
    var btnAdd = document.getElementById('btnAddPassRow');
    if (!container || !btnAdd) return;

    var initialRows = <?= json_encode($passPercentageRows ?? []) ?>;
    var rowIndex = 0;

    function createRow(data) {
      data = data || {};
      var tr = document.createElement('tr');
      tr.className = 'js-pass-row';
      var idx = rowIndex++;

      var passStat = data.pass_status || 'Pass';

      tr.innerHTML =
        '<td style="text-align:center; vertical-align:middle;" class="js-row-sno">1</td>' +
        '<td><input class="input js-sub-code" name="rows[' + idx + '][subject_code]" value="' + escapeHtml(data.subject_code || '') + '" placeholder="e.g. CS3351" required style="padding:4px 8px; height:32px; font-size:12px;"></td>' +
        '<td><input class="input js-sub-name" name="rows[' + idx + '][subject_name]" value="' + escapeHtml(data.subject_name || '') + '" placeholder="Subject Name" required style="padding:4px 8px; height:32px; font-size:12px;"></td>' +
        '<td><input class="input" name="rows[' + idx + '][reg_no]" value="' + escapeHtml(data.reg_no || '') + '" placeholder="Reg No (opt)" style="padding:4px 8px; height:32px; font-size:12px;"></td>' +
        '<td><input class="input" name="rows[' + idx + '][student_name]" value="' + escapeHtml(data.student_name || '') + '" placeholder="Student Name (opt)" style="padding:4px 8px; height:32px; font-size:12px;"></td>' +
        '<td>' +
          '<select class="select" name="rows[' + idx + '][pass_status]" style="padding:2px 24px 2px 8px; height:32px; font-size:12px;">' +
            '<option value="Pass"' + (passStat === 'Pass' ? ' selected' : '') + '>Pass</option>' +
            '<option value="Fail"' + (passStat === 'Fail' ? ' selected' : '') + '>Fail</option>' +
          '</select>' +
        '</td>' +
        '<td><input class="input js-tot-members" type="number" min="1" name="rows[' + idx + '][total_members]" value="' + (data.total_members !== undefined ? data.total_members : '') + '" placeholder="Total" required style="padding:4px 8px; height:32px; font-size:12px; text-align:right;"></td>' +
        '<td><input class="input js-pas-members" type="number" min="0" name="rows[' + idx + '][passed_members]" value="' + (data.passed_members !== undefined ? data.passed_members : '') + '" placeholder="Passed" required style="padding:4px 8px; height:32px; font-size:12px; text-align:right;"></td>' +
        '<td style="text-align:right; font-weight:700; color:#2563eb; vertical-align:middle; padding-right:12px;"><span class="js-row-pct">' + (data.pass_percentage !== undefined ? parseFloat(data.pass_percentage).toFixed(2) + '%' : '0.00%') + '</span></td>' +
        '<td style="text-align:center; vertical-align:middle;">' +
          '<button type="button" class="btn btn-ghost btn-sm js-btn-del-row" style="color:var(--danger,#ef4444); padding:2px 6px; height:28px;" title="Remove row">' +
            '<?= icon('trash', 14) ?>' +
          '</button>' +
        '</td>';

      container.appendChild(tr);

      var inputTot = tr.querySelector('.js-tot-members');
      var inputPas = tr.querySelector('.js-pas-members');

      function updateRowPct() {
        var tot = parseInt(inputTot.value, 10) || 0;
        var pas = parseInt(inputPas.value, 10) || 0;
        if (pas > tot && tot > 0) {
          inputPas.setCustomValidity('Passed members cannot exceed total members');
        } else {
          inputPas.setCustomValidity('');
        }
        var pct = tot > 0 ? ((pas / tot) * 100).toFixed(2) : '0.00';
        tr.querySelector('.js-row-pct').textContent = pct + '%';
        recalcSummary();
      }

      inputTot.addEventListener('input', updateRowPct);
      inputPas.addEventListener('input', updateRowPct);

      tr.querySelector('.js-btn-del-row').addEventListener('click', function() {
        if (container.querySelectorAll('.js-pass-row').length > 1) {
          tr.remove();
          reindexSno();
          recalcSummary();
        } else {
          alert('At least one subject row is required.');
        }
      });

      reindexSno();
      recalcSummary();
    }

    function reindexSno() {
      var rows = container.querySelectorAll('.js-pass-row');
      rows.forEach(function(r, i) {
        var snoEl = r.querySelector('.js-row-sno');
        if (snoEl) snoEl.textContent = (i + 1);
      });
    }

    function recalcSummary() {
      var rows = container.querySelectorAll('.js-pass-row');
      var subjectCodes = {};
      var totalStudents = 0;
      var totalPassed = 0;

      rows.forEach(function(r) {
        var code = (r.querySelector('.js-sub-code').value || '').trim();
        if (code !== '') subjectCodes[code.toUpperCase()] = true;
        var tot = parseInt(r.querySelector('.js-tot-members').value, 10) || 0;
        var pas = parseInt(r.querySelector('.js-pas-members').value, 10) || 0;
        totalStudents += tot;
        totalPassed += pas;
      });

      var totalSubjects = Object.keys(subjectCodes).length;
      var overallPct = totalStudents > 0 ? ((totalPassed / totalStudents) * 100).toFixed(2) : '0.00';

      document.getElementById('summaryTotalSubjects').textContent = totalSubjects;
      document.getElementById('summaryTotalStudents').textContent = totalStudents;
      document.getElementById('summaryTotalPassed').textContent = totalPassed;
      document.getElementById('summaryOverallPct').textContent = overallPct + '%';
    }

    function escapeHtml(str) {
      return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    btnAdd.addEventListener('click', function() {
      createRow();
    });

    if (initialRows && initialRows.length > 0) {
      initialRows.forEach(function(r) { createRow(r); });
    } else {
      createRow();
    }
  })();
  </script>

<?php elseif ($selectedType === 'inst_college_rank'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Programme / Course</label><input class="input" name="programme" placeholder="e.g. B.E. Mechanical Engineering"></div>
  <div class="field"><label>Student Name</label><input class="input" name="student_name" placeholder="Student name (if applicable)"></div>
  <div class="field"><label>Register Number</label><input class="input" name="reg_no" placeholder="Register number"></div>
  <div class="field"><label>Rank in Anna University <span class="req">*</span></label><input class="input" name="rank_val" placeholder="e.g. 1, 2, 3..." required></div>
  <div class="field"><label>Rank Type</label><input class="input" name="rank_type" placeholder="e.g. College Level / State / Academic"></div>
  <div class="field"><label>University <span class="req">*</span></label><input class="input" name="university" value="Anna University" required></div>
  <div class="field"><label>Year / Semester</label><input class="input" name="year_semester" placeholder="e.g. IV Year / VIII Sem"></div>
  <div class="field"><label>Achievement Date</label><input class="input" name="achievement_date" type="date"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2" placeholder="Additional details or remarks"></textarea></div>

<?php elseif ($selectedType === 'inst_student_rank'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Programme / Course</label><input class="input" name="programme" placeholder="e.g. B.Tech Information Technology"></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>University Rank <span class="req">*</span></label><input class="input" name="university_rank" placeholder="e.g. 1st Rank, 3rd Rank, 9th Rank" required></div>
  <div class="field"><label>Class / Year</label><input class="input" name="class_year" placeholder="e.g. IV Year"></div>
  <div class="field"><label>Semester</label><input class="input" name="semester" placeholder="e.g. Semester 8"></div>
  <div class="field"><label>Category</label><input class="input" name="category" placeholder="e.g. UG, PG, Gold Medalist"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_student_cgpa'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Programme / Course</label><input class="input" name="programme" placeholder="e.g. B.E. ECE"></div>
  <div class="field"><label>Class / Year <span class="req">*</span></label>
    <select class="select" name="class_year" required>
      <option value="">Select Year</option>
      <option value="II Year">II Year</option>
      <option value="III Year">III Year</option>
      <option value="IV Year">IV Year</option>
    </select>
  </div>
  <div class="field"><label>Semester <span class="req">*</span></label><input class="input" name="semester" placeholder="e.g. Semester 3 / Semester 5" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>CGPA <span class="req">*</span> <span class="card-sub">(Must be &gt; 7.5)</span></label>
    <input class="input" name="cgpa" id="inputCgpa" type="number" step="0.01" min="7.51" max="10.00" placeholder="e.g. 8.45" required>
  </div>
  <div class="field"><label>Eligibility Status</label>
    <input class="input" name="eligibility" id="inputEligibility" value="Eligible (Above 7.5)" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed; font-weight:600; color:#16a34a;">
  </div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_placement_mnc'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Batch <span class="req">*</span></label><input class="input" name="batch" value="<?= e($effectiveYear) ?>" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Company Name <span class="req">*</span></label><input class="input" name="company_name" placeholder="e.g. Google, Microsoft, TCS, Infosys, Zoho" required></div>
  <div class="field"><label>Company Type</label><select class="select" name="company_type"><option value="Top MNC">Top MNC</option><option value="Product Based">Product Based</option><option value="Core Engineering">Core Engineering</option><option value="IT Services">IT Services</option><option value="Startup">Startup</option></select></div>
  <div class="field"><label>Offer / Placement Status <span class="req">*</span></label><select class="select" name="placement_status" required><option value="Placed">Placed</option><option value="Offer Accepted">Offer Accepted</option><option value="Multiple Offers">Multiple Offers</option><option value="Internship + PPO">Internship + PPO</option></select></div>
  <div class="field"><label>Package / CTC (in LPA)</label><input class="input" name="package_ctc" placeholder="e.g. 12.5 LPA"></div>
  <div class="field"><label>Placement Date</label><input class="input" name="placement_date" type="date"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_publications'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Author(s) <span class="req">*</span></label><input class="input" name="authors" placeholder="Comma-separated authors" required></div>
  <div class="field" style="grid-column:span 2"><label>Title of the Paper <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Journal / Publication Name <span class="req">*</span></label><input class="input" name="journal_name" required></div>
  <div class="field"><label>Publication Type <span class="req">*</span></label>
    <select class="select js-other" name="publication_type" data-other="publication_type_other" required>
      <option value="Scopus">Scopus</option>
      <option value="SCI">SCI</option>
      <option value="Springer">Springer</option>
      <option value="UGC CARE">UGC CARE</option>
      <option value="H-Index">H-Index</option>
      <option value="Others">Others</option>
    </select>
    <input class="input js-other-text" name="publication_type_other" placeholder="Specify other publication type" style="margin-top:8px; display:none;">
  </div>
  <div class="field"><label>DOI / Article URL</label><input class="input" name="doi_url" placeholder="https://doi.org/..." type="url"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>H-Index Value</label><input class="input" name="h_index" placeholder="e.g. 15"></div>
  <div class="field"><label>Volume &amp; Issue No</label><input class="input" name="volume_issue" placeholder="e.g. Vol. 12, Issue 3"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_books'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Book Title <span class="req">*</span></label><input class="input" name="book_title" required></div>
  <div class="field" style="grid-column:span 2"><label>Author(s) <span class="req">*</span></label><input class="input" name="authors" required></div>
  <div class="field"><label>Publisher Name <span class="req">*</span></label><input class="input" name="publisher" required></div>
  <div class="field"><label>ISBN Number <span class="req">*</span></label><input class="input" name="isbn" required></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Edition</label><input class="input" name="edition" placeholder="e.g. 2nd Edition"></div>
  <div class="field" style="grid-column:span 2"><label>Book / Publisher URL</label><input class="input" name="book_url" type="url" placeholder="https://..."></div>

<?php elseif ($selectedType === 'inst_book_chapters'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Chapter Title <span class="req">*</span></label><input class="input" name="chapter_title" required></div>
  <div class="field" style="grid-column:span 2"><label>Book Title <span class="req">*</span></label><input class="input" name="book_title" required></div>
  <div class="field" style="grid-column:span 2"><label>Author(s) <span class="req">*</span></label><input class="input" name="authors" required></div>
  <div class="field"><label>Publisher Name <span class="req">*</span></label><input class="input" name="publisher" required></div>
  <div class="field"><label>ISBN Number <span class="req">*</span></label><input class="input" name="isbn" required></div>
  <div class="field"><label>Chapter Number</label><input class="input" name="chapter_number" placeholder="e.g. Chapter 4"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field" style="grid-column:span 2"><label>Chapter URL</label><input class="input" name="chapter_url" type="url" placeholder="https://..."></div>

<?php elseif ($selectedType === 'inst_patents_published'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Inventor(s) <span class="req">*</span></label><input class="input" name="inventors" placeholder="Comma-separated inventors" required></div>
  <div class="field" style="grid-column:span 2"><label>Title of Patent / Design <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Patent / Design Number <span class="req">*</span></label><input class="input" name="patent_number" required></div>
  <div class="field"><label>Type <span class="req">*</span></label><select class="select" name="patent_type" required><option value="Patent">Patent</option><option value="Design">Design</option></select></div>
  <div class="field"><label>Application Date</label><input class="input" name="application_date" type="date"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Status</label><input class="input" name="patent_status" placeholder="Published / Under Examination"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_patents_granted'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Inventor(s) <span class="req">*</span></label><input class="input" name="inventors" required></div>
  <div class="field" style="grid-column:span 2"><label>Title of Patent <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Patent Number <span class="req">*</span></label><input class="input" name="patent_number" required></div>
  <div class="field"><label>Grant Date <span class="req">*</span></label><input class="input" name="grant_date" type="date" required></div>
  <div class="field"><label>Granting Authority <span class="req">*</span></label><input class="input" name="granting_authority" placeholder="e.g. Indian Patent Office, USPTO" required></div>
  <div class="field"><label>Status</label><input class="input" name="patent_status" placeholder="Granted / In Force"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_copyrights'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Title / Work <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Copyright Number <span class="req">*</span></label><input class="input" name="copyright_number" placeholder="Registration / Diary No." required></div>
  <div class="field"><label>Registration Date <span class="req">*</span></label><input class="input" name="registration_date" type="date" required></div>
  <div class="field"><label>Category</label><input class="input" name="category" placeholder="Literary / Software / Artistic"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_sponsored_research'): ?>
  <div class="field"><label>Faculty Name / Principal Investigator (PI) <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Project Title <span class="req">*</span></label><input class="input" name="project_title" required></div>
  <div class="field"><label>Funding Agency <span class="req">*</span></label><input class="input" name="funding_agency" placeholder="e.g. DST, SERB, AICTE, DRDO, ICMR" required></div>
  <div class="field"><label>Project Amount (in Lakhs) <span class="req">*</span></label><input class="input" name="project_amount" type="number" step="0.01" min="0" placeholder="e.g. 15.50" required></div>
  <div class="field"><label>Sanction / Project Number</label><input class="input" name="project_number" placeholder="Sanction order number"></div>
  <div class="field"><label>Status</label><select class="select" name="project_status"><option value="Sanctioned">Sanctioned</option><option value="Ongoing">Ongoing</option><option value="Completed">Completed</option></select></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>End Date</label><input class="input" name="end_date" type="date"></div>

<?php elseif ($selectedType === 'inst_consultancy'): ?>
  <div class="field"><label>Faculty Name / Consultant <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Project Title <span class="req">*</span></label><input class="input" name="project_title" required></div>
  <div class="field"><label>Client / Organization <span class="req">*</span></label><input class="input" name="client_org" placeholder="Client industry name" required></div>
  <div class="field"><label>Project Amount (in Lakhs) <span class="req">*</span></label><input class="input" name="project_amount" type="number" step="0.01" min="0" placeholder="e.g. 5.25" required></div>
  <div class="field"><label>Project / PO Number</label><input class="input" name="project_number" placeholder="PO or Contract number"></div>
  <div class="field"><label>Status</label><select class="select" name="project_status"><option value="Sanctioned">Sanctioned</option><option value="Ongoing">Ongoing</option><option value="Completed">Completed</option></select></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>End Date</label><input class="input" name="end_date" type="date"></div>

<?php elseif ($selectedType === 'inst_research_centre'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Recognition Name / Recognized Department <span class="req">*</span></label><input class="input" name="recognition_name" placeholder="e.g. Research Centre Recognition for CSE" required></div>
  <div class="field"><label>Recognizing Authority <span class="req">*</span></label><input class="input" name="recognizing_authority" value="Anna University" required></div>
  <div class="field"><label>Recognition Date</label><input class="input" name="recognition_date" type="date"></div>
  <div class="field"><label>Reference / Approval Number</label><input class="input" name="reference_number"></div>
  <div class="field"><label>Status</label><input class="input" name="recognition_status" placeholder="Recognized / Active"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_ipr_programmes'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Programme Type <span class="req">*</span></label>
    <select class="select" name="programme_type" required>
      <option value="IPR">IPR</option>
      <option value="Higher Studies">Higher Studies</option>
      <option value="Entrepreneurship">Entrepreneurship</option>
    </select>
  </div>
  <div class="field" style="grid-column:span 2"><label>Programme Title <span class="req">*</span></label><input class="input" name="programme_title" required></div>
  <div class="field"><label>Date <span class="req">*</span></label><input class="input" name="programme_date" type="date" required></div>
  <div class="field"><label>Organizer <span class="req">*</span></label><input class="input" name="organizer" required></div>
  <div class="field"><label>Target Audience</label><input class="input" name="target_audience" placeholder="e.g. Students, Faculty, Research Scholars"></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="0"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_faculty_certifications'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Platform / Provider <span class="req">*</span></label>
    <select class="select js-other" name="provider" data-other="provider_other" required>
      <option value="NPTEL / Swayam">NPTEL / Swayam</option>
      <option value="Coursera">Coursera</option>
      <option value="edX">edX</option>
      <option value="Udemy">Udemy</option>
      <option value="AWS">AWS</option>
      <option value="Google Cloud">Google Cloud</option>
      <option value="Microsoft Learn">Microsoft Learn</option>
      <option value="Others">Others</option>
    </select>
    <input class="input js-other-text" name="provider_other" placeholder="Specify platform" style="margin-top:8px; display:none;">
  </div>
  <div class="field"><label>Certification Name</label><input class="input" name="certification_name" placeholder="Certificate title"></div>
  <div class="field"><label>Completion Date <span class="req">*</span></label><input class="input" name="completion_date" type="date" required></div>
  <div class="field"><label>Certificate ID / Roll No</label><input class="input" name="certificate_id"></div>
  <div class="field"><label>Status / Grade</label><input class="input" name="cert_status" placeholder="Completed / Elite / Gold"></div>

<?php elseif ($selectedType === 'inst_mou_interactions'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Industry / Organization Name <span class="req">*</span></label><input class="input" name="industry_name" required></div>
  <div class="field"><label>Type <span class="req">*</span></label>
    <select class="select" name="interaction_type" required>
      <option value="Industry Interaction">Industry Interaction</option>
      <option value="MOU">MOU</option>
      <option value="Industry Supported Lab">Industry Supported Lab</option>
    </select>
  </div>
  <div class="field"><label>MOU Number (if applicable)</label><input class="input" name="mou_number"></div>
  <div class="field"><label>Interaction / Signed Date <span class="req">*</span></label><input class="input" name="interaction_date" type="date" required></div>
  <div class="field"><label>Validity Period</label><input class="input" name="validity" placeholder="e.g. 3 Years / Ongoing"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_internships'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Company / Industry Name &amp; Address <span class="req">*</span></label><input class="input" name="company" required></div>
  <div class="field"><label>Start Date <span class="req">*</span></label><input class="input" name="start_date" id="internStartDate" type="date" required></div>
  <div class="field"><label>End Date <span class="req">*</span></label><input class="input" name="end_date" id="internEndDate" type="date" required></div>
  <div class="field"><label>Duration in Weeks <span class="req">*</span> <span class="card-sub">(Must be &ge; 4 weeks)</span></label>
    <input class="input" name="duration_weeks" id="internDurationWeeks" type="number" min="4" step="0.5" placeholder="e.g. 4, 6, 8" required>
  </div>
  <div class="field"><label>Internship Type</label><input class="input" name="internship_type" placeholder="Paid / Core / Software / Research"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>

<?php elseif ($selectedType === 'inst_summer_trainings'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Training Title <span class="req">*</span></label><input class="input" name="training_title" required></div>
  <div class="field" style="grid-column:span 2"><label>Organization / Training Body <span class="req">*</span></label><input class="input" name="organization" required></div>
  <div class="field"><label>Start Date <span class="req">*</span></label><input class="input" name="start_date" id="summerStartDate" type="date" required></div>
  <div class="field"><label>End Date <span class="req">*</span></label><input class="input" name="end_date" id="summerEndDate" type="date" required></div>
  <div class="field"><label>Duration (Days / Weeks) <span class="req">*</span> <span class="card-sub">(Must be &lt; 4 weeks / &lt; 28 days)</span></label>
    <input class="input" name="duration_days" id="summerDuration" placeholder="e.g. 14 days / 2 weeks" required>
  </div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>

<?php elseif ($selectedType === 'inst_student_projects'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Project Title <span class="req">*</span></label><input class="input" name="project_title" required></div>
  <div class="field"><label>Project Guide <span class="req">*</span></label><input class="input" name="project_guide" required></div>
  <div class="field"><label>Project Category</label><input class="input" name="project_category" placeholder="Hardware / Software / IoT / AI"></div>
  <div class="field" style="grid-column:span 2"><label>YouTube URL <span class="req">*</span> <span class="card-sub">(Valid video URL)</span></label>
    <input class="input" name="youtube_url" type="url" placeholder="https://www.youtube.com/watch?v=..." required>
  </div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Views Count</label><input class="input" name="views_count" placeholder="e.g. 1250"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_faculty_participations'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Programme Type <span class="req">*</span></label>
    <select class="select" name="programme_type" required>
      <option value="FDP">FDP</option>
      <option value="Training">Training</option>
      <option value="STTP">STTP</option>
      <option value="Conference">Conference</option>
    </select>
  </div>
  <div class="field" style="grid-column:span 2"><label>Programme Title <span class="req">*</span></label><input class="input" name="programme_title" required></div>
  <div class="field"><label>Organizer <span class="req">*</span></label><input class="input" name="organizer" required></div>
  <div class="field"><label>Location / Mode</label><select class="select" name="location_mode"><option value="Online">Online</option><option value="Offline">Offline</option><option value="Hybrid">Hybrid</option></select></div>
  <div class="field"><label>Start Date <span class="req">*</span></label><input class="input" name="start_date" type="date" required></div>
  <div class="field"><label>End Date <span class="req">*</span></label><input class="input" name="end_date" type="date" required></div>
  <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 5 Days / 1 Week"></div>
  <div class="field"><label>Certificate Number</label><input class="input" name="certificate_number"></div>

<?php elseif ($selectedType === 'inst_society_memberships'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Professional Society <span class="req">*</span></label><input class="input" name="society_name" placeholder="e.g. IEEE, ACM, CSI, ISTE, ASME" required></div>
  <div class="field"><label>Membership Number <span class="req">*</span></label><input class="input" name="membership_number" required></div>
  <div class="field"><label>Membership Type</label><input class="input" name="membership_type" placeholder="Life Member / Annual / Fellow"></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>Expiry Date</label><input class="input" name="expiry_date" type="date"></div>
  <div class="field"><label>Status</label><input class="input" name="membership_status" placeholder="Active / Permanent"></div>

<?php elseif ($selectedType === 'inst_newsletters'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Newsletter Title <span class="req">*</span></label><input class="input" name="newsletter_title" required></div>
  <div class="field"><label>Volume</label><input class="input" name="volume" placeholder="e.g. Volume 5"></div>
  <div class="field"><label>Issue</label><input class="input" name="issue" placeholder="e.g. Issue 2"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Editor / Coordinator <span class="req">*</span></label><input class="input" name="editor_coordinator" required></div>
  <div class="field" style="grid-column:span 2"><label>URL / Link</label><input class="input" name="newsletter_url" type="url" placeholder="https://..."></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_student_certifications'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Platform / Provider <span class="req">*</span></label>
    <select class="select js-other" name="platform" data-other="platform_other" required>
      <option value="Coursera">Coursera</option>
      <option value="NPTEL">NPTEL</option>
      <option value="Udemy">Udemy</option>
      <option value="edX">edX</option>
      <option value="HackerRank">HackerRank</option>
      <option value="Oracle Academy">Oracle Academy</option>
      <option value="AWS Educate">AWS Educate</option>
      <option value="Others">Others</option>
    </select>
    <input class="input js-other-text" name="platform_other" placeholder="Specify platform" style="margin-top:8px; display:none;">
  </div>
  <div class="field"><label>Certification Name</label><input class="input" name="certification_name" placeholder="Certificate title"></div>
  <div class="field"><label>Completion Date <span class="req">*</span></label><input class="input" name="completion_date" type="date" required></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>

<?php elseif ($selectedType === 'inst_nss_events'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
  <div class="field"><label>NSS Unit</label><input class="input" name="nss_unit" placeholder="e.g. Unit 1 / Unit 2"></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="0"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_inter_inst_within'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Host Institution <span class="req">*</span></label><input class="input" name="host_institution" required></div>
  <div class="field"><label>Location (Within State) <span class="req">*</span></label><input class="input" name="location" placeholder="e.g. Chennai, Coimbatore, Madurai" required></div>
  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Participation Type</label><input class="input" name="participation_type" placeholder="Symposium / Hackathon / Paper"></div>
  <div class="field"><label>Position / Award</label><input class="input" name="position_award" placeholder="1st Prize / Runner / Participation"></div>

<?php elseif ($selectedType === 'inst_inter_inst_outside'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Host Institution <span class="req">*</span></label><input class="input" name="host_institution" required></div>
  <div class="field"><label>State (Outside State) <span class="req">*</span></label><input class="input" name="state_name" placeholder="e.g. Karnataka, Kerala, Maharashtra" required></div>
  <div class="field"><label>City <span class="req">*</span></label><input class="input" name="city" placeholder="e.g. Bengaluru, Kochi, Mumbai" required></div>
  <div class="field"><label>Location / Campus</label><input class="input" name="location"></div>
  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Participation Type</label><input class="input" name="participation_type" placeholder="Symposium / Hackathon / Conference"></div>
  <div class="field"><label>Position / Award</label><input class="input" name="position_award" placeholder="Winner / 1st Prize / Participant"></div>

<?php elseif ($selectedType === 'inst_inter_inst_awards'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Host Institution <span class="req">*</span></label><input class="input" name="host_institution" required></div>
  <div class="field"><label>State</label><input class="input" name="state_name" placeholder="State"></div>
  <div class="field"><label>Location <span class="req">*</span></label><input class="input" name="location" required></div>
  <div class="field"><label>Award / Medal <span class="req">*</span></label><input class="input" name="award_medal" placeholder="e.g. Gold Medal / 1st Prize / Cash Award" required></div>
  <div class="field"><label>Position Secured</label><input class="input" name="position_secured" placeholder="1st / 2nd / Winner"></div>
  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>

<?php elseif ($selectedType === 'inst_value_added_courses'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Course Type</label><select class="select" name="course_type"><option value="Value Added Course">Value Added Course</option><option value="Hands-on Training">Hands-on Training</option><option value="Bootcamp">Bootcamp</option><option value="Technical Workshop">Technical Workshop</option></select></div>
  <div class="field"><label>Organizer <span class="req">*</span></label><input class="input" name="organizer" required></div>
  <div class="field"><label>Start Date <span class="req">*</span></label><input class="input" name="start_date" type="date" required></div>
  <div class="field"><label>End Date <span class="req">*</span></label><input class="input" name="end_date" type="date" required></div>
  <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 30 Hours / 5 Days"></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="0"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_sports_state'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Sport <span class="req">*</span></label><input class="input" name="sport" placeholder="e.g. Cricket, Athletics, Badminton" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Level <span class="req">*</span></label><input class="input" name="level_secured" value="State" readonly style="background:var(--bg-subtle, #f3f4f6); font-weight:600;"></div>
  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
  <div class="field"><label>Participation / Position</label><input class="input" name="participation_position" placeholder="e.g. Winner / Runner / Finalist"></div>
  <div class="field"><label>Award / Medal</label><input class="input" name="award" placeholder="e.g. Gold Medal, Trophy"></div>

<?php elseif ($selectedType === 'inst_sports_national'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Sport <span class="req">*</span></label><input class="input" name="sport" placeholder="e.g. Cricket, Athletics, Volleyball" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Level <span class="req">*</span></label><input class="input" name="level_secured" value="National" readonly style="background:var(--bg-subtle, #f3f4f6); font-weight:600;"></div>
  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
  <div class="field"><label>Participation / Position</label><input class="input" name="participation_position" placeholder="e.g. Winner / Runner / Finalist"></div>
  <div class="field"><label>Award / Medal</label><input class="input" name="award" placeholder="e.g. Gold Medal, National Trophy"></div>

<?php elseif ($selectedType === 'inst_innovation_events'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" placeholder="e.g. Department Hackathon / Ideathon" required></div>
  <div class="field"><label>Event Type <span class="req">*</span></label>
    <select class="select" name="event_type" required>
      <option value="Ideathon">Ideathon</option>
      <option value="Hackathon">Hackathon</option>
      <option value="Prototype Exhibition">Prototype Exhibition</option>
      <option value="Design Contest">Design Contest</option>
      <option value="Innovation Workshop">Innovation Workshop</option>
      <option value="Other">Other</option>
    </select>
  </div>
  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="0"></div>
  <div class="field" style="grid-column:span 2"><label>Innovation Theme</label><input class="input" name="innovation_theme" placeholder="e.g. AI for Sustainability, Clean Energy, Smart Healthcare"></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2" placeholder="Projects prototyped, winners, patents/ideas generated"></textarea></div>

<?php elseif ($selectedType === 'inst_iic_activities'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Activity Name <span class="req">*</span></label><input class="input" name="activity_name" required></div>
  <div class="field"><label>Activity Type <span class="req">*</span></label>
    <select class="select" name="activity_type" required>
      <option value="IIC Calendar Activity">IIC Calendar Activity</option>
      <option value="MIC Driven Activity">MIC Driven Activity</option>
      <option value="Self Driven Activity">Self Driven Activity</option>
      <option value="Celebration Activity">Celebration Activity</option>
    </select>
  </div>
  <div class="field"><label>Activity Date <span class="req">*</span></label><input class="input" name="activity_date" type="date" required></div>
  <div class="field"><label>Organizer <span class="req">*</span></label><input class="input" name="organizer" required></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Participants</label><input class="input" name="participants" type="number" min="0"></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_website_updations'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Update Title <span class="req">*</span></label><input class="input" name="update_title" placeholder="e.g. Faculty Profile Update / New Syllabus" required></div>
  <div class="field"><label>Page / Section Updated <span class="req">*</span></label><input class="input" name="page_section" placeholder="e.g. Dept Homepage, Labs, Research" required></div>
  <div class="field"><label>Updated By <span class="req">*</span></label><input class="input" name="updated_by" value="<?= e($user['name']) ?>" required></div>
  <div class="field"><label>Update Date <span class="req">*</span></label><input class="input" name="update_date" type="date" required></div>
  <div class="field" style="grid-column:span 2"><label>Website URL</label><input class="input" name="site_url" type="url" placeholder="https://..."></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2" placeholder="Summary of changes made to the website"></textarea></div>

<?php elseif ($selectedType === 'inst_google_ratings'): ?>
  <?php render_dept_field($user, $departments, 'Institution / Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Google Rating <span class="req">*</span> <span class="card-sub">(1.0 to 5.0)</span></label>
    <input class="input" name="google_rating" type="number" step="0.1" min="1.0" max="5.0" placeholder="e.g. 4.6" required>
  </div>
  <div class="field"><label>Review Count</label>
    <input class="input" name="review_count" type="number" min="0" placeholder="e.g. 350">
  </div>
  <div class="field"><label>Measurement Date <span class="req">*</span></label>
    <input class="input" name="measurement_date" type="date" required>
  </div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label>
    <textarea class="input" name="remarks" rows="2" placeholder="Verification notes or summary"></textarea>
  </div>

<?php elseif ($selectedType === 'inst_startups'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Startup Name <span class="req">*</span></label><input class="input" name="startup_name" required></div>
  <div class="field" style="grid-column:span 2"><label>Founder(s) <span class="req">*</span></label><input class="input" name="founders" placeholder="Names of student / faculty founders" required></div>
  <div class="field"><label>Founder Type <span class="req">*</span></label>
    <select class="select" name="founder_type" required>
      <option value="Student">Student</option>
      <option value="Faculty">Faculty</option>
      <option value="Alumni">Alumni</option>
      <option value="Joint">Joint (Faculty + Student)</option>
    </select>
  </div>
  <div class="field"><label>Register Number (if student)</label><input class="input" name="reg_no" placeholder="Reg No."></div>
  <div class="field"><label>Startup Type / Domain</label><input class="input" name="startup_type" placeholder="e.g. EdTech, HealthTech, AI"></div>
  <div class="field"><label>Registration / DPIIT Number</label><input class="input" name="registration_number" placeholder="CIN / DPIIT / Udyam No."></div>
  <div class="field"><label>Registration Date</label><input class="input" name="registration_date" type="date"></div>
  <div class="field"><label>Startup Status</label><select class="select" name="startup_status"><option value="Idea Phase">Idea Phase</option><option value="Incubated">Incubated</option><option value="Registered / Seed Funded">Registered / Seed Funded</option><option value="Revenue Generating">Revenue Generating</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_alumni_chapters'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Chapter Name <span class="req">*</span></label><input class="input" name="chapter_name" placeholder="e.g. Chennai Chapter, USA Chapter" required></div>
  <div class="field"><label>Location / City <span class="req">*</span></label><input class="input" name="location" required></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Formation Date <span class="req">*</span></label><input class="input" name="formation_date" type="date" required></div>
  <div class="field"><label>Member Count</label><input class="input" name="member_count" type="number" min="0" placeholder="e.g. 150"></div>
  <div class="field"><label>Status</label><select class="select" name="chapter_status"><option value="Active">Active</option><option value="Formation in Progress">Formation in Progress</option><option value="Inactive">Inactive</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Activities Conducted</label><textarea class="input" name="activities" rows="2" placeholder="Key alumni meets, mentorship sessions, or events"></textarea></div>

<?php elseif ($selectedType === 'inst_awards_recognitions'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Person Name <span class="req">*</span></label><input class="input" name="person_name" value="<?= e($user['name']) ?>" required></div>
  <div class="field"><label>Faculty Name (if applicable)</label><input class="input" name="faculty_name" placeholder="Faculty name"></div>
  <div class="field"><label>Recognition Type <span class="req">*</span></label>
    <select class="select" name="recognition_type" required>
      <option value="Department Award">Department Award</option>
      <option value="Faculty Award">Faculty Award</option>
      <option value="Recognition">Recognition</option>
      <option value="BoS Member">BoS Member</option>
      <option value="DC Member">DC Member</option>
      <option value="QP / Question Paper Setting">QP / Question Paper Setting</option>
      <option value="Key Setting">Key Setting</option>
      <option value="Other">Other</option>
    </select>
  </div>
  <div class="field"><label>Organization / University <span class="req">*</span></label><input class="input" name="organization" required></div>
  <div class="field" style="grid-column:span 2"><label>Title / Award / Recognition <span class="req">*</span></label><input class="input" name="title_award" required></div>
  <div class="field"><label>Achievement Date</label><input class="input" name="achievement_date" type="date"></div>
  <div class="field"><label>Reference Number</label><input class="input" name="reference_number" placeholder="Letter / Order No."></div>
  <div class="field" style="grid-column:span 2"><label>Role / Responsibility</label><textarea class="input" name="role_responsibility" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_spoken_tutorials'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <div class="field"><label>Academic Year <span class="card-sub">— Locked by Admin</span></label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Locked by Admin"></div>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" placeholder="e.g. Python, C++, Java, Linux" required></div>
  <div class="field"><label>Tutorial Name <span class="req">*</span></label><input class="input" name="tutorial_name" placeholder="e.g. Basic Python for Beginners" required></div>
  <div class="field"><label>Completion Date <span class="req">*</span></label><input class="input" name="completion_date" type="date" required></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>
  <div class="field"><label>Platform</label><input class="input" name="platform" value="IIT-Bombay Spoken Tutorial" readonly style="background:var(--bg-subtle, #f3f4f6); font-weight:600;"></div>
<?php endif; ?>
