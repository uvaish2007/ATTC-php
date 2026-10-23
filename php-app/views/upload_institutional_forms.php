<?php
/**
 * Institutional & Department Achievement Upload Forms (39 Structured Categories)
 * Included from upload.php when $selectedType starts with 'inst_'
 */
?>

<?php if ($selectedType === 'inst_pass_percentage'): ?>
  <?php
    $userDept = $user['department'] ?? '';
    $initialDept = !empty($editRecord['department']) ? $editRecord['department'] : ($userDept ?: 'CSE');
    $currSession = $editRecord['exam_session'] ?? '';
    if (empty($currSession) && empty($editRecord)) {
        $currSession = 'Nov/Dec';
    }
    $currSem = $editRecord['semester'] ?? '';
    if (empty($currSem) && empty($editRecord)) {
        $currSem = 'Sem 1';
    }
    $existingRowsJson = !empty($editRecord['rows']) ? json_encode($editRecord['rows']) : '[]';

    // Fetch active faculty across departments for live synchronization
    $allFacQuery = db()->query("SELECT id, name, department, role FROM users WHERE role IN ('Faculty', 'HoD', 'Coordinator') AND (status = 1 OR status = '1' OR status = 'Active') ORDER BY name ASC");
    $allFacultyData = $allFacQuery ? $allFacQuery->fetchAll(PDO::FETCH_ASSOC) : [];

    // Clean string list of all department names for dropdown generation
    $deptNamesList = [];
    foreach ($departments ?? [] as $d) {
        if (is_array($d)) {
            $nm = $d['name'] ?? $d['code'] ?? '';
        } else {
            $nm = (string)$d;
        }
        $nm = trim((string)$nm);
        if ($nm !== '') {
            $deptNamesList[] = $nm;
        }
    }
    if (!empty($initialDept) && !in_array($initialDept, $deptNamesList, true)) {
        $deptNamesList[] = $initialDept;
    }
    $deptNamesList = array_values(array_unique($deptNamesList));
    sort($deptNamesList, SORT_NATURAL | SORT_FLAG_CASE);
  ?>
  <style>
    .upercent-table-wrap {
      overflow-x: hidden !important;
      scrollbar-width: none !important;
      -ms-overflow-style: none !important;
      width: 100% !important;
      box-sizing: border-box !important;
    }
    .upercent-table-wrap::-webkit-scrollbar {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    .atts-metric-nav-container {
      scrollbar-width: none !important;
      -ms-overflow-style: none !important;
    }
    .atts-metric-nav-container::-webkit-scrollbar {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    #upercentTable {
      border-collapse: separate !important;
      border-spacing: 0 !important;
      width: 100% !important;
      max-width: 100% !important;
      table-layout: fixed !important;
      font-size: 12px !important;
      box-sizing: border-box !important;
    }
    #upercentTable thead th {
      padding: 9px 4px !important;
      font-size: 10.5px !important;
      font-weight: 700 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.02em !important;
      color: var(--ink-muted, #475569) !important;
      background: var(--bg-subtle, #f8fafc) !important;
      border-bottom: 2px solid var(--border, #e2e8f0) !important;
      border-right: 1px solid #f1f5f9 !important;
      vertical-align: middle !important;
      box-sizing: border-box !important;
      line-height: 1.25 !important;
      white-space: nowrap !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
    }
    #upercentTable thead th:last-child {
      border-right: none !important;
    }
    #upercentTable tbody td {
      padding: 6px 4px !important;
      vertical-align: middle !important;
      border-bottom: 1px solid #f1f5f9 !important;
      border-right: 1px solid #f8fafc !important;
      box-sizing: border-box !important;
    }
    #upercentTable tbody td:last-child {
      border-right: none !important;
    }
    #upercentTable tbody tr:hover td {
      background: #f8fafc !important;
    }
    #upercentTable .input, #upercentTable .select {
      height: 32px !important;
      font-size: 11.5px !important;
      border-radius: 5px !important;
      padding: 0 6px !important;
      box-sizing: border-box !important;
      width: 100% !important;
      display: block !important;
    }
    #upercentTable .select {
      padding-right: 16px !important;
      background-position: right 4px center !important;
    }
    .upercent-sum-grid {
      display: grid !important;
      grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
      gap: 12px !important;
      margin-top: 6px !important;
    }
    @media (max-width: 992px) {
      .upercent-sum-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 640px) {
      .upercent-sum-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 420px) {
      .upercent-sum-grid {
        grid-template-columns: 1fr !important;
      }
    }
    .upercent-sum-card {
      background: #ffffff !important;
      border: 1px solid var(--border, #e2e8f0) !important;
      border-radius: 8px !important;
      padding: 12px 14px !important;
      text-align: center !important;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03) !important;
      display: flex !important;
      flex-direction: column !important;
      justify-content: center !important;
      align-items: center !important;
      min-height: 74px !important;
      box-sizing: border-box !important;
    }
    .upercent-sum-card .upercent-sum-title {
      font-size: 11px !important;
      font-weight: 700 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.5px !important;
      margin-bottom: 4px !important;
      white-space: nowrap !important;
    }
    .upercent-sum-card .upercent-sum-num {
      font-size: 22px !important;
      font-weight: 800 !important;
      font-variant-numeric: tabular-nums !important;
      line-height: 1.1 !important;
    }
  </style>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field($editRecord['academic_year'] ?? $effectiveYear, 'academic_year', 'Academic Year', true, 'upercentAcademicYear'); ?>
  <div class="field">
    <label for="upercentExamSession">Exam Session <span class="req">*</span></label>
    <select class="select" id="upercentExamSession" name="exam_session" required onchange="upercentOnSessionChange(this)">
      <option value="" disabled <?= empty($currSession) ? 'selected' : '' ?>>Select Exam Session</option>
      <option value="Nov/Dec" <?= ($currSession === 'Nov/Dec' || $currSession === 'Nov-Dec') ? 'selected' : '' ?>>Nov/Dec (Odd Semesters)</option>
      <option value="Apr/May" <?= ($currSession === 'Apr/May' || $currSession === 'Apr-May') ? 'selected' : '' ?>>Apr/May (Even Semesters)</option>
    </select>
  </div>
  <div class="field">
    <label for="upercentSemester">Semester <span class="req">*</span></label>
    <select class="select" id="upercentSemester" name="semester" required onchange="upercentOnSemesterChange(this)">
      <option value="" disabled selected>Select Semester</option>
    </select>
  </div>

  <input type="hidden" name="pass_percentage_rows" id="instPpRowsJson" value="[]">
  <input type="hidden" name="edit_id" id="upercentEditId" value="<?= (int)($editRecord['id'] ?? 0) ?>">
  <datalist id="upercentSubjectDatalist"></datalist>

  <!-- Year-Wise University Results Table -->
  <div style="grid-column:span 2; margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
      <div>
        <strong style="font-size:14px; color:var(--navy,#131D3B); display:inline-flex; align-items:center; gap:6px;">
          <?= icon('award', 16) ?> Year-Wise University Results Table
        </strong>
        <div class="card-sub" id="upercentDeptBadge" style="font-size:12px; margin-top:2px;">
          Displaying year-wise examination statistics and faculty assignments.
        </div>
      </div>
      <div style="display:flex; gap:8px; align-items:center;">
        <button type="button" class="btn btn-outline btn-sm" onclick="upercentAddSubjectRow()" style="border-color:#FF4F01; color:#FF4F01; display:inline-flex; align-items:center; gap:5px;">
          <?= icon('plus', 13) ?> Add Subject Row
        </button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="upercentResetDefaultRows()" title="Reset to default year rows" style="display:inline-flex; align-items:center; gap:4px; font-size:12px;">
          <?= icon('refresh-cw', 12) ?> Reset Rows
        </button>
      </div>
    </div>

    <div class="table-wrap upercent-table-wrap" style="overflow-x:hidden; border:1px solid var(--border,#e2e8f0); border-radius:8px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
      <table class="data" id="upercentTable" style="margin:0; width:100%; max-width:100%; table-layout:fixed; font-size:12px;">
        <colgroup>
          <col style="width:3.5%;">
          <col style="width:9.5%;">
          <col style="width:11%;">
          <col style="width:18%;">
          <col style="width:12%;">
          <col style="width:15%;">
          <col style="width:7.5%;">
          <col style="width:7.5%;">
          <col style="width:5.5%;">
          <col style="width:7%;">
          <col style="width:3.5%;">
        </colgroup>
        <thead>
          <tr style="background:var(--bg-subtle, #f8fafc); border-bottom:2px solid var(--border,#e2e8f0);">
            <th style="text-align:center;">Order</th>
            <th>Year <span class="req">*</span></th>
            <th>Subject Code <span class="req">*</span></th>
            <th>Subject Name <span class="req">*</span></th>
            <th>Faculty Department <span class="req">*</span></th>
            <th>Faculty Name <span class="req">*</span></th>
            <th style="text-align:right;">Registered <span class="req">*</span></th>
            <th style="text-align:right;">Passed <span class="req">*</span></th>
            <th style="text-align:right;">Failed</th>
            <th style="text-align:right;">Pass %</th>
            <th style="text-align:center;"></th>
          </tr>
        </thead>
        <tbody id="upercentTableBody">
          <!-- Populated dynamically -->
        </tbody>
      </table>
    </div>

    <!-- 1. Result Summary: MOVED TO BOTTOM, BELOW SUBJECT RESULTS TABLE -->
    <div style="margin-top:16px;">
      <div style="font-size:13px; font-weight:700; color:var(--navy,#131D3B); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
        <?= icon('bar-chart-2', 15) ?> University Result Summary
      </div>
      <div class="upercent-sum-grid">
        <div class="upercent-sum-card">
          <div class="upercent-sum-title" style="color:var(--ink-muted,#64748b);">Total Subjects</div>
          <div id="upercentSumSubjects" class="upercent-sum-num" style="color:var(--ink,#1e293b);">0</div>
        </div>
        <div class="upercent-sum-card">
          <div class="upercent-sum-title" style="color:#2563eb;">Registered Students</div>
          <div id="upercentSumReg" class="upercent-sum-num" style="color:var(--ink,#1e293b);">0</div>
        </div>
        <div class="upercent-sum-card">
          <div class="upercent-sum-title" style="color:#16a34a;">Passed Students</div>
          <div id="upercentSumPassed" class="upercent-sum-num" style="color:#16a34a;">0</div>
        </div>
        <div class="upercent-sum-card">
          <div class="upercent-sum-title" style="color:#dc2626;">Failed Students</div>
          <div id="upercentSumFailed" class="upercent-sum-num" style="color:#dc2626;">0</div>
        </div>
        <div class="upercent-sum-card">
          <div class="upercent-sum-title" style="color:#FF4F01;">Overall Pass Percentage</div>
          <div id="upercentSumPct" class="upercent-sum-num" style="color:#FF4F01;">0.00%</div>
        </div>
      </div>
    </div>

    <!-- 5. Attachment: Result Copy Section -->
    <div style="margin-top:16px; padding:16px 18px; border:1px solid var(--border,#e2e8f0); border-radius:8px; background:var(--bg-subtle,#f8fafc);">
      <label style="font-weight:600; font-size:13.5px; color:var(--navy,#131D3B); display:flex; align-items:center; gap:6px;">
        <?= icon('paperclip', 15) ?> Result Copy <span class="req">*</span>
        <span class="card-sub" style="font-weight:400; font-size:12px; color:var(--ink-muted,#64748b);">— PDF or PNG, maximum 4 MB</span>
      </label>
      <div style="font-size:12px; color:var(--ink-muted,#64748b); margin:4px 0 10px 0;">
        Upload the university result copy or approved result document (PDF/PNG, maximum 4 MB).
      </div>
      <?php if (!empty($editRecord['proof_file'])): ?>
        <div style="font-size:12.5px; margin-bottom:8px; color:var(--ink,#1e293b); background:#fff; padding:6px 10px; border-radius:6px; border:1px solid var(--border,#e2e8f0); display:inline-flex; align-items:center; gap:8px;">
          <strong>Current File:</strong>
          <a href="<?= e(url('proof.php?file=' . urlencode($editRecord['proof_file']))) ?>" target="_blank" style="color:var(--primary,#2563eb); text-decoration:underline; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
            <?= icon('file-text', 13) ?> View Result Copy
          </a>
          <span style="color:var(--ink-muted,#64748b); font-size:11.5px;">(leave empty to keep the current file)</span>
        </div>
      <?php endif; ?>
      <input class="input" type="file" name="proof" id="upercentResultCopyInput" accept="application/pdf,image/png,.pdf,.png" <?= empty($editRecord['proof_file']) ? 'required' : '' ?> style="background:#fff;">
      <div id="upercentProofSizeError" style="color:var(--danger, #ef4444); font-size:12px; margin-top:5px; display:none;"></div>
    </div>
  </div>

  <script>
  (function() {
    var initialRows = <?= $existingRowsJson ?>;
    var currentRows = [];
    var allDepartments = <?= json_encode($deptNamesList) ?>;
    var allFacultyUsers = <?= json_encode($allFacultyData) ?>;
    var currentUser = {
      name: <?= json_encode($user['name'] ?? '') ?>,
      dept: <?= json_encode($user['department'] ?? '') ?>,
      role: <?= json_encode($user['role'] ?? '') ?>
    };

    var lastValidSession = <?= json_encode($currSession) ?>;
    var lastValidSemester = <?= json_encode($currSem) ?>;

    function isArchDept(d) {
      if (!d) return false;
      var s = String(d).trim().toLowerCase();
      return (s === 'arch' || s === 'architecture' || s.indexOf('architect') !== -1);
    }

    function getSelectedDept() {
      var dEl = document.querySelector('select[name="department"], input[name="department"]');
      if (dEl) return dEl.value.trim();
      return currentUser.dept || 'CSE';
    }

    function getSelectedSession() {
      var sEl = document.getElementById('upercentExamSession');
      return sEl ? sEl.value.trim() : '';
    }

    function getSelectedSemester() {
      var smEl = document.getElementById('upercentSemester');
      return smEl ? smEl.value.trim() : '';
    }

    function getYearForSemester(semStr) {
      var n = parseInt(String(semStr).replace(/[^0-9]/g, ''), 10);
      if (isNaN(n) || n <= 0) return '1st Year';
      if (n <= 2) return '1st Year';
      if (n <= 4) return '2nd Year';
      if (n <= 6) return '3rd Year';
      if (n <= 8) return '4th Year';
      return '5th Year';
    }

    function hasUnsavedRowData() {
      for (var i = 0; i < currentRows.length; i++) {
        var r = currentRows[i];
        if ((r.subject_code && String(r.subject_code).trim() !== '') ||
            (r.subject_name && String(r.subject_name).trim() !== '') ||
            (r.total_members && String(r.total_members).trim() !== '') ||
            (r.passed_members && String(r.passed_members).trim() !== '')) {
          return true;
        }
      }
      return false;
    }

    function getFacultyForDept(dept) {
      if (!dept) return [];
      var target = String(dept).trim().toLowerCase();
      return allFacultyUsers.filter(function(f) {
        var fd = String(f.department || '').trim().toLowerCase();
        return fd === target || fd.replace(/\s+/g, '') === target.replace(/\s+/g, '');
      });
    }

    function getDeptForFaculty(facName) {
      if (!facName) return '';
      var target = String(facName).trim().toLowerCase();
      for (var i = 0; i < allFacultyUsers.length; i++) {
        if (String(allFacultyUsers[i].name).trim().toLowerCase() === target) {
          return allFacultyUsers[i].department;
        }
      }
      return '';
    }

    function syncSemesterOptions(preserveVal) {
      var dept = getSelectedDept();
      var session = getSelectedSession();
      var isArch = isArchDept(dept);
      var maxSem = isArch ? 10 : 8;
      var semSelect = document.getElementById('upercentSemester');
      if (!semSelect) return;

      var currentVal = preserveVal || semSelect.value;
      semSelect.innerHTML = '<option value="" disabled>Select Semester</option>';

      if (!session) {
        semSelect.innerHTML = '<option value="" disabled selected>Select Exam Session First</option>';
        return;
      }

      var isOddSession = (session === 'Nov/Dec' || session === 'Nov-Dec');
      var isEvenSession = (session === 'Apr/May' || session === 'Apr-May');

      var allowedSems = [];
      for (var s = 1; s <= maxSem; s++) {
        if (isOddSession && s % 2 !== 0) {
          allowedSems.push('Sem ' + s);
        } else if (isEvenSession && s % 2 === 0) {
          allowedSems.push('Sem ' + s);
        } else if (!isOddSession && !isEvenSession) {
          allowedSems.push('Sem ' + s);
        }
      }

      allowedSems.forEach(function(sm) {
        var opt = document.createElement('option');
        opt.value = sm;
        opt.textContent = sm + ' (' + getYearForSemester(sm) + ')';
        semSelect.appendChild(opt);
      });

      if (currentVal && allowedSems.indexOf(currentVal) !== -1) {
        semSelect.value = currentVal;
      } else if (allowedSems.length > 0) {
        semSelect.value = allowedSems[0];
      }
    }

    function getDefaultYearRows(dept) {
      var isArch = isArchDept(dept);
      var defaultDept = (currentUser.role === 'Faculty' && currentUser.dept) ? currentUser.dept : dept;
      var defaultFac = (currentUser.role === 'Faculty' && currentUser.name) ? currentUser.name : '';

      var currSem = getSelectedSemester();
      var matchedYear = getYearForSemester(currSem);

      var years = isArch
        ? ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']
        : ['1st Year', '2nd Year', '3rd Year', '4th Year'];

      return years.map(function(yr, idx) {
        return {
          class_year: yr,
          subject_code: '',
          subject_name: '',
          faculty_dept: defaultDept,
          faculty_name: defaultFac,
          total_members: '',
          passed_members: '',
          failed_members: 0,
          pass_percentage: '0.00',
          sort_order: idx + 1
        };
      });
    }

    function h(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderTable() {
      var tbody = document.getElementById('upercentTableBody');
      if (!tbody) return;
      tbody.innerHTML = '';

      var dept = getSelectedDept();
      var isArch = isArchDept(dept);
      var badge = document.getElementById('upercentDeptBadge');
      if (badge) {
        badge.innerHTML = isArch
          ? 'Architecture Programme: <strong>5 Academic Years</strong> enabled (1st Year to 5th Year).'
          : 'Standard Engineering Programme: <strong>4 Academic Years</strong> enabled (1st Year to 4th Year).';
      }

      var yrOptions = isArch
        ? ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']
        : ['1st Year', '2nd Year', '3rd Year', '4th Year'];

      currentRows.forEach(function(row, idx) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-row-idx', idx);

        var regStr = String(row.total_members !== null && row.total_members !== undefined ? row.total_members : '').trim();
        var passStr = String(row.passed_members !== null && row.passed_members !== undefined ? row.passed_members : '').trim();

        var regValid = /^[0-9]+$/.test(regStr);
        var passValid = /^[0-9]+$/.test(passStr);

        var regNum = regValid ? parseInt(regStr, 10) : 0;
        var passNum = passValid ? parseInt(passStr, 10) : 0;

        var failedVal = Math.max(0, regNum - passNum);
        row.failed_members = failedVal;

        var pctVal = (regNum > 0) ? ((passNum / regNum) * 100).toFixed(2) : '0.00';
        row.pass_percentage = pctVal;

        var isExceed = (regValid && passValid && passNum > regNum);
        var hasNonNumeric = (regStr !== '' && !regValid) || (passStr !== '' && !passValid);

        // Year select
        var yrSelectHtml = '<select class="select" onchange="upercentSetRowYear(' + idx + ', this.value)">';
        yrOptions.forEach(function(y) {
          yrSelectHtml += '<option value="' + y + '"' + (row.class_year === y ? ' selected' : '') + '>' + y + '</option>';
        });
        yrSelectHtml += '</select>';

        // Department select (Column 5 - Faculty Dept)
        var rowDept = row.faculty_dept || dept;
        var deptSelectHtml = '<select class="select upercent-fac-dept-select" onchange="upercentOnRowDeptChange(' + idx + ', this.value)"' +
          (currentUser.role === 'Faculty' ? ' disabled title="Faculty submissions are restricted to your own department."' : '') + '>';
        allDepartments.forEach(function(d) {
          var dName = (typeof d === 'object' && d !== null) ? (d.name || d.code || '') : String(d || '');
          if (!dName) return;
          deptSelectHtml += '<option value="' + h(dName) + '"' + (rowDept.toLowerCase() === dName.toLowerCase() ? ' selected' : '') + '>' + h(dName) + '</option>';
        });
        deptSelectHtml += '</select>';

        // Faculty Name select (Column 6 - Faculty Name)
        var matchingFaculty = getFacultyForDept(rowDept);
        var facSelectHtml = '<select class="select upercent-fac-name-select" onchange="upercentOnRowFacChange(' + idx + ', this.value)">';
        facSelectHtml += '<option value="">Select Faculty</option>';
        var facFound = false;
        matchingFaculty.forEach(function(fac) {
          var isSel = (row.faculty_name && row.faculty_name.trim().toLowerCase() === fac.name.trim().toLowerCase());
          if (isSel) facFound = true;
          facSelectHtml += '<option value="' + h(fac.name) + '"' + (isSel ? ' selected' : '') + '>' + h(fac.name) + '</option>';
        });
        if (row.faculty_name && !facFound) {
          facSelectHtml += '<option value="' + h(row.faculty_name) + '" selected>' + h(row.faculty_name) + ' (Existing)</option>';
        }
        facSelectHtml += '</select>';

        var regInputStyle = (regStr !== '' && !regValid ? 'border-color:#ef4444; background:#fef2f2;' : '') + 'text-align:right;';
        var passInputStyle = ((passStr !== '' && !passValid) || isExceed ? 'border-color:#ef4444; background:#fef2f2;' : '') + 'text-align:right;';

        var inlineErrMsg = '';
        if (hasNonNumeric) inlineErrMsg = 'Must be a non-negative whole number (digits only)';
        else if (isExceed) inlineErrMsg = 'Passed cannot exceed Registered';

        var pctColor = parseFloat(pctVal) >= 75 ? '#16a34a' : (parseFloat(pctVal) >= 50 ? '#d97706' : '#dc2626');

        tr.innerHTML =
          '<td style="text-align:center; color:#64748b; font-weight:600;">' + (idx + 1) + '</td>' +
          '<td>' + yrSelectHtml + '</td>' +
          '<td><input class="input" list="upercentSubjectDatalist" value="' + h(row.subject_code) + '" placeholder="e.g. CS301" required oninput="upercentSetField(' + idx + ',\'subject_code\',this.value)"></td>' +
          '<td><input class="input" value="' + h(row.subject_name) + '" placeholder="Subject Name" required oninput="upercentSetField(' + idx + ',\'subject_name\',this.value)"></td>' +
          '<td>' + deptSelectHtml + '</td>' +
          '<td>' + facSelectHtml + '</td>' +
          '<td style="text-align:right;">' +
            '<input class="input upercent-reg-input" style="' + regInputStyle + '" type="text" inputmode="numeric" placeholder="0" value="' + h(row.total_members) + '" required oninput="upercentOnNumericInput(' + idx + ')">' +
          '</td>' +
          '<td style="text-align:right;">' +
            '<input class="input upercent-passed-input" style="' + passInputStyle + '" type="text" inputmode="numeric" placeholder="0" value="' + h(row.passed_members) + '" required oninput="upercentOnNumericInput(' + idx + ')">' +
          '</td>' +
          '<td style="text-align:right;"><input class="input upercent-failed-input" style="text-align:right; background:var(--bg-subtle,#f3f4f6); cursor:not-allowed;" type="text" readonly value="' + failedVal + '"></td>' +
          '<td style="text-align:right; font-weight:700;"><span class="upercent-pct-cell" style="color:' + pctColor + ';">' + pctVal + '%</span></td>' +
          '<td style="text-align:center;">' +
            (currentRows.length > 1
              ? '<button type="button" class="btn btn-ghost btn-sm" style="color:#ef4444; padding:2px 6px;" onclick="upercentDeleteRow(' + idx + ')" title="Remove Row">&#x2715;</button>'
              : '<span style="color:#cbd5e1;">—</span>') +
          '</td>';

        if (inlineErrMsg) {
          var errTr = document.createElement('tr');
          errTr.className = 'upercent-err-row';
          errTr.innerHTML = '<td colspan="11" style="padding:4px 10px; background:#fef2f2; border-bottom:1px solid #fee2e2; color:#dc2626; font-size:11px; font-weight:600;">' +
            '⚠️ Row ' + (idx + 1) + ': ' + h(inlineErrMsg) + '</td>';
          tbody.appendChild(tr);
          tbody.appendChild(errTr);
        } else {
          tbody.appendChild(tr);
        }
      });

      updateSummary();
      syncHiddenJson();
    }

    function syncHiddenJson() {
      var jsonEl = document.getElementById('instPpRowsJson');
      if (jsonEl) jsonEl.value = JSON.stringify(currentRows);
    }

    function updateSummary() {
      var tSubj = currentRows.length;
      var sumReg = 0;
      var sumPassed = 0;

      currentRows.forEach(function(r) {
        var regStr = String(r.total_members || '').trim();
        var passStr = String(r.passed_members || '').trim();
        if (/^[0-9]+$/.test(regStr)) {
          var reg = parseInt(regStr, 10);
          if (reg > 0) sumReg += reg;
        }
        if (/^[0-9]+$/.test(passStr)) {
          var passed = parseInt(passStr, 10);
          if (passed > 0) sumPassed += passed;
        }
      });

      var sumFailed = Math.max(0, sumReg - sumPassed);
      var overallPct = sumReg > 0 ? ((sumPassed / sumReg) * 100).toFixed(2) : '0.00';

      var elSubj = document.getElementById('upercentSumSubjects');
      var elReg = document.getElementById('upercentSumReg');
      var elPassed = document.getElementById('upercentSumPassed');
      var elFailed = document.getElementById('upercentSumFailed');
      var elPct = document.getElementById('upercentSumPct');

      if (elSubj) elSubj.textContent = tSubj;
      if (elReg) elReg.textContent = sumReg;
      if (elPassed) elPassed.textContent = sumPassed;
      if (elFailed) elFailed.textContent = sumFailed;
      if (elPct) elPct.textContent = overallPct + '%';
    }

    window.upercentOnNumericInput = function(idx) {
      if (!currentRows[idx]) return;
      var tr = document.querySelector('tr[data-row-idx="' + idx + '"]');
      if (!tr) return;

      var regInput = tr.querySelector('.upercent-reg-input');
      var passInput = tr.querySelector('.upercent-passed-input');
      var failedInput = tr.querySelector('.upercent-failed-input');
      var pctCell = tr.querySelector('.upercent-pct-cell');

      var regStr = regInput ? regInput.value.trim() : '';
      var passStr = passInput ? passInput.value.trim() : '';

      var regValid = regStr === '' || /^[0-9]+$/.test(regStr);
      var passValid = passStr === '' || /^[0-9]+$/.test(passStr);

      var regNum = (regStr !== '' && /^[0-9]+$/.test(regStr)) ? parseInt(regStr, 10) : 0;
      var passNum = (passStr !== '' && /^[0-9]+$/.test(passStr)) ? parseInt(passStr, 10) : 0;

      var isExceed = (regStr !== '' && passStr !== '' && regValid && passValid && passNum > regNum);

      if (regInput) {
        if (!regValid) {
          regInput.style.borderColor = '#ef4444';
          regInput.style.background = '#fef2f2';
        } else {
          regInput.style.borderColor = '';
          regInput.style.background = '';
        }
      }

      if (passInput) {
        if (!passValid || isExceed) {
          passInput.style.borderColor = '#ef4444';
          passInput.style.background = '#fef2f2';
          passInput.title = isExceed ? 'Passed cannot exceed Registered' : 'Must be whole numbers';
        } else {
          passInput.style.borderColor = '';
          passInput.style.background = '';
          passInput.title = '';
        }
      }

      var failedVal = Math.max(0, regNum - passNum);
      var pctVal = (regNum > 0) ? ((passNum / regNum) * 100).toFixed(2) : '0.00';

      if (failedInput) failedInput.value = failedVal;
      if (pctCell) {
        pctCell.textContent = pctVal + '%';
        pctCell.style.color = parseFloat(pctVal) >= 75 ? '#16a34a' : (parseFloat(pctVal) >= 50 ? '#d97706' : '#dc2626');
      }

      currentRows[idx].total_members = regStr;
      currentRows[idx].passed_members = passStr;
      currentRows[idx].failed_members = failedVal;
      currentRows[idx].pass_percentage = pctVal;

      updateSummary();
      syncHiddenJson();
    };

    window.upercentSetField = function(idx, field, val) {
      if (currentRows[idx]) {
        currentRows[idx][field] = val;
        syncHiddenJson();
      }
    };

    window.upercentSetRowYear = function(idx, val) {
      if (currentRows[idx]) {
        currentRows[idx].class_year = val;
        syncHiddenJson();
      }
    };

    window.upercentOnRowDeptChange = function(idx, newDept) {
      if (!currentRows[idx]) return;
      currentRows[idx].faculty_dept = newDept;

      var tr = document.querySelector('tr[data-row-idx="' + idx + '"]');
      if (tr) {
        var facSelect = tr.querySelector('.upercent-fac-name-select');
        if (facSelect) {
          var facultyList = getFacultyForDept(newDept);
          facSelect.innerHTML = '<option value="">Select Faculty</option>';
          facultyList.forEach(function(fac) {
            facSelect.innerHTML += '<option value="' + h(fac.name) + '">' + h(fac.name) + '</option>';
          });
          facSelect.value = '';
          currentRows[idx].faculty_name = '';
        }
      }
      syncHiddenJson();
    };

    window.upercentOnRowFacChange = function(idx, newFacName) {
      if (!currentRows[idx]) return;
      currentRows[idx].faculty_name = newFacName;

      var facDept = getDeptForFaculty(newFacName);
      if (facDept) {
        currentRows[idx].faculty_dept = facDept;
        var tr = document.querySelector('tr[data-row-idx="' + idx + '"]');
        if (tr) {
          var deptSelect = tr.querySelector('.upercent-fac-dept-select');
          if (deptSelect) deptSelect.value = facDept;
        }
      }
      syncHiddenJson();
    };

    window.upercentAddSubjectRow = function() {
      var dept = getSelectedDept();
      var currSem = getSelectedSemester();
      var defaultYr = getYearForSemester(currSem);
      if (currentRows.length > 0) {
        defaultYr = currentRows[currentRows.length - 1].class_year || defaultYr;
      }

      currentRows.push({
        class_year: defaultYr,
        subject_code: '',
        subject_name: '',
        faculty_dept: (currentUser.role === 'Faculty' && currentUser.dept) ? currentUser.dept : dept,
        faculty_name: (currentUser.role === 'Faculty' && currentUser.name) ? currentUser.name : '',
        total_members: '',
        passed_members: '',
        failed_members: 0,
        pass_percentage: '0.00',
        sort_order: currentRows.length + 1
      });
      renderTable();
    };

    window.upercentDeleteRow = function(idx) {
      if (currentRows[idx]) {
        currentRows.splice(idx, 1);
        renderTable();
      }
    };

    window.upercentResetDefaultRows = function() {
      if (hasUnsavedRowData()) {
        if (!confirm('This will clear the current table rows and reset to default year entries. Do you wish to continue?')) {
          return;
        }
      }
      var dept = getSelectedDept();
      currentRows = getDefaultYearRows(dept);
      renderTable();
    };

    window.upercentOnSessionChange = function(selectEl) {
      if (hasUnsavedRowData()) {
        if (!confirm('You have unsaved subject entries. Changing the exam session will reload records for the selected session. Do you want to proceed?')) {
          if (selectEl && lastValidSession) selectEl.value = lastValidSession;
          return;
        }
      }
      lastValidSession = selectEl ? selectEl.value : '';
      syncSemesterOptions();
      lastValidSemester = getSelectedSemester();
      upercentFetchExistingData();
    };

    window.upercentOnSemesterChange = function(selectEl) {
      if (hasUnsavedRowData()) {
        if (!confirm('You have unsaved subject entries. Changing the semester will reload records for the selected semester. Do you want to proceed?')) {
          if (selectEl && lastValidSemester) selectEl.value = lastValidSemester;
          return;
        }
      }
      lastValidSemester = selectEl ? selectEl.value : '';
      upercentFetchExistingData();
    };

    function populateSubjectDatalist(subjectList) {
      var sDl = document.getElementById('upercentSubjectDatalist');
      if (sDl && Array.isArray(subjectList)) {
        sDl.innerHTML = '';
        subjectList.forEach(function(sub) {
          var opt = document.createElement('option');
          opt.value = sub.subject_code;
          opt.textContent = sub.subject_name ? (sub.subject_code + ' - ' + sub.subject_name) : sub.subject_code;
          sDl.appendChild(opt);
        });
      }
    }

    function upercentFetchExistingData() {
      var dept = getSelectedDept();
      var session = getSelectedSession();
      var sem = getSelectedSemester();
      var ayEl = document.getElementById('upercentAcademicYear');
      var ay = ayEl ? ayEl.value.trim() : '';

      if (!session || !sem) return;

      var params = new URLSearchParams({
        action: 'fetch_upercent',
        department: dept,
        exam_session: session,
        semester: sem,
        academic_year: ay
      });

      var fetchUrl = window.location.pathname ? (window.location.pathname + '?' + params.toString()) : ('upload.php?' + params.toString());

      fetch(fetchUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!data || !data.success) return;

        if (Array.isArray(data.all_faculty)) {
          allFacultyUsers = data.all_faculty;
        }
        if (data.subjects) {
          populateSubjectDatalist(data.subjects || []);
        }

        var editIdEl = document.getElementById('upercentEditId');
        if (data.found && Array.isArray(data.rows) && data.rows.length > 0) {
          currentRows = data.rows.map(function(r, idx) {
            return {
              class_year: r.class_year || getYearForSemester(sem),
              subject_code: r.subject_code || '',
              subject_name: r.subject_name || '',
              faculty_dept: r.faculty_dept || dept,
              faculty_name: r.faculty_name || '',
              total_members: r.total_members !== null && r.total_members !== undefined ? String(r.total_members) : '',
              passed_members: r.passed_members !== null && r.passed_members !== undefined ? String(r.passed_members) : '',
              failed_members: r.failed_members || 0,
              pass_percentage: r.pass_percentage || '0.00',
              sort_order: r.sort_order || (idx + 1)
            };
          });
          if (editIdEl) editIdEl.value = data.record_id || 0;
        } else {
          if (editIdEl) editIdEl.value = 0;
          currentRows = getDefaultYearRows(dept);
        }
        renderTable();
      })
      .catch(function(err) {
        console.warn('upercent live fetch notice:', err);
      });
    }

    function initUpercent() {
      var dept = getSelectedDept();
      syncSemesterOptions(<?= json_encode($currSem) ?>);

      var deptEl = document.querySelector('select[name="department"]');
      if (deptEl) {
        deptEl.addEventListener('change', function() {
          if (hasUnsavedRowData()) {
            if (!confirm('You have unsaved subject entries. Changing the department will reload records. Do you want to proceed?')) {
              return;
            }
          }
          syncSemesterOptions();
          upercentFetchExistingData();
        });
      }

      if (Array.isArray(initialRows) && initialRows.length > 0) {
        currentRows = initialRows.map(function(r, idx) {
          return {
            class_year: r.class_year || ('Year ' + (idx + 1)),
            subject_code: r.subject_code || '',
            subject_name: r.subject_name || '',
            faculty_dept: r.faculty_dept || dept,
            faculty_name: r.faculty_name || '',
            total_members: r.total_members !== null && r.total_members !== undefined ? String(r.total_members) : '',
            passed_members: r.passed_members !== null && r.passed_members !== undefined ? String(r.passed_members) : '',
            failed_members: r.failed_members || 0,
            pass_percentage: r.pass_percentage || '0.00',
            sort_order: r.sort_order || (idx + 1)
          };
        });
      } else {
        currentRows = getDefaultYearRows(dept);
      }

      renderTable();
      upercentFetchExistingData();

      // 5. Result Copy attachment client-side validation (PDF/PNG <= 4 MB)
      var proofInput = document.getElementById('upercentResultCopyInput');
      if (proofInput) {
        var MAX_PROOF_BYTES = 4 * 1024 * 1024; // 4 MB
        var proofErr = document.getElementById('upercentProofSizeError');

        proofInput.addEventListener('change', function() {
          var file = this.files && this.files[0];
          if (!file) return;

          if (file.size > MAX_PROOF_BYTES) {
            var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            alert('Result Copy file size exceeds the 4 MB limit (' + sizeMb + ' MB). Please choose a file ≤ 4 MB.');
            this.value = '';
            if (proofErr) {
              proofErr.textContent = 'Selected Result Copy (' + sizeMb + ' MB) exceeds the 4 MB limit. Selection cleared. Please choose a file ≤ 4 MB.';
              proofErr.style.display = 'block';
            }
            return false;
          }

          var name = file.name.toLowerCase();
          if (!name.endsWith('.pdf') && !name.endsWith('.png')) {
            alert('Only PDF and PNG files are allowed as Result Copy.');
            this.value = '';
            if (proofErr) {
              proofErr.textContent = 'Only PDF and PNG documents are allowed for Result Copy. Selection cleared.';
              proofErr.style.display = 'block';
            }
            return false;
          }

          if (proofErr) {
            proofErr.textContent = '';
            proofErr.style.display = 'none';
          }
        });
      }

      // Form submission validation
      var form = document.querySelector('form');
      if (form) {
        form.addEventListener('submit', function(e) {
          var typeInput = form.querySelector('input[name="record_type"]');
          if (!typeInput || typeInput.value !== 'inst_pass_percentage') return;

          if (!currentRows || currentRows.length === 0) {
            alert('Please add at least one subject result entry.');
            e.preventDefault();
            return false;
          }

          for (var i = 0; i < currentRows.length; i++) {
            var r = currentRows[i];
            var rowNum = i + 1;
            if (!r.subject_code || !String(r.subject_code).trim()) {
              alert('Row ' + rowNum + ': Subject Code is required.');
              e.preventDefault();
              return false;
            }
            if (!r.subject_name || !String(r.subject_name).trim()) {
              alert('Row ' + rowNum + ': Subject Name is required.');
              e.preventDefault();
              return false;
            }
            if (!r.faculty_dept || !String(r.faculty_dept).trim()) {
              alert('Row ' + rowNum + ': Faculty Department is required.');
              e.preventDefault();
              return false;
            }
            if (!r.faculty_name || !String(r.faculty_name).trim()) {
              alert('Row ' + rowNum + ': Faculty Name is required.');
              e.preventDefault();
              return false;
            }

            var regStr = String(r.total_members || '').trim();
            var passStr = String(r.passed_members || '').trim();

            if (!/^[0-9]+$/.test(regStr)) {
              alert('Row ' + rowNum + ': Registered students must be a valid non-negative whole number (digits only).');
              e.preventDefault();
              return false;
            }
            if (!/^[0-9]+$/.test(passStr)) {
              alert('Row ' + rowNum + ': Passed students must be a valid non-negative whole number (digits only).');
              e.preventDefault();
              return false;
            }
            var reg = parseInt(regStr, 10);
            var passed = parseInt(passStr, 10);
            if (passed > reg) {
              alert('Row ' + rowNum + ': Passed students (' + passed + ') cannot exceed Registered students (' + reg + ').');
              e.preventDefault();
              return false;
            }
          }
          syncHiddenJson();
        });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initUpercent);
    } else {
      initUpercent();
    }
  })();
  </script>

