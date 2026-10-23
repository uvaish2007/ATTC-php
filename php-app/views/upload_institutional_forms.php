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
    $currSem = $editRecord['semester'] ?? '';
    $existingRowsJson = !empty($editRecord['rows']) ? json_encode($editRecord['rows']) : '[]';
  ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field($editRecord['academic_year'] ?? $effectiveYear, 'academic_year', 'Academic Year', true, 'upercentAcademicYear'); ?>
  <div class="field">
    <label for="upercentExamSession">Exam Session <span class="req">*</span></label>
    <select class="select" id="upercentExamSession" name="exam_session" required onchange="upercentOnSessionOrDeptChange()">
      <option value="" disabled <?= empty($currSession) ? 'selected' : '' ?>>Select Exam Session</option>
      <option value="Apr/May" <?= ($currSession === 'Apr/May' || $currSession === 'Apr-May') ? 'selected' : '' ?>>Apr/May</option>
      <option value="Nov/Dec" <?= ($currSession === 'Nov/Dec' || $currSession === 'Nov-Dec') ? 'selected' : '' ?>>Nov/Dec</option>
    </select>
  </div>
  <div class="field">
    <label for="upercentSemester">Semester <span class="req">*</span></label>
    <select class="select" id="upercentSemester" name="semester" required onchange="upercentOnSemesterChange()">
      <option value="" disabled selected>Select Semester</option>
    </select>
  </div>

  <input type="hidden" name="pass_percentage_rows" id="instPpRowsJson" value="[]">
  <input type="hidden" name="edit_id" id="upercentEditId" value="<?= (int)($editRecord['id'] ?? 0) ?>">
  <datalist id="upercentFacultyDatalist"></datalist>
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

    <div class="table-wrap" style="overflow-x:auto; border:1px solid var(--border,#e2e8f0); border-radius:8px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
      <table class="data" id="upercentTable" style="margin:0; width:100%; min-width:980px; font-size:12.5px;">
        <thead>
          <tr style="background:var(--bg-subtle, #f8fafc); border-bottom:2px solid var(--border,#e2e8f0);">
            <th style="width:45px; text-align:center;">Order</th>
            <th style="width:100px;">Yr <span class="req">*</span></th>
            <th style="width:115px;">Sub Code <span class="req">*</span></th>
            <th style="min-width:170px;">Sub Name <span class="req">*</span></th>
            <th style="min-width:140px;">Faculty Name</th>
            <th style="width:95px;">Faculty Dept</th>
            <th style="width:80px; text-align:right;">Reg <span class="req">*</span></th>
            <th style="width:80px; text-align:right;">Passed <span class="req">*</span></th>
            <th style="width:80px; text-align:right;">Failed</th>
            <th style="width:95px; text-align:right;">Pass %</th>
            <th style="width:40px; text-align:center;"></th>
          </tr>
        </thead>
        <tbody id="upercentTableBody">
          <!-- Populated dynamically based on department duration -->
        </tbody>
      </table>
    </div>

    <!-- Live Calculated Summary Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-top:12px;">
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:10px 12px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--ink-muted,#64748b); font-weight:600;">Total Subjects</div>
        <div id="upercentSumSubjects" style="font-size:18px; font-weight:700; color:var(--ink,#1e293b); margin-top:2px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:10px 12px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--ink-muted,#64748b); font-weight:600;">Registered (Reg)</div>
        <div id="upercentSumReg" style="font-size:18px; font-weight:700; color:var(--ink,#1e293b); margin-top:2px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:10px 12px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:#16a34a; font-weight:600;">Passed</div>
        <div id="upercentSumPassed" style="font-size:18px; font-weight:700; color:#16a34a; margin-top:2px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:10px 12px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:#dc2626; font-weight:600;">Failed</div>
        <div id="upercentSumFailed" style="font-size:18px; font-weight:700; color:#dc2626; margin-top:2px;">0</div>
      </div>
      <div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:10px 12px; text-align:center;">
        <div style="font-size:11px; text-transform:uppercase; color:#FF4F01; font-weight:600;">Overall Pass %</div>
        <div id="upercentSumPct" style="font-size:18px; font-weight:700; color:#FF4F01; margin-top:2px;">0.00%</div>
      </div>
    </div>
  </div>

  <script>
  (function() {
    var initialRows = <?= $existingRowsJson ?>;
    var currentRows = [];
    var currentUser = {
      name: <?= json_encode($user['name'] ?? '') ?>,
      dept: <?= json_encode($user['department'] ?? '') ?>,
      role: <?= json_encode($user['role'] ?? '') ?>
    };

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

    function syncSemesterOptions(preserveVal) {
      var dept = getSelectedDept();
      var session = getSelectedSession();
      var isArch = isArchDept(dept);
      var maxSem = isArch ? 10 : 8;
      var semSelect = document.getElementById('upercentSemester');
      if (!semSelect) return;

      var currentVal = preserveVal || semSelect.value;
      semSelect.innerHTML = '<option value="" disabled>Select Semester</option>';

      var isOddSession = (session === 'Nov/Dec' || session === 'Nov-Dec');
      var isEvenSession = (session === 'Apr/May' || session === 'Apr-May');

      var primarySems = [];
      var alternateSems = [];

      for (var s = 1; s <= maxSem; s++) {
        var semLabel = 'Sem ' + s;
        if (isOddSession) {
          if (s % 2 !== 0) primarySems.push(semLabel);
          else alternateSems.push(semLabel);
        } else if (isEvenSession) {
          if (s % 2 === 0) primarySems.push(semLabel);
          else alternateSems.push(semLabel);
        } else {
          primarySems.push(semLabel);
        }
      }

      if (session !== '') {
        var grpPrimary = document.createElement('optgroup');
        grpPrimary.label = (isOddSession ? 'Odd Semesters (' + session + ')' : 'Even Semesters (' + session + ')');
        primarySems.forEach(function(sm) {
          var opt = document.createElement('option');
          opt.value = sm;
          opt.textContent = sm;
          grpPrimary.appendChild(opt);
        });
        semSelect.appendChild(grpPrimary);

        if (alternateSems.length > 0) {
          var grpAlt = document.createElement('optgroup');
          grpAlt.label = 'Other Semesters (Supplementary)';
          alternateSems.forEach(function(sm) {
            var opt = document.createElement('option');
            opt.value = sm;
            opt.textContent = sm;
            grpAlt.appendChild(opt);
          });
          semSelect.appendChild(grpAlt);
        }
      } else {
        for (var s = 1; s <= maxSem; s++) {
          var opt = document.createElement('option');
          opt.value = 'Sem ' + s;
          opt.textContent = 'Sem ' + s;
          semSelect.appendChild(opt);
        }
      }

      if (currentVal && semSelect.querySelector('option[value="' + currentVal + '"]')) {
        semSelect.value = currentVal;
      } else if (primarySems.length > 0) {
        semSelect.value = primarySems[0];
      }
    }

    function getDefaultYearRows(dept) {
      var isArch = isArchDept(dept);
      var years = isArch
        ? ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']
        : ['1st Year', '2nd Year', '3rd Year', '4th Year'];

      var defaultFac = (currentUser.role === 'Faculty' && currentUser.name) ? currentUser.name : '';
      var defaultDept = currentUser.dept || dept;

      return years.map(function(yr, idx) {
        return {
          class_year: yr,
          subject_code: '',
          subject_name: '',
          faculty_name: defaultFac,
          faculty_dept: defaultDept,
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

      currentRows.forEach(function(row, idx) {
        var tr = document.createElement('tr');
        var reg = parseInt(row.total_members, 10);
        var passed = parseInt(row.passed_members, 10);
        var regVal = isNaN(reg) ? 0 : Math.max(0, reg);
        var passedVal = isNaN(passed) ? 0 : Math.max(0, passed);

        var failedVal = Math.max(0, regVal - passedVal);
        row.failed_members = failedVal;

        var pctVal = (regVal > 0) ? ((passedVal / regVal) * 100).toFixed(2) : '0.00';
        row.pass_percentage = pctVal;

        var isExceed = (!isNaN(reg) && !isNaN(passed) && passed > reg);
        var inputStyle = 'font-size:12px; padding:5px 8px; width:100%;';
        var numStyle = inputStyle + 'text-align:right;' + (isExceed ? 'border-color:#ef4444; background:#fef2f2;' : '');

        var yrOptions = isArch
          ? ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']
          : ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        var yrSelectHtml = '<select class="select" style="font-size:12px; padding:4px 6px;" onchange="upercentSet(' + idx + ',\'class_year\',this.value)">';
        yrOptions.forEach(function(y) {
          yrSelectHtml += '<option value="' + y + '"' + (row.class_year === y ? ' selected' : '') + '>' + y + '</option>';
        });
        yrSelectHtml += '</select>';

        tr.innerHTML =
          '<td style="text-align:center; color:#64748b; font-weight:600;">' + (idx + 1) + '</td>' +
          '<td>' + yrSelectHtml + '</td>' +
          '<td><input class="input" style="' + inputStyle + '" list="upercentSubjectDatalist" value="' + h(row.subject_code) + '" placeholder="e.g. CS101" required oninput="upercentSet(' + idx + ',\'subject_code\',this.value)"></td>' +
          '<td><input class="input" style="' + inputStyle + '" value="' + h(row.subject_name) + '" placeholder="Subject Title" required oninput="upercentSet(' + idx + ',\'subject_name\',this.value)"></td>' +
          '<td><input class="input" style="' + inputStyle + '" list="upercentFacultyDatalist" value="' + h(row.faculty_name) + '" placeholder="Faculty Name" oninput="upercentSet(' + idx + ',\'faculty_name\',this.value)"></td>' +
          '<td><input class="input" style="' + inputStyle + '" value="' + h(row.faculty_dept || dept) + '" placeholder="Dept" oninput="upercentSet(' + idx + ',\'faculty_dept\',this.value)"></td>' +
          '<td><input class="input" style="' + numStyle + '" type="number" min="0" value="' + (row.total_members !== '' && row.total_members !== null ? h(row.total_members) : '') + '" placeholder="0" required oninput="upercentSet(' + idx + ',\'total_members\',this.value)"></td>' +
          '<td><input class="input" style="' + numStyle + '" type="number" min="0" value="' + (row.passed_members !== '' && row.passed_members !== null ? h(row.passed_members) : '') + '" placeholder="0" required oninput="upercentSet(' + idx + ',\'passed_members\',this.value)" title="' + (isExceed ? 'Passed cannot exceed Registered' : '') + '"></td>' +
          '<td><input class="input" style="' + inputStyle + 'text-align:right; background:var(--bg-subtle,#f3f4f6); cursor:not-allowed;" type="number" readonly value="' + failedVal + '"></td>' +
          '<td style="text-align:right; font-weight:700; color:' + (parseFloat(pctVal) >= 75 ? '#16a34a' : (parseFloat(pctVal) >= 50 ? '#d97706' : '#dc2626')) + ';">' + pctVal + '%</td>' +
          '<td style="text-align:center;">' +
            (currentRows.length > (isArch ? 5 : 4)
              ? '<button type="button" class="btn btn-ghost btn-sm" style="color:#ef4444; padding:2px 6px;" onclick="upercentDeleteRow(' + idx + ')" title="Remove Row">&#x2715;</button>'
              : '<span style="color:#cbd5e1;">—</span>') +
          '</td>';

        tbody.appendChild(tr);
      });

      updateSummary();
      var jsonEl = document.getElementById('instPpRowsJson');
      if (jsonEl) jsonEl.value = JSON.stringify(currentRows);
    }

    function updateSummary() {
      var tSubj = currentRows.length;
      var sumReg = 0;
      var sumPassed = 0;
      var sumFailed = 0;

      currentRows.forEach(function(r) {
        var reg = parseInt(r.total_members, 10);
        var passed = parseInt(r.passed_members, 10);
        if (!isNaN(reg) && reg > 0) sumReg += reg;
        if (!isNaN(passed) && passed > 0) sumPassed += passed;
      });

      sumFailed = Math.max(0, sumReg - sumPassed);
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

    window.upercentSet = function(idx, key, val) {
      if (currentRows[idx]) {
        currentRows[idx][key] = val;
        renderTable();
      }
    };

    window.upercentDeleteRow = function(idx) {
      if (currentRows[idx]) {
        currentRows.splice(idx, 1);
        renderTable();
      }
    };

    window.upercentAddSubjectRow = function() {
      var dept = getSelectedDept();
      var isArch = isArchDept(dept);
      var defaultYr = '1st Year';
      if (currentRows.length > 0) {
        defaultYr = currentRows[currentRows.length - 1].class_year || '1st Year';
      }

      currentRows.push({
        class_year: defaultYr,
        subject_code: '',
        subject_name: '',
        faculty_name: (currentUser.role === 'Faculty' && currentUser.name) ? currentUser.name : '',
        faculty_dept: currentUser.dept || dept,
        total_members: '',
        passed_members: '',
        failed_members: 0,
        pass_percentage: '0.00',
        sort_order: currentRows.length + 1
      });
      renderTable();
    };

    window.upercentResetDefaultRows = function() {
      var dept = getSelectedDept();
      currentRows = getDefaultYearRows(dept);
      renderTable();
    };

    window.upercentOnSessionOrDeptChange = function() {
      syncSemesterOptions();
      upercentFetchExistingData();
    };

    window.upercentOnSemesterChange = function() {
      upercentFetchExistingData();
    };

    function populateDatalists(facultyList, subjectList) {
      var fDl = document.getElementById('upercentFacultyDatalist');
      if (fDl && Array.isArray(facultyList)) {
        fDl.innerHTML = '';
        facultyList.forEach(function(fn) {
          var opt = document.createElement('option');
          opt.value = fn;
          fDl.appendChild(opt);
        });
      }
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

      fetch('/upload.php?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!data || !data.success) return;

        if (data.faculty || data.subjects) {
          populateDatalists(data.faculty || [], data.subjects || []);
        }

        var editIdEl = document.getElementById('upercentEditId');
        if (data.found && Array.isArray(data.rows) && data.rows.length > 0) {
          currentRows = data.rows.map(function(r, idx) {
            return {
              class_year: r.class_year || ('Year ' + (idx + 1)),
              subject_code: r.subject_code || '',
              subject_name: r.subject_name || '',
              faculty_name: r.faculty_name || '',
              faculty_dept: r.faculty_dept || dept,
              total_members: r.total_members !== null ? r.total_members : '',
              passed_members: r.passed_members !== null ? r.passed_members : '',
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

    document.addEventListener('DOMContentLoaded', function() {
      var dept = getSelectedDept();
      syncSemesterOptions(<?= json_encode($currSem) ?>);

      var deptEl = document.querySelector('select[name="department"]');
      if (deptEl) {
        deptEl.addEventListener('change', function() {
          upercentOnSessionOrDeptChange();
        });
      }

      if (Array.isArray(initialRows) && initialRows.length > 0) {
        currentRows = initialRows.map(function(r, idx) {
          return {
            class_year: r.class_year || ('Year ' + (idx + 1)),
            subject_code: r.subject_code || '',
            subject_name: r.subject_name || '',
            faculty_name: r.faculty_name || '',
            faculty_dept: r.faculty_dept || dept,
            total_members: r.total_members !== null ? r.total_members : '',
            passed_members: r.passed_members !== null ? r.passed_members : '',
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
    });
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
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
  <?php render_exam_session_field($effectiveYear); ?>
  <div class="field"><label>Professional Society <span class="req">*</span></label><input class="input" name="society_name" placeholder="e.g. IEEE, ACM, CSI, ISTE, ASME" required></div>
  <div class="field"><label>Membership Number <span class="req">*</span></label><input class="input" name="membership_number" required></div>
  <div class="field"><label>Membership Type</label><input class="input" name="membership_type" placeholder="Life Member / Annual / Fellow"></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>Expiry Date</label><input class="input" name="expiry_date" type="date"></div>
  <div class="field"><label>Status</label><input class="input" name="membership_status" placeholder="Active / Permanent"></div>

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

<?php elseif ($selectedType === 'inst_sports_state'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php render_academic_year_field(); ?>
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
  <?php render_academic_year_field(); ?>
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