<?php elseif ($selectedType === 'inst_college_rank'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php 
    $cgpaEditYear = $editRecord['class_year'] ?? '';
    $cgpaEditSem  = $editRecord['semester'] ?? '';
    $cgpaProof    = $editRecord['proof_file'] ?? '';
  ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field">
    <label for="cgpaProgramme">Programme / Course</label>
    <input class="input" id="cgpaProgramme" name="programme" placeholder="e.g. B.E. ECE" value="<?= e($editRecord['programme'] ?? '') ?>">
  </div>
  <div class="field">
    <label for="cgpaClassYear">Class / Year <span class="req">*</span></label>
    <select class="select" id="cgpaClassYear" name="class_year" required onchange="onCgpaClassYearChange(this.value)">
      <option value="" disabled <?= empty($cgpaEditYear) ? 'selected' : '' ?>>Select Class / Year</option>
      <option value="I Year" <?= ($cgpaEditYear === 'I Year') ? 'selected' : '' ?>>I Year</option>
      <option value="II Year" <?= ($cgpaEditYear === 'II Year') ? 'selected' : '' ?>>II Year</option>
      <option value="III Year" <?= ($cgpaEditYear === 'III Year') ? 'selected' : '' ?>>III Year</option>
      <option value="IV Year" <?= ($cgpaEditYear === 'IV Year') ? 'selected' : '' ?>>IV Year</option>
    </select>
  </div>
  <div class="field">
    <label for="cgpaSemester">Semester <span class="req">*</span></label>
    <select class="select" id="cgpaSemester" name="semester" required>
      <option value="" disabled selected>Select Semester</option>
    </select>
  </div>
  <div class="field">
    <label for="cgpaAbove85">Number of Students Secured Above 8.5 CGPA <span class="req">*</span></label>
    <input class="input" id="cgpaAbove85" name="students_above_8_5" type="number" min="0" step="1" required placeholder="e.g. 15" value="<?= e((string)($editRecord['students_above_8_5'] ?? '')) ?>">
    <span class="card-sub" style="font-size:11px; color:var(--ink-muted,#64748b);">Accepts only non-negative whole numbers. Validated against uploaded student list.</span>
  </div>
  <div class="field" style="grid-column:span 2; margin-top:8px; border:1px dashed var(--border,#cbd5e1); border-radius:10px; padding:16px; background:var(--bg-subtle,#f8fafc);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
      <div>
        <label for="cgpaExcelFile" style="font-weight:700; font-size:13px; color:var(--navy,#131D3B); display:block; margin-bottom:2px;">
          Attachment – List of Students Secured Above 8.5 CGPA <?= empty($cgpaProof) ? '<span class="req">*</span>' : '' ?>
        </label>
        <span class="card-sub" style="font-size:12px; color:var(--ink-muted,#64748b);">
          Upload the Excel list of students who secured above 8.5 CGPA.
        </span>
      </div>
      <div>
        <a href="<?= e(url('upload.php?action=download_cgpa_template')) ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border:1px solid #FF4F01; color:#FF4F01; border-radius:6px; font-size:12px; font-weight:600; text-decoration:none;" title="Download sample Excel template with preformatted headers">
          <?= icon('download', 14) ?> Download Excel Template (.xlsx)
        </a>
      </div>
    </div>

    <div style="font-size:11px; color:#475569; margin-bottom:12px; background:#f1f5f9; padding:8px 12px; border-radius:6px; display:flex; align-items:center; gap:6px;">
      <?= icon('info', 14) ?>
      <span>Workbook format: <code>.xlsx</code> | Required columns in exact order: <strong>S.No, Register Number, Name, Year, Semester, CGPA</strong></span>
    </div>

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <input type="file" name="proof" id="cgpaExcelFile" accept=".xlsx" class="input" style="padding:8px 12px; font-size:13px; max-width:400px;" <?= empty($cgpaProof) ? 'required' : '' ?> onchange="onCgpaFileSelected(this)">
      <span id="cgpaFileNameDisplay" style="font-size:12px; font-weight:600; display:none;"></span>
    </div>

    <?php if (!empty($cgpaProof)): ?>
      <div style="margin-top:10px; padding:8px 12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; font-size:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <span style="color:#1e40af; display:inline-flex; align-items:center; gap:6px;">
          <?= icon('file-text', 14) ?> Current attachment: <strong><?= e($cgpaProof) ?></strong>
          <a href="<?= e(url('proof.php?file=' . urlencode($cgpaProof) . '&download=1')) ?>" target="_blank" style="color:#2563EB; font-weight:600; margin-left:6px; text-decoration:underline;">Download</a>
        </span>
        <span style="font-size:11px; color:#64748b;">(Choose a new .xlsx file above to replace this attachment)</span>
      </div>
    <?php endif; ?>
  </div>

  <input type="hidden" name="edit_id" id="cgpaEditId" value="<?= (int)($editRecord['id'] ?? 0) ?>">

  <script>
    const CGPA_YEAR_SEMS = {
      'I Year': ['Sem 1', 'Sem 2'],
      'II Year': ['Sem 3', 'Sem 4'],
      'III Year': ['Sem 5', 'Sem 6'],
      'IV Year': ['Sem 7', 'Sem 8']
    };

    function onCgpaClassYearChange(year, preselectedSem) {
      const semSel = document.getElementById('cgpaSemester');
      if (!semSel) return;
      const sems = CGPA_YEAR_SEMS[year] || [];
      const currentVal = preselectedSem || semSel.value;
      
      semSel.innerHTML = '<option value="" disabled selected>Select Semester</option>';
      sems.forEach(function(s) {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        if (currentVal === s) {
          opt.selected = true;
        }
        semSel.appendChild(opt);
      });
    }

    function onCgpaFileSelected(inp) {
      const disp = document.getElementById('cgpaFileNameDisplay');
      if (!disp) return;
      if (inp.files && inp.files[0]) {
        const f = inp.files[0];
        const ext = f.name.split('.').pop().toLowerCase();
        const sizeKb = Math.round(f.size / 1024);
        if (ext !== 'xlsx') {
          disp.innerHTML = '<span style="color:#DC2626;">⚠ Invalid file type (.' + ext + '). Only .xlsx files are supported.</span>';
          disp.style.display = 'inline-block';
          inp.value = '';
          return;
        }
        if (f.size > 2 * 1024 * 1024) {
          disp.innerHTML = '<span style="color:#DC2626;">⚠ File too large (' + sizeKb + ' KB). Maximum size is 2 MB.</span>';
          disp.style.display = 'inline-block';
          inp.value = '';
          return;
        }
        disp.innerHTML = '<span style="color:#059669;">✓ Selected: ' + f.name + ' (' + sizeKb + ' KB) — Ready for validation</span>';
        disp.style.display = 'inline-block';
      } else {
        disp.innerHTML = '';
        disp.style.display = 'none';
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const yearSel = document.getElementById('cgpaClassYear');
      const savedSem = <?= json_encode($cgpaEditSem) ?>;
      if (yearSel && yearSel.value) {
        onCgpaClassYearChange(yearSel.value, savedSem);
      }

      const form = document.getElementById('cgpaExcelFile')?.closest('form');
      if (form) {
        form.addEventListener('submit', function() {
          const submitBtns = form.querySelectorAll('button[type="submit"]');
          setTimeout(function() {
            submitBtns.forEach(function(btn) {
              btn.disabled = true;
              btn.style.opacity = '0.7';
              btn.innerHTML = '<span>Validating &amp; Submitting...</span>';
            });
          }, 10);
        });
      }
    });
  </script>

<?php elseif ($selectedType === 'inst_placement_mnc'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Title / Work <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Copyright Number <span class="req">*</span></label><input class="input" name="copyright_number" placeholder="Registration / Diary No." required></div>
  <div class="field"><label>Registration Date <span class="req">*</span></label><input class="input" name="registration_date" type="date" required></div>
  <div class="field"><label>Category</label><input class="input" name="category" placeholder="Literary / Software / Artistic"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_sponsored_research'): ?>
  <div class="field"><label>Faculty Name / Principal Investigator (PI) <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Recognition Name / Recognized Department <span class="req">*</span></label><input class="input" name="recognition_name" placeholder="e.g. Research Centre Recognition for CSE" required></div>
  <div class="field"><label>Recognizing Authority <span class="req">*</span></label><input class="input" name="recognizing_authority" value="Anna University" required></div>
  <div class="field"><label>Recognition Date</label><input class="input" name="recognition_date" type="date"></div>
  <div class="field"><label>Reference / Approval Number</label><input class="input" name="reference_number"></div>
  <div class="field"><label>Status</label><input class="input" name="recognition_status" placeholder="Recognized / Active"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><textarea class="input" name="remarks" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_ipr_programmes'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php
    $socCategory = $editRecord['membership_category'] ?? ($_GET['category'] ?? 'Faculty');
    if (!in_array($socCategory, ['Faculty', 'Student'], true)) {
        $socCategory = 'Faculty';
    }
    $socMode = $_GET['mode'] ?? 'individual';
    $isSocBulk = ($socMode === 'bulk') && empty($editRecord);
    $memTypesFaculty = ['Life Member', 'Annual Member', 'Fellow', 'Senior Member', 'Associate Member', 'Member'];
    $memTypesStudent = ['Student Member', 'Annual Member', 'Life Member', 'Member'];
  ?>

  <!-- Top Form Control 1: Membership Category Dropdown -->
  <div class="field" style="grid-column: 1 / -1; margin-bottom: 6px;">
    <label style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
      <span style="font-size:13px; font-weight:700; color:#131D3B;">Membership Category <span class="req" style="color:#DC2626;">*</span></span>
      <span class="card-sub" style="font-size:11.5px; color:#64748B;">Select member classification to update entry controls and validation rules</span>
    </label>
    <select class="select" id="socCategorySelect" name="membership_category" style="font-size:13.5px; font-weight:600; color:#131D3B; border-color:#FF4F01; background-color:#FFF7ED; padding:9px 14px;" required>
      <option value="Faculty" <?= $socCategory === 'Faculty' ? 'selected' : '' ?>>Faculty</option>
      <option value="Student" <?= $socCategory === 'Student' ? 'selected' : '' ?>>Student</option>
    </select>
  </div>

  <!-- Top Form Control 2: Individual Entry / Bulk Upload Selector -->
  <?php if (empty($editRecord)): ?>
  <div class="field" style="grid-column: 1 / -1; margin-bottom: 14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:8px 14px;">
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <span style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748B;">Upload Mode:</span>
        <div style="display:inline-flex; background:#E2E8F0; padding:3px; border-radius:8px; gap:4px;">
          <button type="button" id="socBtnModeInd" class="btn-mode-toggle <?= !$isSocBulk ? 'active' : '' ?>" onclick="setSocietyInputMode('individual')">
            <?= icon('user', 14) ?> Individual Entry
          </button>
          <button type="button" id="socBtnModeBulk" class="btn-mode-toggle <?= $isSocBulk ? 'active' : '' ?>" onclick="setSocietyInputMode('bulk')">
            <?= icon('upload', 14) ?> Bulk Upload
          </button>
        </div>
      </div>
      <span id="socModeHelpText" style="font-size:12px; color:#64748B;">
        <?= $isSocBulk ? 'Batch import multiple records via validated Excel spreadsheet (.xlsx)' : 'Submit an individual membership record with certificate upload' ?>
      </span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Individual Fields Container (Displayed when mode is Individual Entry) -->
  <div id="socIndividualFieldsGroup" style="display: contents;">
    <!-- Dynamic Faculty Fields Container -->
    <div id="socFacultyFieldsRow" style="display: <?= $socCategory === 'Faculty' ? 'contents' : 'none' ?>;">
      <div class="field" id="socFieldFacultyName">
        <label>Faculty Name <span class="req">*</span></label>
        <input class="input" id="socInputFacultyName" name="faculty_name" value="<?= e($editRecord['faculty_name'] ?? ($user['name'] ?? '')) ?>" placeholder="Faculty member full name" <?= $socCategory === 'Faculty' ? 'required' : '' ?>>
      </div>
    </div>

    <!-- Dynamic Student Fields Container -->
    <div id="socStudentFieldsRow" style="display: <?= $socCategory === 'Student' ? 'contents' : 'none' ?>;">
      <div class="field" id="socFieldRegNo">
        <label style="display:flex; align-items:center; justify-content:space-between;">
          <span>Student Register Number <span class="req">*</span></span>
          <span id="socLookupBadge" style="font-size:11px; font-weight:600; display:none;"></span>
        </label>
        <input class="input" id="socInputRegNo" name="reg_no" value="<?= e($editRecord['reg_no'] ?? '') ?>" placeholder="e.g. 711322104001" autocomplete="off" <?= $socCategory === 'Student' ? 'required' : '' ?>>
      </div>
      <div class="field" id="socFieldStudentName">
        <label>Student Name <span class="req">*</span></label>
        <input class="input" id="socInputStudentName" name="student_name" value="<?= e($editRecord['student_name'] ?? '') ?>" placeholder="Student full name" <?= $socCategory === 'Student' ? 'required' : '' ?>>
      </div>
      <div class="field" id="socFieldClassYear">
        <label>Class / Year of Study</label>
        <select class="select" id="socInputClassYear" name="class_year">
          <option value="">-- Select Year --</option>
          <option value="I Year" <?= ($editRecord['class_year'] ?? '') === 'I Year' ? 'selected' : '' ?>>I Year</option>
          <option value="II Year" <?= ($editRecord['class_year'] ?? '') === 'II Year' ? 'selected' : '' ?>>II Year</option>
          <option value="III Year" <?= ($editRecord['class_year'] ?? '') === 'III Year' ? 'selected' : '' ?>>III Year</option>
          <option value="IV Year" <?= ($editRecord['class_year'] ?? '') === 'IV Year' ? 'selected' : '' ?>>IV Year</option>
        </select>
      </div>
    </div>

    <!-- Common Fields across Faculty and Student -->
    <?php render_dept_field($user, $departments, 'Department', true); ?>
    <?php render_academic_year_field(); ?>

    <div class="field">
      <label>Professional Society <span class="req">*</span></label>
      <input class="input" id="socInputSocietyName" name="society_name" placeholder="e.g. IEEE, CSI, ISTE, ACM, ASME" value="<?= e($editRecord['society_name'] ?? '') ?>" required>
    </div>

    <div class="field">
      <label>Membership Number <span class="req">*</span></label>
      <input class="input" id="socInputMembershipNumber" name="membership_number" placeholder="e.g. 98765432" value="<?= e($editRecord['membership_number'] ?? '') ?>" required>
    </div>

    <div class="field">
      <label>Membership Type</label>
      <select class="select" id="socMembershipTypeSelect" name="membership_type">
        <?php
          $currentMType = $editRecord['membership_type'] ?? '';
          $typeList = ($socCategory === 'Student') ? $memTypesStudent : $memTypesFaculty;
          foreach ($typeList as $mt):
        ?>
          <option value="<?= e($mt) ?>" <?= $currentMType === $mt ? 'selected' : '' ?>><?= e($mt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Start Date</label>
      <input class="input" name="start_date" type="date" value="<?= e($editRecord['start_date'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Expiry Date</label>
      <input class="input" name="expiry_date" type="date" value="<?= e($editRecord['expiry_date'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Status</label>
      <select class="select" name="membership_status">
        <option value="Active" <?= ($editRecord['membership_status'] ?? '') === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Permanent" <?= ($editRecord['membership_status'] ?? '') === 'Permanent' ? 'selected' : '' ?>>Permanent</option>
        <option value="Expired" <?= ($editRecord['membership_status'] ?? '') === 'Expired' ? 'selected' : '' ?>>Expired</option>
        <option value="Renewed" <?= ($editRecord['membership_status'] ?? '') === 'Renewed' ? 'selected' : '' ?>>Renewed</option>
      </select>
    </div>
  </div>
  <!-- End Individual Fields Container -->

<?php elseif ($selectedType === 'inst_newsletters'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
  <div class="field"><label>NSS Unit</label><input class="input" name="nss_unit" placeholder="e.g. Unit 1 / Unit 2"></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="0"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif (in_array($selectedType, ['inst_inter_inst_within', 'inst_inter_inst_outside', 'inst_inter_inst_awards'], true)): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <?php
    $currentLevel = $editRecord['event_level'] ?? 'State — Within State';
    if ($selectedType === 'inst_inter_inst_outside' && empty($editRecord['event_level'])) {
        $currentLevel = 'State — Outside State';
    }
    $currentPType = $editRecord['participation_type'] ?? 'Participant';
    if (empty($currentPType) && !empty($editRecord['award_medal'])) {
        $currentPType = 'Winner / First Place';
    }
    $currentAchType = $editRecord['achievement_type'] ?? (in_array($currentPType, ['Winner / First Place', 'Second Runner', 'Third Runner'], true) ? 'Medal' : 'Participation');
  ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required value="<?= e($editRecord['student_name'] ?? '') ?>" placeholder="e.g. Aravind Kumar"></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required value="<?= e($editRecord['reg_no'] ?? '') ?>" placeholder="e.g. 711322104001"></div>
  <div class="field" style="grid-column:span 2"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required value="<?= e($editRecord['event_name'] ?? '') ?>" placeholder="e.g. National Level Technical Symposium / Hackathon"></div>
  <div class="field"><label>Host Institution <span class="req">*</span></label><input class="input" name="host_institution" required value="<?= e($editRecord['host_institution'] ?? '') ?>" placeholder="e.g. PSG College of Technology"></div>
  <div class="field">
    <label>Event Level <span class="req">*</span></label>
    <select class="select" name="event_level" id="inter_inst_event_level" required>
      <option value="District" <?= ($currentLevel === 'District') ? 'selected' : '' ?>>District</option>
      <option value="State — Within State" <?= in_array($currentLevel, ['State — Within State', 'State', 'Within State'], true) ? 'selected' : '' ?>>State — Within State</option>
      <option value="State — Outside State" <?= in_array($currentLevel, ['State — Outside State', 'Outside State'], true) ? 'selected' : '' ?>>State — Outside State</option>
      <option value="National" <?= ($currentLevel === 'National') ? 'selected' : '' ?>>National</option>
      <option value="International" <?= ($currentLevel === 'International') ? 'selected' : '' ?>>International</option>
    </select>
  </div>
  <div class="field"><label>Location / City <span class="req">*</span></label><input class="input" name="location" required value="<?= e($editRecord['location'] ?? ($editRecord['city'] ?? '')) ?>" placeholder="e.g. Madurai, Chennai, Bengaluru"></div>
  <div class="field"><label>District / State Name <span class="card-sub">(Contextual)</span></label><input class="input" name="state_name" value="<?= e($editRecord['state_name'] ?? ($editRecord['district_name'] ?? ($editRecord['state'] ?? ''))) ?>" placeholder="e.g. Madurai / Tamil Nadu / Karnataka"></div>
  <div class="field"><label>Event Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required value="<?= e($editRecord['event_date'] ?? '') ?>" max="<?= app_ist_today() ?>"></div>
  <div class="field">
    <label>Participation Type <span class="req">*</span></label>
    <select class="select" name="participation_type" id="inter_inst_participation_type" required>
      <option value="Participant" <?= ($currentPType === 'Participant') ? 'selected' : '' ?>>Participant</option>
      <option value="Winner / First Place" <?= in_array($currentPType, ['Winner / First Place', 'Winner', 'First Place', '1st Place'], true) ? 'selected' : '' ?>>Winner / First Place</option>
      <option value="Second Runner" <?= in_array($currentPType, ['Second Runner', '2nd Runner', 'Runner-up', 'Second Place'], true) ? 'selected' : '' ?>>Second Runner</option>
      <option value="Third Runner" <?= in_array($currentPType, ['Third Runner', '3rd Runner', 'Third Place'], true) ? 'selected' : '' ?>>Third Runner</option>
      <option value="Presenter" <?= ($currentPType === 'Presenter') ? 'selected' : '' ?>>Presenter</option>
    </select>
  </div>
  <div class="field"><label>Position / Medal <span class="card-sub">(if won)</span></label><input class="input" name="position_award" id="inter_inst_position_award" value="<?= e($editRecord['position_award'] ?? ($editRecord['award_medal'] ?? ($editRecord['award'] ?? ''))) ?>" placeholder="e.g. First Place / 2nd Runner / Gold Medal"></div>
  <div class="field">
    <label>Event Participation / Achievement Type</label>
    <select class="select" name="achievement_type" id="inter_inst_achievement_type">
      <option value="Participation" <?= ($currentAchType === 'Participation') ? 'selected' : '' ?>>Participation</option>
      <option value="Medal" <?= in_array($currentAchType, ['Medal', 'Award / Prize Winner', 'Award'], true) ? 'selected' : '' ?>>Medal</option>
    </select>
  </div>
  <div class="field">
    <label>Participation Format</label>
    <select class="select" name="team_individual">
      <option value="Individual" <?= (($editRecord['team_individual'] ?? '') !== 'Team') ? 'selected' : '' ?>>Individual</option>
      <option value="Team" <?= (($editRecord['team_individual'] ?? '') === 'Team') ? 'selected' : '' ?>>Team</option>
    </select>
  </div>
  <div class="field" style="grid-column:span 2">
    <label for="inter_inst_cert_link_2">Certificate Link (Optional) <span class="card-sub">(https://...)</span></label>
    <input class="input" name="certificate_link" id="inter_inst_cert_link_2" type="url" value="<?= e($editRecord['certificate_link'] ?? '') ?>" placeholder="https://example.com/certificate.pdf">
  </div>
  <script>
    (function(){
      var pSel = document.getElementById('inter_inst_participation_type');
      var aSel = document.getElementById('inter_inst_achievement_type');
      var posInp = document.getElementById('inter_inst_position_award');
      if (pSel && aSel) {
        pSel.addEventListener('change', function(){
          var v = pSel.value;
          if (v === 'Winner / First Place') {
            aSel.value = 'Medal';
            if (posInp && !posInp.value) posInp.value = 'First Place';
          } else if (v === 'Second Runner') {
            aSel.value = 'Medal';
            if (posInp && !posInp.value) posInp.value = 'Second Runner';
          } else if (v === 'Third Runner') {
            aSel.value = 'Medal';
            if (posInp && !posInp.value) posInp.value = 'Third Runner';
          } else {
            aSel.value = 'Participation';
          }
        });
      }
    })();
  </script>

<?php elseif ($selectedType === 'inst_value_added_courses'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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

<?php elseif ($selectedType === 'inst_sports_state' || $selectedType === 'inst_sports_national'): ?>
  <!-- Import from Participation Action Banner -->
  <div class="field" style="grid-column: span 2; margin-bottom: 6px;">
    <div style="background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
      <div>
        <div style="font-weight: 600; color: #1e40af; font-size: 13.5px; display: flex; align-items: center; gap: 6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Import from Existing Student Participation
        </div>
        <div style="font-size: 12px; color: #4b5563; margin-top: 2px;">
          Select a verified participation record to automatically populate student and event information.
        </div>
        <div id="sports_imported_badge" style="display: <?= !empty($editRecord['participation_id']) ? 'inline-flex' : 'none' ?>; align-items: center; gap: 4px; margin-top: 6px; font-size: 11px; font-weight: 600; color: #047857; background: #d1fae5; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px;">
          ✓ Linked to Participation Record #<span id="sports_linked_pid"><?= e((string)($editRecord['participation_id'] ?? '')) ?></span>
        </div>
      </div>
      <div style="display: flex; gap: 8px; align-items: center;">
        <button type="button" id="btnOpenParticipationModal" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Import from Participation
        </button>
        <button type="button" id="btnClearImportedParticipation" class="btn btn-ghost btn-sm" style="display: <?= !empty($editRecord['participation_id']) ? 'inline-flex' : 'none' ?>; color: #dc2626; font-size: 12px;">
          Clear Link
        </button>
      </div>
    </div>
  </div>

  <input type="hidden" name="participation_id" id="sports_participation_id" value="<?= e((string)($editRecord['participation_id'] ?? '')) ?>">
  <input type="hidden" name="orig_type" value="<?= e($selectedType) ?>">

  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>

  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" id="sports_student_name" placeholder="e.g. Deepak S" value="<?= e($editRecord['student_name'] ?? '') ?>" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" id="sports_reg_no" placeholder="e.g. 711322104004" value="<?= e($editRecord['reg_no'] ?? '') ?>" required></div>
  <div class="field"><label>Sport <span class="req">*</span></label><input class="input" name="sport" id="sports_sport" placeholder="e.g. Cricket, Athletics, Badminton, Chess" value="<?= e($editRecord['sport'] ?? '') ?>" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" id="sports_event_name" placeholder="e.g. State Inter-Collegiate Athletic Meet" value="<?= e($editRecord['event_name'] ?? '') ?>" required></div>

  <div class="field">
    <label>Competition Level <span class="req">*</span></label>
    <select class="select" name="level_secured" id="sports_competition_level" required>
      <option value="" disabled <?= empty($editRecord['level_secured']) && empty($selectedType) ? 'selected' : '' ?>>Select Competition Level</option>
      <option value="State" <?= (($editRecord['level_secured'] ?? ($selectedType === 'inst_sports_national' ? 'National' : 'State')) === 'State') ? 'selected' : '' ?>>State</option>
      <option value="National" <?= (($editRecord['level_secured'] ?? ($selectedType === 'inst_sports_national' ? 'National' : 'State')) === 'National') ? 'selected' : '' ?>>National</option>
    </select>
  </div>

  <div class="field"><label>Event Date <span class="req">*</span></label><input class="input" name="event_date" id="sports_event_date" type="date" value="<?= e($editRecord['event_date'] ?? '') ?>" required></div>
  <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" id="sports_venue" placeholder="e.g. Jawaharlal Nehru Stadium, Chennai" value="<?= e($editRecord['venue'] ?? '') ?>" required></div>

  <div class="field">
    <label>Medal <span class="req">*</span></label>
    <select class="select" name="award" id="sports_medal_select" required>
      <option value="" disabled <?= empty($editRecord['award']) ? 'selected' : '' ?>>Select Medal</option>
      <option value="Gold" <?= (($editRecord['award'] ?? '') === 'Gold') ? 'selected' : '' ?>>Gold</option>
      <option value="Silver" <?= (($editRecord['award'] ?? '') === 'Silver') ? 'selected' : '' ?>>Silver</option>
      <option value="Bronze" <?= (($editRecord['award'] ?? '') === 'Bronze') ? 'selected' : '' ?>>Bronze</option>
    </select>
  </div>

  <div class="field">
    <label>Result / Position <span class="req">*</span> <span class="card-sub">— Auto-mapped from Medal</span></label>
    <input class="input" name="participation_position" id="sports_result_position" readonly style="background:var(--bg-subtle, #f3f4f6); font-weight:600; cursor:not-allowed;" placeholder="Auto-mapped from Medal" value="<?= e($editRecord['participation_position'] ?? '') ?>" required>
  </div>

  <!-- Modal for Import from Participation -->
  <dialog class="modal" id="sportsParticipationModal" style="max-width: 56rem; width: 95vw; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); padding: 0;">
    <div class="modal-head" style="padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
      <div>
        <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Select Student Participation Record
        </h3>
        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Choose an existing participation record to populate the sports achievement form.</div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('sportsParticipationModal').close()" style="font-size: 18px; line-height: 1; padding: 4px 8px;">&times;</button>
    </div>
    <div class="modal-body" style="padding: 16px 20px; max-height: 60vh; overflow-y: auto;">
      <div style="display: flex; gap: 12px; margin-bottom: 14px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 220px;">
          <input type="text" id="sportsModalSearch" class="input" placeholder="Search by student name, register number, or event..." style="width: 100%; font-size: 13px;">
        </div>
        <div style="width: 160px;">
          <select id="sportsModalDept" class="select" style="width: 100%; font-size: 13px;">
            <?php if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])): ?>
              <option value="<?= e($user['department']) ?>"><?= e($user['department']) ?></option>
            <?php else: ?>
              <option value="">All Departments</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <div id="sportsModalLoading" style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;">
        Loading participation records...
      </div>

      <div id="sportsModalEmpty" style="display: none; text-align: center; padding: 30px; color: #64748b; font-size: 13px; background: #f8fafc; border-radius: 8px;">
        No matching participation records found.
      </div>

      <div id="sportsModalTableWrapper" style="display: none; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
        <table class="table" style="width: 100%; font-size: 12.5px; border-collapse: collapse;">
          <thead>
            <tr style="background: #f1f5f9; text-align: left; border-bottom: 1px solid #e2e8f0;">
              <th style="padding: 8px 12px;">Student</th>
              <th style="padding: 8px 12px;">Reg. No</th>
              <th style="padding: 8px 12px;">Dept</th>
              <th style="padding: 8px 12px;">Event / Activity</th>
              <th style="padding: 8px 12px;">Level</th>
              <th style="padding: 8px 12px; text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody id="sportsModalTbody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-foot" style="padding: 12px 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; background: #f8fafc;">
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('sportsParticipationModal').close()">Cancel</button>
    </div>
  </dialog>

  <script>
  (function() {
    // Medal to Result Mapping
    const medalMap = {
      'Gold': 'Winner',
      'Silver': 'Runner-up',
      'Bronze': 'Third Place'
    };

    const medalSelect = document.getElementById('sports_medal_select');
    const resultInput = document.getElementById('sports_result_position');
    const compLvlSelect = document.getElementById('sports_competition_level');
    const recTypeHidden = document.getElementById('recordTypeInput');

    function syncMedalResult() {
      if (!medalSelect || !resultInput) return;
      const m = medalSelect.value;
      if (medalMap[m]) {
        resultInput.value = medalMap[m];
      }
    }

    if (medalSelect) {
      medalSelect.addEventListener('change', syncMedalResult);
      if (medalSelect.value && !resultInput.value) {
        syncMedalResult();
      }
    }

    if (compLvlSelect) {
      compLvlSelect.addEventListener('change', function() {
        if (recTypeHidden) {
          recTypeHidden.value = (this.value === 'National') ? 'inst_sports_national' : 'inst_sports_state';
        }
      });
    }

    // Modal and Import handling
    const modal = document.getElementById('sportsParticipationModal');
    const btnOpen = document.getElementById('btnOpenParticipationModal');
    const btnClear = document.getElementById('btnClearImportedParticipation');
    const hiddenPartId = document.getElementById('sports_participation_id');
    const badge = document.getElementById('sports_imported_badge');
    const linkedPidSpan = document.getElementById('sports_linked_pid');

    const searchInput = document.getElementById('sportsModalSearch');
    const deptSelect = document.getElementById('sportsModalDept');
    const loadingEl = document.getElementById('sportsModalLoading');
    const emptyEl = document.getElementById('sportsModalEmpty');
    const tableWrap = document.getElementById('sportsModalTableWrapper');
    const tbody = document.getElementById('sportsModalTbody');

    let searchTimer = null;

    if (btnOpen && modal) {
      btnOpen.addEventListener('click', function() {
        modal.showModal();
        loadParticipations();
      });
    }

    if (btnClear) {
      btnClear.addEventListener('click', function() {
        if (hiddenPartId) hiddenPartId.value = '';
        if (badge) badge.style.display = 'none';
        if (linkedPidSpan) linkedPidSpan.textContent = '';
        btnClear.style.display = 'none';
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadParticipations, 250);
      });
    }

    if (deptSelect) {
      deptSelect.addEventListener('change', loadParticipations);
    }

    function loadParticipations() {
      if (!loadingEl || !emptyEl || !tableWrap || !tbody) return;
      loadingEl.style.display = 'block';
      emptyEl.style.display = 'none';
      tableWrap.style.display = 'none';
      tbody.innerHTML = '';

      const searchVal = searchInput ? searchInput.value.trim() : '';
      const deptVal = deptSelect ? deptSelect.value.trim() : '';
      const params = new URLSearchParams({
        action: 'fetch_sports_participations',
        search: searchVal,
        department: deptVal
      });

      fetch('/upload.php?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(res => res.json())
      .then(data => {
        loadingEl.style.display = 'none';
        if (!data.success || !Array.isArray(data.records) || data.records.length === 0) {
          emptyEl.style.display = 'block';
          return;
        }

        tableWrap.style.display = 'block';
        data.records.forEach(r => {
          const tr = document.createElement('tr');
          tr.style.borderBottom = '1px solid #e2e8f0';

          const tdStudent = document.createElement('td');
          tdStudent.style.padding = '8px 12px';
          tdStudent.style.fontWeight = '600';
          tdStudent.textContent = r.student_name || '—';

          const tdReg = document.createElement('td');
          tdReg.style.padding = '8px 12px';
          tdReg.textContent = r.reg_no || '—';

          const tdDept = document.createElement('td');
          tdDept.style.padding = '8px 12px';
          tdDept.textContent = r.department || '—';

          const tdEvent = document.createElement('td');
          tdEvent.style.padding = '8px 12px';
          tdEvent.textContent = r.event_name || r.function_name || r.activity_category || '—';

          const tdLevel = document.createElement('td');
          tdLevel.style.padding = '8px 12px';
          tdLevel.textContent = r.level_secured || '—';

          const tdAction = document.createElement('td');
          tdAction.style.padding = '8px 12px';
          tdAction.style.textAlign = 'right';

          if (r.already_imported) {
            const badgeImp = document.createElement('span');
            badgeImp.style.cssText = 'background:#fef3c7; color:#92400e; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600; display:inline-block;';
            badgeImp.textContent = 'Already Imported';
            tdAction.appendChild(badgeImp);
          } else {
            const btnImport = document.createElement('button');
            btnImport.type = 'button';
            btnImport.className = 'btn btn-primary btn-sm';
            btnImport.style.padding = '4px 10px';
            btnImport.style.fontSize = '12px';
            btnImport.textContent = 'Import';
            btnImport.addEventListener('click', function() {
              importRecord(r.id);
            });
            tdAction.appendChild(btnImport);
          }

          tr.appendChild(tdStudent);
          tr.appendChild(tdReg);
          tr.appendChild(tdDept);
          tr.appendChild(tdEvent);
          tr.appendChild(tdLevel);
          tr.appendChild(tdAction);
          tbody.appendChild(tr);
        });
      })
      .catch(err => {
        loadingEl.style.display = 'none';
        emptyEl.textContent = 'Failed to load participation records.';
        emptyEl.style.display = 'block';
      });
    }

    function importRecord(id) {
      fetch('/upload.php?action=get_sports_participation&id=' + encodeURIComponent(id), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(res => res.json())
      .then(data => {
        if (!data.success) {
          alert(data.error || 'Failed to import participation record.');
          return;
        }
        const rec = data.record;
        if (!rec) return;

        // Populate fields
        const sName = document.getElementById('sports_student_name');
        if (sName && rec.student_name) sName.value = rec.student_name;

        const sReg = document.getElementById('sports_reg_no');
        if (sReg && rec.reg_no) sReg.value = rec.reg_no;

        const sSport = document.getElementById('sports_sport');
        if (sSport && rec.sport) sSport.value = rec.sport;

        const sEvent = document.getElementById('sports_event_name');
        if (sEvent && rec.event_name) sEvent.value = rec.event_name;

        const sLevel = document.getElementById('sports_competition_level');
        if (sLevel && (rec.competition_level === 'State' || rec.competition_level === 'National')) {
          sLevel.value = rec.competition_level;
          sLevel.dispatchEvent(new Event('change'));
        }

        const sDate = document.getElementById('sports_event_date');
        if (sDate && rec.event_date) sDate.value = rec.event_date;

        const sVenue = document.getElementById('sports_venue');
        if (sVenue && rec.venue) sVenue.value = rec.venue;

        // Department select
        const deptSelectForm = document.querySelector('select[name="department"]');
        if (deptSelectForm && rec.department) {
          deptSelectForm.value = rec.department;
        }

        // Set participation_id & show badge
        if (hiddenPartId) hiddenPartId.value = rec.id;
        if (linkedPidSpan) linkedPidSpan.textContent = rec.id;
        if (badge) badge.style.display = 'inline-flex';
        if (btnClear) btnClear.style.display = 'inline-flex';

        // Close modal
        modal.close();
      })
      .catch(err => {
        alert('Network error while importing participation record.');
      });
    }
  })();
  </script>


<?php elseif ($selectedType === 'inst_innovation_events'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field" style="grid-column:span 2"><label>Update Title <span class="req">*</span></label><input class="input" name="update_title" placeholder="e.g. Faculty Profile Update / New Syllabus" required></div>
  <div class="field"><label>Page / Section Updated <span class="req">*</span></label><input class="input" name="page_section" placeholder="e.g. Dept Homepage, Labs, Research" required></div>
  <div class="field"><label>Updated By <span class="req">*</span></label><input class="input" name="updated_by" value="<?= e($user['name']) ?>" required></div>
  <div class="field"><label>Update Date <span class="req">*</span></label><input class="input" name="update_date" type="date" required></div>
  <div class="field" style="grid-column:span 2"><label>Website URL</label><input class="input" name="site_url" type="url" placeholder="https://..."></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2" placeholder="Summary of changes made to the website"></textarea></div>

<?php elseif ($selectedType === 'inst_google_ratings'): ?>
  <?php render_dept_field($user, $departments, 'Institution / Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" placeholder="e.g. Python, C++, Java, Linux" required></div>
  <div class="field"><label>Tutorial Name <span class="req">*</span></label><input class="input" name="tutorial_name" placeholder="e.g. Basic Python for Beginners" required></div>
  <div class="field"><label>Completion Date <span class="req">*</span></label><input class="input" name="completion_date" type="date" required></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>
  <div class="field"><label>Platform</label><input class="input" name="platform" value="IIT-Bombay Spoken Tutorial" readonly style="background:var(--bg-subtle, #f3f4f6); font-weight:600;"></div>
<?php endif; ?>
