<?php
/**
 * Institutional / Department Achievement Forms
 * Included from upload.php at the point where $selectedType is known.
 * Called AFTER the standard elseif chain's closing endif.
 */

// Only render if the selected type is an inst_ type
if (!str_starts_with($selectedType ?? '', 'inst_')) return;

$iYear = $effectiveYear ?? $activeYear ?? '';
$iName = $user['name'] ?? '';

// Helper: editable academic year field
if (!function_exists('inst_yr')) {
function inst_yr(string $yr): void {
    global $editRecord, $effectiveYear;
    $selected = $editRecord['academic_year'] ?? $yr ?: ($effectiveYear ?? null);
    render_academic_year_field($selected);
}
}
// Helper: select dropdown
if (!function_exists('inst_sel')) {
function inst_sel(string $name, array $opts, bool $req = false): void {
    $r = $req ? ' required' : '';
    echo '<select class="select" name="' . $name . '"' . $r . '>';
    foreach ($opts as $o) {
        echo '<option>' . htmlspecialchars($o, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
}
}
// Helper: field div
if (!function_exists('inst_field')) {
function inst_field(string $label, string $content, bool $wide = false, bool $req = false): void {
    $span = $wide ? ' style="grid-column:span 2"' : '';
    $star = $req ? ' <span class="req">*</span>' : '';
    echo '<div class="field"' . $span . '><label>' . $label . $star . '</label>' . $content . '</div>';
}
}
if (!function_exists('inst_input')) {
function inst_input(string $name, string $placeholder = '', string $type = 'text', bool $req = false, string $extra = ''): string {
    $r  = $req ? ' required' : '';
    $ph = $placeholder ? ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"' : '';
    if ($type === 'date' && !str_contains($extra, 'max=') && !in_array($name, ['valid_upto', 'expiry_date', 'planned_end_date', 'project_end_date'], true)) {
        $today = function_exists('app_ist_today') ? app_ist_today() : date('Y-m-d');
        $extra .= ' max="' . $today . '"';
    }
    return '<input class="input" name="' . $name . '" type="' . $type . '"' . $ph . $r . ' ' . $extra . '>';
}
}
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
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Programme</label><input class="input" name="programme" placeholder="e.g. B.E. CSE"></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Rank <span class="req">*</span></label><input class="input" name="rank_val" type="number" min="1" placeholder="e.g. 1" required></div>
  <div class="field"><label>Rank Type</label><input class="input" name="rank_type" placeholder="e.g. Gold Medal, College Rank"></div>
  <div class="field"><label>University</label><input class="input" name="university" value="Anna University"></div>
  <div class="field"><label>Year / Semester</label><input class="input" name="year_semester" placeholder="e.g. IV Year / Sem 8"></div>
  <div class="field"><label>Achievement Date</label><input class="input" name="achievement_date" type="date"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_student_rank'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Programme</label><input class="input" name="programme" placeholder="e.g. B.E. ECE"></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>University Rank <span class="req">*</span></label><input class="input" name="university_rank" placeholder="e.g. 3rd Rank" required></div>
  <div class="field"><label>Class Year</label><input class="input" name="class_year" placeholder="e.g. IV Year"></div>
  <div class="field"><label>Semester</label><input class="input" name="semester" placeholder="e.g. Sem 7"></div>
  <div class="field"><label>Category</label><input class="input" name="category" placeholder="e.g. State, University"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_student_cgpa'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Programme</label><input class="input" name="programme" placeholder="e.g. B.E. Mech"></div>
  <div class="field"><label>Class / Year <span class="req">*</span></label><select class="select" name="class_year" required><option>II Year</option><option>III Year</option></select></div>
  <div class="field"><label>Semester <span class="req">*</span></label><select class="select" name="semester" required><option>Sem 3</option><option>Sem 4</option><option>Sem 5</option><option>Sem 6</option></select></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>CGPA <span class="req">*</span></label><input class="input" name="cgpa" type="number" step="0.01" min="0" max="10" required oninput="syncCgpa(this.value)"></div>
  <div class="field"><label>Eligibility</label><select class="select" name="eligibility" id="cgpaElig"><option value="Eligible (Above 7.5)">Eligible (Above 7.5)</option><option value="Not Eligible (7.5 or Below)">Not Eligible (7.5 or Below)</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>
  <script>function syncCgpa(v){var s=document.getElementById('cgpaElig');if(s)s.value=parseFloat(v)>7.5?'Eligible (Above 7.5)':'Not Eligible (7.5 or Below)';}</script>

<?php elseif ($selectedType === 'inst_placement_mnc'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Batch <span class="req">*</span></label><input class="input" name="batch" value="<?= e($iYear) ?>" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Company Name <span class="req">*</span></label><input class="input" name="company_name" required></div>
  <div class="field"><label>Company Type</label><select class="select" name="company_type"><option>MNC</option><option>Product-Based</option><option>Service-Based</option><option>Core Company</option><option>Start-up</option><option>PSU</option><option>Other</option></select></div>
  <div class="field"><label>Placement Status <span class="req">*</span></label><select class="select" name="placement_status" required><option>Placed</option><option>Offer Received</option><option>Internship to Full-time</option></select></div>
  <div class="field"><label>Package / CTC</label><input class="input" name="package_ctc" placeholder="e.g. 6.5 LPA"></div>
  <div class="field"><label>Placement Date</label><input class="input" name="placement_date" type="date"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_publications'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Author(s)</label><input class="input" name="authors" placeholder="Comma-separated"></div>
  <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Journal / Publication Name <span class="req">*</span></label><input class="input" name="journal_name" required></div>
  <div class="field"><label>Publication Type <span class="req">*</span></label><select class="select" name="publication_type" required><option>Scopus</option><option>SCI</option><option>Springer</option><option>UGC CARE</option><option>H-Index</option><option>Web of Science</option><option>Peer Reviewed</option><option>Other</option></select></div>
  <div class="field"><label>DOI / URL</label><input class="input" name="doi_url" type="url" placeholder="https://doi.org/..."></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>H-Index Value</label><input class="input" name="h_index" placeholder="e.g. 5"></div>
  <div class="field"><label>Volume / Issue</label><input class="input" name="volume_issue" placeholder="e.g. Vol.12, Issue 3"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_books'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Book Title <span class="req">*</span></label><input class="input" name="book_title" required></div>
  <div class="field"><label>Author(s)</label><input class="input" name="authors" placeholder="Comma-separated"></div>
  <div class="field"><label>Publisher <span class="req">*</span></label><input class="input" name="publisher" required></div>
  <div class="field"><label>ISBN <span class="req">*</span></label><input class="input" name="isbn" required></div>
  <div class="field"><label>Edition</label><input class="input" name="edition" placeholder="e.g. 2nd"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>URL</label><input class="input" name="book_url" type="url" placeholder="https://..."></div>

<?php elseif ($selectedType === 'inst_book_chapters'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Chapter Title <span class="req">*</span></label><input class="input" name="chapter_title" required></div>
  <div class="field" style="grid-column:span 2"><label>Book Title <span class="req">*</span></label><input class="input" name="book_title" required></div>
  <div class="field"><label>Author(s)</label><input class="input" name="authors" placeholder="Comma-separated"></div>
  <div class="field"><label>Publisher <span class="req">*</span></label><input class="input" name="publisher" required></div>
  <div class="field"><label>ISBN <span class="req">*</span></label><input class="input" name="isbn" required></div>
  <div class="field"><label>Chapter Number</label><input class="input" name="chapter_number" placeholder="e.g. 4"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>URL</label><input class="input" name="chapter_url" type="url" placeholder="https://..."></div>

<?php elseif ($selectedType === 'inst_patents_published'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Inventor(s)</label><input class="input" name="inventors" placeholder="Comma-separated"></div>
  <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Patent / Design Number <span class="req">*</span></label><input class="input" name="patent_number" required></div>
  <div class="field"><label>Type <span class="req">*</span></label><select class="select" name="patent_type" required><option>Patent</option><option>Design Patent</option><option>Utility Patent</option><option>Plant Patent</option></select></div>
  <div class="field"><label>Application Date</label><input class="input" name="application_date" type="date"></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Status</label><select class="select" name="patent_status"><option>Published</option><option>Under Review</option><option>Granted</option><option>Rejected</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_patents_granted'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Inventor(s)</label><input class="input" name="inventors" placeholder="Comma-separated"></div>
  <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Patent Number <span class="req">*</span></label><input class="input" name="patent_number" required></div>
  <div class="field"><label>Grant Date <span class="req">*</span></label><input class="input" name="grant_date" type="date" required></div>
  <div class="field"><label>Granting Authority <span class="req">*</span></label><input class="input" name="granting_authority" placeholder="e.g. Indian Patent Office" required></div>
  <div class="field"><label>Status</label><input class="input" name="patent_status" value="Granted"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_copyrights'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Title / Work <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Copyright Number <span class="req">*</span></label><input class="input" name="copyright_number" required></div>
  <div class="field"><label>Registration Date <span class="req">*</span></label><input class="input" name="registration_date" type="date" required></div>
  <div class="field"><label>Category</label><select class="select" name="category"><option>Literary Work</option><option>Software</option><option>Artistic Work</option><option>Musical Work</option><option>Other</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_sponsored_research'): ?>
  <div class="field"><label>Faculty Name / PI <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Project Title <span class="req">*</span></label><input class="input" name="project_title" required></div>
  <div class="field"><label>Funding Agency <span class="req">*</span></label><input class="input" name="funding_agency" required></div>
  <div class="field"><label>Project Amount (Lakhs) <span class="req">*</span></label><input class="input" name="project_amount" type="number" step="0.01" min="0" placeholder="e.g. 5.50" required></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>End Date</label><input class="input" name="end_date" type="date"></div>
  <div class="field"><label>Project Number</label><input class="input" name="project_number"></div>
  <div class="field"><label>Status</label><select class="select" name="project_status"><option>Ongoing</option><option>Completed</option><option>Submitted</option><option>Approved</option></select></div>

<?php elseif ($selectedType === 'inst_consultancy'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Project Title <span class="req">*</span></label><input class="input" name="project_title" required></div>
  <div class="field"><label>Client / Organization <span class="req">*</span></label><input class="input" name="client_org" required></div>
  <div class="field"><label>Project Amount (Lakhs) <span class="req">*</span></label><input class="input" name="project_amount" type="number" step="0.01" min="0" placeholder="e.g. 2.00" required></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>End Date</label><input class="input" name="end_date" type="date"></div>
  <div class="field"><label>Project Number</label><input class="input" name="project_number"></div>
  <div class="field"><label>Status</label><select class="select" name="project_status"><option>Ongoing</option><option>Completed</option><option>Submitted</option></select></div>

<?php elseif ($selectedType === 'inst_research_centre'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Recognition Name <span class="req">*</span></label><input class="input" name="recognition_name" required></div>
  <div class="field"><label>Recognizing Authority <span class="req">*</span></label><input class="input" name="recognizing_authority" value="Anna University" required></div>
  <div class="field"><label>Recognition Date</label><input class="input" name="recognition_date" type="date"></div>
  <div class="field"><label>Reference Number</label><input class="input" name="reference_number"></div>
  <div class="field"><label>Status</label><select class="select" name="rec_status"><option>Recognized</option><option>Applied</option><option>Under Review</option><option>Renewed</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_ipr_programmes'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Programme Type <span class="req">*</span></label><select class="select" name="programme_type" required><option>IPR</option><option>Higher Studies</option><option>Entrepreneurship</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Programme Title <span class="req">*</span></label><input class="input" name="programme_title" required></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Organizer</label><input class="input" name="organizer"></div>
  <div class="field"><label>Target Audience</label><input class="input" name="target_audience" placeholder="e.g. Faculty, Students"></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="1"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_faculty_certifications'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Platform / Provider <span class="req">*</span></label><input class="input" name="platform" placeholder="e.g. Coursera, NPTEL, edX" required></div>
  <div class="field"><label>Certification Name</label><input class="input" name="certification_name"></div>
  <div class="field"><label>Completion Date</label><input class="input" name="completion_date" type="date"></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>
  <div class="field"><label>Status</label><select class="select" name="cert_status"><option>Completed</option><option>In Progress</option></select></div>

<?php elseif ($selectedType === 'inst_mou_interactions'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Industry Name <span class="req">*</span></label><input class="input" name="industry_name" required></div>
  <div class="field"><label>Type <span class="req">*</span></label><select class="select" name="interaction_type" required><option>Industry Interaction</option><option>MOU</option><option>Industry Supported Lab</option></select></div>
  <div class="field"><label>MOU Number</label><input class="input" name="mou_number" placeholder="If applicable"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Validity</label><input class="input" name="validity" placeholder="e.g. 3 years"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><input class="input" name="outcome"></div>

<?php elseif ($selectedType === 'inst_internships'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Company / Industry <span class="req">*</span></label><input class="input" name="company" required></div>
  <div class="field"><label>Internship Type</label><select class="select" name="internship_type"><option>In-Plant Training</option><option>Industrial Training</option><option>Research Internship</option><option>Other</option></select></div>
  <div class="field"><label>Start Date <span class="req">*</span></label><input class="input" name="start_date" type="date" required id="intS"></div>
  <div class="field"><label>End Date <span class="req">*</span></label><input class="input" name="end_date" type="date" required id="intE" oninput="calcInt()"></div>
  <div class="field"><label>Duration (Weeks) <span class="card-sub">auto</span></label><input class="input" name="duration_weeks" id="intD" readonly style="background:var(--bg-subtle,#f3f4f6)"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <script>function calcInt(){var s=new Date(document.getElementById('intS').value),e=new Date(document.getElementById('intE').value),d=document.getElementById('intD');if(isNaN(s)||isNaN(e))return;var w=((e-s)/864e5/7).toFixed(1);d.value=w;d.style.color=parseFloat(w)>=4?'var(--success,#22c55e)':'var(--danger,#ef4444)';}</script>

<?php elseif ($selectedType === 'inst_summer_trainings'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Organization <span class="req">*</span></label><input class="input" name="organization" required></div>
  <div class="field"><label>Training Title</label><input class="input" name="training_title"></div>
  <div class="field"><label>Start Date <span class="req">*</span></label><input class="input" name="start_date" type="date" required id="sumS"></div>
  <div class="field"><label>End Date <span class="req">*</span></label><input class="input" name="end_date" type="date" required id="sumE" oninput="calcSum()"></div>
  <div class="field"><label>Duration (Weeks) <span class="card-sub">auto</span></label><input class="input" name="duration_weeks" id="sumD" readonly style="background:var(--bg-subtle,#f3f4f6)"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <script>function calcSum(){var s=new Date(document.getElementById('sumS').value),e=new Date(document.getElementById('sumE').value),d=document.getElementById('sumD');if(isNaN(s)||isNaN(e))return;var w=((e-s)/864e5/7).toFixed(1);d.value=w;d.style.color=parseFloat(w)<4?'var(--success,#22c55e)':'var(--danger,#ef4444)';}</script>

<?php elseif ($selectedType === 'inst_student_projects'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field" style="grid-column:span 2"><label>Project Title <span class="req">*</span></label><input class="input" name="project_title" required></div>
  <div class="field"><label>Project Guide</label><input class="input" name="project_guide"></div>
  <div class="field"><label>Project Category</label><input class="input" name="project_category" placeholder="e.g. Final Year, Mini Project"></div>
  <div class="field" style="grid-column:span 2"><label>YouTube URL <span class="req">*</span></label><input class="input" name="youtube_url" type="url" placeholder="https://www.youtube.com/..." required></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Views (if available)</label><input class="input" name="views" type="number" min="0"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_faculty_participations'): ?>
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Programme Type <span class="req">*</span></label><select class="select" name="programme_type" required><option>FDP</option><option>Training</option><option>STTP</option><option>Conference</option><option>Workshop</option><option>Seminar</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Programme Title <span class="req">*</span></label><input class="input" name="programme_title" required></div>
  <div class="field"><label>Organizer</label><input class="input" name="organizer"></div>
  <div class="field"><label>Location / Mode</label><input class="input" name="location" placeholder="Online / Venue Name"></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>End Date</label><input class="input" name="end_date" type="date"></div>
  <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 5 days"></div>
  <div class="field"><label>Certificate Number</label><input class="input" name="certificate_id"></div>

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
        <input class="input" id="socInputFacultyName" name="faculty_name" value="<?= e($editRecord['faculty_name'] ?? $iName) ?>" placeholder="Faculty member full name" <?= $socCategory === 'Faculty' ? 'required' : '' ?>>
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
    <?php inst_yr($iYear); ?>

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
      <input class="input" name="expiry_date" type="date" value="<?= e($editRecord['expiry_date'] ?? ($editRecord['end_date'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Status</label>
      <select class="select" name="membership_status">
        <option value="Active" <?= ($editRecord['membership_status'] ?? ($editRecord['mem_status'] ?? '')) === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Permanent" <?= ($editRecord['membership_status'] ?? ($editRecord['mem_status'] ?? '')) === 'Permanent' ? 'selected' : '' ?>>Permanent</option>
        <option value="Expired" <?= ($editRecord['membership_status'] ?? ($editRecord['mem_status'] ?? '')) === 'Expired' ? 'selected' : '' ?>>Expired</option>
        <option value="Renewed" <?= ($editRecord['membership_status'] ?? ($editRecord['mem_status'] ?? '')) === 'Renewed' ? 'selected' : '' ?>>Renewed</option>
      </select>
    </div>
  </div>
  <!-- End Individual Fields Container -->

  <!-- Bulk Upload Container (Integrated within this SAME form card, shown when mode is Bulk Upload) -->
  <div id="socBulkUploadPanel" style="grid-column: 1 / -1; display: <?= $isSocBulk ? 'block' : 'none' ?>; margin-top: 4px;">
    <!-- Step 1 & 2 Cards Grid -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px; margin-bottom:16px;">
      <!-- Step 1: Download Template -->
      <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px; display:flex; flex-direction:column; justify-content:space-between;">
        <div>
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
            <span style="background:#131D3B; color:#FFFFFF; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">1</span>
            <span style="font-size:13px; font-weight:700; color:#131D3B;">Download Category Template</span>
          </div>
          <p id="socTemplateDescription" style="font-size:12px; color:#64748B; margin-bottom:12px; line-height:1.45;">
            <?= $socCategory === 'Student' 
              ? 'Download the official Student Membership Excel spreadsheet. Includes columns for Register Number, Student Name, Department, Academic Year, Class/Year, Society, Membership No, Type, Dates, Status, and Certificate Link.'
              : 'Download the official Faculty Membership Excel spreadsheet. Includes columns for Faculty Identifier/Name, Department, Academic Year, Society, Membership No, Type, Dates, Status, and Certificate Link.' ?>
          </p>
        </div>
        <div>
          <a id="socBtnDownloadTemplate" href="<?= e(url('bulk-import.php?action=template&module=' . ($socCategory === 'Student' ? 'society_student' : 'society_faculty'))) ?>" class="btn btn-outline" style="border-color:#FF4F01; color:#FF4F01; font-weight:700; font-size:12.5px; width:100%; justify-content:center;">
            <?= icon('download', 14) ?> <span id="socBtnDownloadText">Download <?= $socCategory ?> Membership Template (.xlsx)</span>
          </a>
        </div>
      </div>

      <!-- Step 2: Upload Spreadsheet -->
      <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px;">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
          <span style="background:#FF4F01; color:#FFFFFF; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">2</span>
          <span style="font-size:13px; font-weight:700; color:#131D3B;">Upload &amp; Validate Spreadsheet</span>
        </div>
        <p style="font-size:12px; color:#64748B; margin-bottom:10px;">Select your filled Excel (.xlsx) file to run row-by-row validation against ATTS rules.</p>

        <!-- Dropzone -->
        <div id="socBulkDropzone" onclick="document.getElementById('socBulkExcelInput').click()" style="border:2px dashed #CBD5E1; border-radius:8px; background:#FFFFFF; padding:16px; text-align:center; cursor:pointer; transition:all 0.2s;">
          <input type="file" id="socBulkExcelInput" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleSocBulkFileSelected(this)">
          <div style="color:#FF4F01; margin-bottom:4px;"><?= icon('upload', 24) ?></div>
          <div id="socBulkDropzoneText" style="font-size:12.5px; font-weight:600; color:#1E293B;">Click or Drag &amp; Drop Excel file here</div>
          <div style="font-size:11px; color:#94A3B8; margin-top:2px;">Supports .xlsx, .xls, .csv strictly up to 10 MB</div>
        </div>

        <div id="socBulkSelectedFileInfo" style="display:none; margin-top:10px; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:6px; padding:8px 12px; align-items:center; justify-content:space-between;">
          <div style="display:flex; align-items:center; gap:6px; overflow:hidden;">
            <span style="color:#2563EB;"><?= icon('file-text', 14) ?></span>
            <span id="socBulkFileName" style="font-size:12px; font-weight:600; color:#1E40AF; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;"></span>
            <span id="socBulkFileSize" style="font-size:11px; color:#64748B;"></span>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="clearSocBulkFile(event)" style="padding:2px 6px; font-size:11px; color:#DC2626;">Remove</button>
        </div>

        <button type="button" id="socBtnValidatePreview" class="btn btn-primary" onclick="submitSocBulkPreview()" style="width:100%; margin-top:12px; justify-content:center; font-weight:700;" disabled>
          <?= icon('check', 14) ?> Validate &amp; Preview
        </button>
      </div>
    </div>

    <!-- Bulk Processing Spinner -->
    <div id="socBulkSpinner" style="display:none; text-align:center; padding:28px 16px; background:#FFFFFF; border:1px solid #E2E8F0; border-radius:12px; margin-bottom:16px;">
      <div style="display:inline-block; width:36px; height:36px; border:3px solid #E2E8F0; border-top-color:#FF4F01; border-radius:50%; animation:spin 0.8s linear infinite;"></div>
      <div style="font-size:14px; font-weight:700; color:#131D3B; margin-top:12px;">Validating Spreadsheet Records...</div>
      <div style="font-size:12px; color:#64748B; margin-top:4px;">Checking headers, required fields, date formats, and duplicates against database</div>
    </div>

    <!-- Bulk Alert Message -->
    <div id="socBulkAlert" style="display:none; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:12.5px;"></div>

    <!-- Bulk Preview & Results Section -->
    <div id="socBulkPreviewSection" style="display:none; background:#FFFFFF; border:1px solid #E2E8F0; border-radius:12px; padding:18px; margin-bottom:16px;">
      <!-- Metrics Bar -->
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-bottom:16px;">
        <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:10px; text-align:center;">
          <div id="socMetricTotal" style="font-size:20px; font-weight:800; color:#131D3B;">0</div>
          <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748B;">Total Rows</div>
        </div>
        <div style="background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; padding:10px; text-align:center;">
          <div id="socMetricValid" style="font-size:20px; font-weight:800; color:#15803D;">0</div>
          <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#166534;">Valid Rows</div>
        </div>
        <div style="background:#FFF7ED; border:1px solid #FED7AA; border-radius:8px; padding:10px; text-align:center;">
          <div id="socMetricDuplicate" style="font-size:20px; font-weight:800; color:#C2410C;">0</div>
          <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#9A3412;">Duplicates</div>
        </div>
        <div style="background:#FEF2F2; border:1px solid #FECACA; border-radius:8px; padding:10px; text-align:center;">
          <div id="socMetricInvalid" style="font-size:20px; font-weight:800; color:#DC2626;">0</div>
          <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#991B1B;">Invalid / Errors</div>
        </div>
      </div>

      <!-- Errors Box if any -->
      <div id="socBulkErrorsBox" style="display:none; background:#FEF2F2; border:1px solid #FECACA; border-radius:8px; padding:12px; margin-bottom:16px;">
        <div style="font-size:12.5px; font-weight:700; color:#991B1B; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
          <?= icon('alert-triangle', 14) ?> Row-Level Issues Detected (<span id="socBulkErrorCount">0</span>)
        </div>
        <div style="max-height:160px; overflow-y:auto; border:1px solid #FCA5A5; border-radius:6px; background:#FFFFFF;">
          <table style="width:100%; border-collapse:collapse; font-size:11.5px; text-align:left;">
            <thead><tr style="background:#FEE2E2; color:#991B1B;"><th style="padding:4px 8px;">Row</th><th style="padding:4px 8px;">Field</th><th style="padding:4px 8px;">Problem</th><th style="padding:4px 8px;">Value</th></tr></thead>
            <tbody id="socBulkErrorsTableBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Preview Table Controls: Search & Tabs -->
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
        <div style="display:inline-flex; background:#F1F5F9; padding:2px; border-radius:6px; gap:2px; font-size:11.5px;">
          <button type="button" class="btn-mode-toggle active" id="socTabAll" onclick="filterSocPreviewTable('all')" style="padding:4px 10px; font-size:11.5px;">All (<span id="socTabCntAll">0</span>)</button>
          <button type="button" class="btn-mode-toggle" id="socTabValid" onclick="filterSocPreviewTable('valid')" style="padding:4px 10px; font-size:11.5px; color:#15803D;">Valid (<span id="socTabCntValid">0</span>)</button>
          <button type="button" class="btn-mode-toggle" id="socTabDup" onclick="filterSocPreviewTable('duplicate')" style="padding:4px 10px; font-size:11.5px; color:#C2410C;">Duplicates (<span id="socTabCntDup">0</span>)</button>
          <button type="button" class="btn-mode-toggle" id="socTabErr" onclick="filterSocPreviewTable('invalid')" style="padding:4px 10px; font-size:11.5px; color:#DC2626;">Invalid (<span id="socTabCntErr">0</span>)</button>
        </div>
        <input type="text" id="socPreviewSearchInput" oninput="searchSocPreviewTable(this.value)" placeholder="Search preview rows..." style="padding:5px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; width:180px;">
      </div>

      <!-- Preview Table -->
      <div style="overflow-x:auto; border:1px solid #E2E8F0; border-radius:8px; max-height:280px; margin-bottom:16px;">
        <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;" id="socPreviewTable">
          <thead id="socPreviewTableHead"></thead>
          <tbody id="socPreviewTableBody"></tbody>
        </table>
      </div>

      <!-- Final Action Bar -->
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <button type="button" class="btn btn-outline" onclick="resetSocBulkUpload()" style="font-size:12.5px;">
          <?= icon('arrow-left', 13) ?> Choose Different File
        </button>
        <button type="button" id="socBtnConfirmImport" class="btn btn-primary" onclick="confirmSocBulkImport()" style="background:#16A34A; border-color:#16A34A; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
          <?= icon('check', 15) ?> Confirm &amp; Import <span id="socConfirmCount">0</span> Valid Records
        </button>
      </div>
    </div>
  </div>
  <!-- End Bulk Upload Container -->

  <script>
  (function() {
    var defaultFacultyName = <?= json_encode($iName) ?>;
    var currentCategory = <?= json_encode($socCategory) ?>;
    var currentMode = <?= json_encode($isSocBulk ? 'bulk' : 'individual') ?>;
    var lastSelectedCategory = currentCategory;
    var bulkPreviewData = null;
    var activePreviewFilter = 'all';
    var previewSearchQuery = '';

    window.setSocietyInputMode = function(mode) {
      currentMode = mode;
      var indGroup = document.getElementById('socIndividualFieldsGroup');
      var bulkPanel = document.getElementById('socBulkUploadPanel');
      var btnInd = document.getElementById('socBtnModeInd');
      var btnBulk = document.getElementById('socBtnModeBulk');
      var helpText = document.getElementById('socModeHelpText');
      var proofCont = document.querySelector('.atts-proof-container');
      var uploadActions = document.querySelector('.upload-actions');

      if (mode === 'bulk') {
        if (indGroup) indGroup.style.display = 'none';
        if (proofCont) proofCont.style.display = 'none';
        if (uploadActions) uploadActions.style.display = 'none';
        if (bulkPanel) bulkPanel.style.display = 'block';
        if (btnInd) btnInd.classList.remove('active');
        if (btnBulk) btnBulk.classList.add('active');
        if (helpText) helpText.textContent = 'Batch import multiple records via validated Excel spreadsheet (.xlsx)';
      } else {
        if (bulkPanel) bulkPanel.style.display = 'none';
        if (indGroup) indGroup.style.display = 'contents';
        if (proofCont) proofCont.style.display = '';
        if (uploadActions) uploadActions.style.display = '';
        if (btnBulk) btnBulk.classList.remove('active');
        if (btnInd) btnInd.classList.add('active');
        if (helpText) helpText.textContent = 'Submit an individual membership record with certificate upload';
      }
    };

    var catSelect = document.getElementById('socCategorySelect');
    if (catSelect) {
      catSelect.addEventListener('change', function(e) {
        var newCat = this.value;
        if (newCat === lastSelectedCategory) return;

        // Check if there is dirty incompatible data in category-specific fields
        var isDirty = false;
        var facInput = document.getElementById('socInputFacultyName');
        var regInput = document.getElementById('socInputRegNo');
        var stuNameInput = document.getElementById('socInputStudentName');

        if (lastSelectedCategory === 'Faculty' && facInput) {
          var fVal = facInput.value.trim();
          if (fVal !== '' && fVal !== defaultFacultyName) {
            isDirty = true;
          }
        } else if (lastSelectedCategory === 'Student') {
          if ((regInput && regInput.value.trim() !== '') || (stuNameInput && stuNameInput.value.trim() !== '')) {
            isDirty = true;
          }
        }

        if (isDirty) {
          var confirmed = confirm('Switching membership category will clear the entered ' + lastSelectedCategory + ' details. Are you sure you want to proceed?');
          if (!confirmed) {
            this.value = lastSelectedCategory;
            return;
          }
        }

        applyCategoryChange(newCat);
      });
    }

    function applyCategoryChange(cat) {
      currentCategory = cat;
      lastSelectedCategory = cat;

      var facRow = document.getElementById('socFacultyFieldsRow');
      var stuRow = document.getElementById('socStudentFieldsRow');
      var facInput = document.getElementById('socInputFacultyName');
      var regInput = document.getElementById('socInputRegNo');
      var stuNameInput = document.getElementById('socInputStudentName');
      var classYearSelect = document.getElementById('socInputClassYear');
      var lookupBadge = document.getElementById('socLookupBadge');
      var mTypeSelect = document.getElementById('socMembershipTypeSelect');
      var tplDesc = document.getElementById('socTemplateDescription');
      var tplBtn = document.getElementById('socBtnDownloadTemplate');
      var tplText = document.getElementById('socBtnDownloadText');

      if (cat === 'Student') {
        if (facRow) facRow.style.display = 'none';
        if (stuRow) stuRow.style.display = 'contents';
        if (facInput) {
          facInput.removeAttribute('required');
          facInput.value = '';
        }
        if (regInput) regInput.setAttribute('required', 'required');
        if (stuNameInput) stuNameInput.setAttribute('required', 'required');

        if (tplDesc) {
          tplDesc.textContent = 'Download the official Student Membership Excel spreadsheet. Includes columns for Register Number, Student Name, Department, Academic Year, Class/Year, Society, Membership No, Type, Dates, Status, and Certificate Link.';
        }
        if (tplBtn) {
          tplBtn.href = <?= json_encode(url('bulk-import.php?action=template&module=society_student')) ?>;
        }
        if (tplText) {
          tplText.textContent = 'Download Student Membership Template (.xlsx)';
        }

        // Populate student membership types
        if (mTypeSelect) {
          var curVal = mTypeSelect.value;
          mTypeSelect.innerHTML = '<option value="Student Member">Student Member</option>'
            + '<option value="Annual Member">Annual Member</option>'
            + '<option value="Life Member">Life Member</option>'
            + '<option value="Member">Member</option>';
          if (['Student Member', 'Annual Member', 'Life Member', 'Member'].includes(curVal)) {
            mTypeSelect.value = curVal;
          } else {
            mTypeSelect.value = 'Student Member';
          }
        }
      } else {
        if (stuRow) stuRow.style.display = 'none';
        if (facRow) facRow.style.display = 'contents';
        if (regInput) {
          regInput.removeAttribute('required');
          regInput.value = '';
        }
        if (stuNameInput) {
          stuNameInput.removeAttribute('required');
          stuNameInput.value = '';
        }
        if (classYearSelect) classYearSelect.value = '';
        if (lookupBadge) lookupBadge.style.display = 'none';

        if (facInput) {
          facInput.setAttribute('required', 'required');
          if (!facInput.value.trim()) {
            facInput.value = defaultFacultyName;
          }
        }

        if (tplDesc) {
          tplDesc.textContent = 'Download the official Faculty Membership Excel spreadsheet. Includes columns for Faculty Identifier/Name, Department, Academic Year, Society, Membership No, Type, Dates, Status, and Certificate Link.';
        }
        if (tplBtn) {
          tplBtn.href = <?= json_encode(url('bulk-import.php?action=template&module=society_faculty')) ?>;
        }
        if (tplText) {
          tplText.textContent = 'Download Faculty Membership Template (.xlsx)';
        }

        // Populate faculty membership types
        if (mTypeSelect) {
          var curVal = mTypeSelect.value;
          mTypeSelect.innerHTML = '<option value="Life Member">Life Member</option>'
            + '<option value="Annual Member">Annual Member</option>'
            + '<option value="Fellow">Fellow</option>'
            + '<option value="Senior Member">Senior Member</option>'
            + '<option value="Associate Member">Associate Member</option>'
            + '<option value="Member">Member</option>';
          if (['Life Member', 'Annual Member', 'Fellow', 'Senior Member', 'Associate Member', 'Member'].includes(curVal)) {
            mTypeSelect.value = curVal;
          } else {
            mTypeSelect.value = 'Life Member';
          }
        }
      }

      // Reset any active bulk preview if category changed
      if (bulkPreviewData) {
        resetSocBulkUpload();
      }
    }

    // Student Lookup with Debouncing
    var regInputEl = document.getElementById('socInputRegNo');
    var lookupTimer = null;
    if (regInputEl) {
      function triggerStudentLookup() {
        var reg = regInputEl.value.trim();
        var badge = document.getElementById('socLookupBadge');
        if (reg.length < 3) {
          if (badge) badge.style.display = 'none';
          return;
        }
        if (badge) {
          badge.textContent = 'Looking up...';
          badge.style.display = 'inline-block';
          badge.style.color = '#64748B';
          badge.style.background = '#F1F5F9';
          badge.style.padding = '2px 8px';
          badge.style.borderRadius = '4px';
        }
        fetch('upload.php?action=student_lookup&reg_no=' + encodeURIComponent(reg))
          .then(function(res) { return res.json(); })
          .then(function(json) {
            if (json.success && json.found && json.data) {
              var stuName = document.getElementById('socInputStudentName');
              if (stuName && json.data.student_name) {
                stuName.value = json.data.student_name;
              }
              var classYr = document.getElementById('socInputClassYear');
              if (classYr && json.data.class_year) {
                classYr.value = json.data.class_year;
              }
              var deptSelect = document.querySelector('select[name="department"]');
              if (deptSelect && json.data.department) {
                for (var i = 0; i < deptSelect.options.length; i++) {
                  if (deptSelect.options[i].value.toLowerCase() === json.data.department.toLowerCase()) {
                    deptSelect.selectedIndex = i;
                    break;
                  }
                }
              }
              if (badge) {
                badge.textContent = 'Verified Student: ' + (json.data.student_name || reg);
                badge.style.color = '#15803D';
                badge.style.background = '#DCFCE7';
              }
            } else {
              if (badge) {
                badge.textContent = 'New Student';
                badge.style.color = '#64748B';
                badge.style.background = '#F1F5F9';
              }
            }
          })
          .catch(function() {
            if (badge) badge.style.display = 'none';
          });
      }

      regInputEl.addEventListener('input', function() {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(triggerStudentLookup, 400);
      });
      regInputEl.addEventListener('blur', triggerStudentLookup);
    }

    // Bulk File Handling
    var chosenBulkFile = null;
    window.handleSocBulkFileSelected = function(input) {
      if (!input.files || input.files.length === 0) return;
      chosenBulkFile = input.files[0];
      document.getElementById('socBulkFileName').textContent = chosenBulkFile.name;
      document.getElementById('socBulkFileSize').textContent = '(' + (chosenBulkFile.size / 1024).toFixed(1) + ' KB)';
      document.getElementById('socBulkDropzone').style.display = 'none';
      document.getElementById('socBulkSelectedFileInfo').style.display = 'flex';
      document.getElementById('socBtnValidatePreview').disabled = false;
      hideSocBulkAlert();
    };

    window.clearSocBulkFile = function(e) {
      if (e) e.stopPropagation();
      chosenBulkFile = null;
      var fileInput = document.getElementById('socBulkExcelInput');
      if (fileInput) fileInput.value = '';
      document.getElementById('socBulkDropzone').style.display = 'block';
      document.getElementById('socBulkSelectedFileInfo').style.display = 'none';
      document.getElementById('socBtnValidatePreview').disabled = true;
    };

    function showSocBulkAlert(msg, type) {
      var box = document.getElementById('socBulkAlert');
      if (!box) return;
      box.style.display = 'block';
      box.textContent = msg;
      if (type === 'error') {
        box.style.background = '#FEF2F2';
        box.style.border = '1px solid #FECACA';
        box.style.color = '#991B1B';
      } else if (type === 'success') {
        box.style.background = '#F0FDF4';
        box.style.border = '1px solid #BBF7D0';
        box.style.color = '#15803D';
      } else {
        box.style.background = '#EFF6FF';
        box.style.border = '1px solid #BFDBFE';
        box.style.color = '#1D4ED8';
      }
    }

    function hideSocBulkAlert() {
      var box = document.getElementById('socBulkAlert');
      if (box) box.style.display = 'none';
    }

    window.submitSocBulkPreview = function() {
      if (!chosenBulkFile) return;
      var csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
      var mod = (currentCategory === 'Student') ? 'society_student' : 'society_faculty';

      var formData = new FormData();
      formData.append('action', 'upload_preview');
      formData.append('module', mod);
      formData.append('csrf_token', csrf);
      formData.append('is_ajax', '1');
      formData.append('excel_file', chosenBulkFile);

      document.getElementById('socBulkSpinner').style.display = 'block';
      document.getElementById('socBulkPreviewSection').style.display = 'none';
      hideSocBulkAlert();

      fetch('bulk-import.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(res) { return res.json(); })
      .then(function(json) {
        document.getElementById('socBulkSpinner').style.display = 'none';
        if (!json.success) {
          showSocBulkAlert(json.error || 'Failed to validate spreadsheet.', 'error');
          return;
        }
        renderSocBulkPreview(json.data);
      })
      .catch(function(err) {
        document.getElementById('socBulkSpinner').style.display = 'none';
        showSocBulkAlert('Network / Server communication error: ' + err.message, 'error');
      });
    };

    function renderSocBulkPreview(data) {
      bulkPreviewData = data;
      document.getElementById('socMetricTotal').textContent = data.total_detected;
      document.getElementById('socMetricValid').textContent = data.valid_count;
      document.getElementById('socMetricDuplicate').textContent = data.duplicate_count;
      document.getElementById('socMetricInvalid').textContent = data.invalid_count;

      document.getElementById('socTabCntAll').textContent = data.total_detected;
      document.getElementById('socTabCntValid').textContent = data.valid_count;
      document.getElementById('socTabCntDup').textContent = data.duplicate_count;
      document.getElementById('socTabCntErr').textContent = data.invalid_count;

      document.getElementById('socConfirmCount').textContent = data.valid_count;
      document.getElementById('socBtnConfirmImport').disabled = (data.valid_count === 0);

      // Render Errors Box
      var errBox = document.getElementById('socBulkErrorsBox');
      var errTbody = document.getElementById('socBulkErrorsTableBody');
      if (data.errors && data.errors.length > 0) {
        document.getElementById('socBulkErrorCount').textContent = data.errors.length;
        errTbody.innerHTML = '';
        data.errors.forEach(function(err) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td style="padding:4px 8px; font-weight:700;">Row ' + esc(err.row) + '</td>'
            + '<td style="padding:4px 8px; font-weight:600;">' + esc(err.field) + '</td>'
            + '<td style="padding:4px 8px; color:#DC2626;">' + esc(err.problem) + '</td>'
            + '<td style="padding:4px 8px; color:#64748B; font-family:monospace;">' + esc(err.value || '—') + '</td>';
          errTbody.appendChild(tr);
        });
        errBox.style.display = 'block';
      } else {
        errBox.style.display = 'none';
      }

      // Render Table Headers
      var thead = document.getElementById('socPreviewTableHead');
      var isStudent = (data.module === 'society_student');
      if (isStudent) {
        thead.innerHTML = '<tr style="background:#F8FAFC; color:#475569; font-weight:700; border-bottom:1px solid #E2E8F0;">'
          + '<th style="padding:8px 10px;">#</th>'
          + '<th style="padding:8px 10px;">Register No</th>'
          + '<th style="padding:8px 10px;">Student Name</th>'
          + '<th style="padding:8px 10px;">Dept</th>'
          + '<th style="padding:8px 10px;">AY</th>'
          + '<th style="padding:8px 10px;">Class/Year</th>'
          + '<th style="padding:8px 10px;">Society</th>'
          + '<th style="padding:8px 10px;">Membership No</th>'
          + '<th style="padding:8px 10px;">Type</th>'
          + '<th style="padding:8px 10px;">Validity</th>'
          + '<th style="padding:8px 10px;">Status</th>'
          + '<th style="padding:8px 10px;">Certificate Link</th>'
          + '<th style="padding:8px 10px;">Result</th>'
          + '</tr>';
      } else {
        thead.innerHTML = '<tr style="background:#F8FAFC; color:#475569; font-weight:700; border-bottom:1px solid #E2E8F0;">'
          + '<th style="padding:8px 10px;">#</th>'
          + '<th style="padding:8px 10px;">Faculty Name</th>'
          + '<th style="padding:8px 10px;">Dept</th>'
          + '<th style="padding:8px 10px;">AY</th>'
          + '<th style="padding:8px 10px;">Society</th>'
          + '<th style="padding:8px 10px;">Membership No</th>'
          + '<th style="padding:8px 10px;">Type</th>'
          + '<th style="padding:8px 10px;">Validity</th>'
          + '<th style="padding:8px 10px;">Status</th>'
          + '<th style="padding:8px 10px;">Certificate Link</th>'
          + '<th style="padding:8px 10px;">Result</th>'
          + '</tr>';
      }

      activePreviewFilter = 'all';
      previewSearchQuery = '';
      renderSocPreviewRows();

      document.getElementById('socBulkPreviewSection').style.display = 'block';
    }

    function renderSocPreviewRows() {
      if (!bulkPreviewData) return;
      var tbody = document.getElementById('socPreviewTableBody');
      tbody.innerHTML = '';

      var isStudent = (bulkPreviewData.module === 'society_student');
      var rows = bulkPreviewData.rows || [];
      var q = previewSearchQuery.toLowerCase();

      rows.forEach(function(r) {
        if (activePreviewFilter === 'valid' && r.status !== 'valid') return;
        if (activePreviewFilter === 'duplicate' && r.status !== 'duplicate') return;
        if (activePreviewFilter === 'invalid' && r.status !== 'invalid') return;

        if (q !== '') {
          var haystack = ((r.reg_no || '') + ' ' + (r.student_name || '') + ' ' + (r.faculty_name || '') + ' ' + (r.society_name || '') + ' ' + (r.membership_number || '')).toLowerCase();
          if (haystack.indexOf(q) === -1) return;
        }

        var tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #F1F5F9';

        var statusBadge = '';
        if (r.status === 'valid') {
          statusBadge = '<span style="background:#DCFCE7; color:#15803D; padding:2px 6px; border-radius:4px; font-weight:700; font-size:10.5px;">VALID</span>';
        } else if (r.status === 'duplicate') {
          statusBadge = '<span style="background:#FFEDD5; color:#9A3412; padding:2px 6px; border-radius:4px; font-weight:700; font-size:10.5px;" title="' + esc(r.duplicate_reason || 'Duplicate record') + '">DUPLICATE</span>';
        } else {
          statusBadge = '<span style="background:#FEE2E2; color:#991B1B; padding:2px 6px; border-radius:4px; font-weight:700; font-size:10.5px;" title="' + esc(r.error_message || 'Validation error') + '">INVALID</span>';
        }

        var certCell = '—';
        if (r.certificate_link) {
          certCell = '<a href="' + esc(r.certificate_link) + '" target="_blank" style="color:#FF4F01; font-weight:600; text-decoration:underline;">Link</a>';
        }

        var dateVal = (r.start_date || '') + (r.expiry_date ? (' to ' + r.expiry_date) : '');
        if (!dateVal) dateVal = '—';

        if (isStudent) {
          tr.innerHTML = '<td style="padding:6px 10px; font-weight:600;">' + esc(r.row_num) + '</td>'
            + '<td style="padding:6px 10px; font-weight:700; color:#131D3B;">' + esc(r.reg_no) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.student_name) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.department) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.academic_year) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.class_year || '—') + '</td>'
            + '<td style="padding:6px 10px; font-weight:600;">' + esc(r.society_name) + '</td>'
            + '<td style="padding:6px 10px; font-family:monospace;">' + esc(r.membership_number) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.membership_type || '—') + '</td>'
            + '<td style="padding:6px 10px; font-size:11px; color:#64748B;">' + esc(dateVal) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.membership_status || 'Active') + '</td>'
            + '<td style="padding:6px 10px;">' + certCell + '</td>'
            + '<td style="padding:6px 10px;">' + statusBadge + '</td>';
        } else {
          tr.innerHTML = '<td style="padding:6px 10px; font-weight:600;">' + esc(r.row_num) + '</td>'
            + '<td style="padding:6px 10px; font-weight:700; color:#131D3B;">' + esc(r.faculty_name) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.department) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.academic_year) + '</td>'
            + '<td style="padding:6px 10px; font-weight:600;">' + esc(r.society_name) + '</td>'
            + '<td style="padding:6px 10px; font-family:monospace;">' + esc(r.membership_number) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.membership_type || '—') + '</td>'
            + '<td style="padding:6px 10px; font-size:11px; color:#64748B;">' + esc(dateVal) + '</td>'
            + '<td style="padding:6px 10px;">' + esc(r.membership_status || 'Active') + '</td>'
            + '<td style="padding:6px 10px;">' + certCell + '</td>'
            + '<td style="padding:6px 10px;">' + statusBadge + '</td>';
        }
        tbody.appendChild(tr);
      });
    }

    window.filterSocPreviewTable = function(filter) {
      activePreviewFilter = filter;
      ['socTabAll', 'socTabValid', 'socTabDup', 'socTabErr'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('active');
      });
      if (filter === 'all') document.getElementById('socTabAll')?.classList.add('active');
      if (filter === 'valid') document.getElementById('socTabValid')?.classList.add('active');
      if (filter === 'duplicate') document.getElementById('socTabDup')?.classList.add('active');
      if (filter === 'invalid') document.getElementById('socTabErr')?.classList.add('active');
      renderSocPreviewRows();
    };

    window.searchSocPreviewTable = function(q) {
      previewSearchQuery = q;
      renderSocPreviewRows();
    };

    window.resetSocBulkUpload = function() {
      bulkPreviewData = null;
      clearSocBulkFile();
      document.getElementById('socBulkPreviewSection').style.display = 'none';
      hideSocBulkAlert();
    };

    window.confirmSocBulkImport = function() {
      if (!bulkPreviewData || !bulkPreviewData.batch_id) return;
      var csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
      var mod = bulkPreviewData.module;

      var formData = new FormData();
      formData.append('action', 'confirm_import');
      formData.append('batch_id', bulkPreviewData.batch_id);
      formData.append('module', mod);
      formData.append('csrf_token', csrf);
      formData.append('is_ajax', '1');

      var btn = document.getElementById('socBtnConfirmImport');
      btn.disabled = true;
      btn.textContent = 'Importing records...';

      fetch('bulk-import.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(res) { return res.json(); })
      .then(function(json) {
        if (!json.success) {
          btn.disabled = false;
          btn.textContent = 'Confirm & Import Valid Records';
          showSocBulkAlert(json.error || 'Import failed.', 'error');
          return;
        }
        showSocBulkAlert('Import Successful! ' + json.summary.imported_records + ' records successfully imported into ATTS.', 'success');
        document.getElementById('socBulkPreviewSection').style.display = 'none';
        clearSocBulkFile();
        setTimeout(function() {
          window.location.reload();
        }, 1300);
      })
      .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Confirm & Import Valid Records';
        showSocBulkAlert('Import communication failed: ' + err.message, 'error');
      });
    };

    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
  })();
  </script>

<?php elseif ($selectedType === 'inst_newsletters'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field" style="grid-column:span 2"><label>Newsletter Title <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Volume <span class="req">*</span></label><input class="input" name="volume" placeholder="e.g. Vol. 5" required></div>
  <div class="field"><label>Issue <span class="req">*</span></label><input class="input" name="issue" placeholder="e.g. Issue 2" required></div>
  <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>
  <div class="field"><label>Editor / Coordinator</label><input class="input" name="editor_name"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field"><label>URL</label><input class="input" name="url" type="url" placeholder="https://..."></div>

<?php elseif ($selectedType === 'inst_student_certifications'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Platform</label><input class="input" name="platform" placeholder="e.g. Coursera, NPTEL"></div>
  <div class="field"><label>Certification Name</label><input class="input" name="certification_name"></div>
  <div class="field"><label>Completion Date</label><input class="input" name="completion_date" type="date"></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>

<?php elseif ($selectedType === 'inst_nss_events'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
  <div class="field"><label>NSS Unit</label><input class="input" name="nss_unit"></div>
  <div class="field"><label>Coordinator</label><input class="input" name="coordinator"></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="1"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><input class="input" name="outcome"></div>

<?php elseif (in_array($selectedType, ['inst_inter_inst_within', 'inst_inter_inst_outside', 'inst_inter_inst_awards'], true)): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <?php if (function_exists('render_exam_session_field')) { render_exam_session_field($effectiveYear ?? $activeYear); } ?>
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
    <label for="inter_inst_cert_link">Certificate Link (Optional) <span class="card-sub">(https://...)</span></label>
    <input class="input" name="certificate_link" id="inter_inst_cert_link" type="url" value="<?= e($editRecord['certificate_link'] ?? '') ?>" placeholder="https://example.com/certificate.pdf">
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
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Course Type</label><select class="select" name="course_type"><option>Value Added Course</option><option>Hands-On Training</option><option>Workshop</option><option>Bridge Course</option></select></div>
  <div class="field"><label>Organizer <span class="req">*</span></label><input class="input" name="organizer" required></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>End Date</label><input class="input" name="end_date" type="date"></div>
  <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 30 hours"></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="1"></div>
  <div class="field"><label>Faculty Coordinator</label><input class="input" name="faculty_coordinator"></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><input class="input" name="outcome"></div>

<?php elseif ($selectedType === 'inst_sports_state'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Sport <span class="req">*</span></label><input class="input" name="sport" placeholder="e.g. Cricket, Chess" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Level</label><input class="input" name="level" value="State" readonly style="background:var(--bg-subtle,#f3f4f6)"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Venue</label><input class="input" name="venue"></div>
  <div class="field"><label>Position / Participation</label><input class="input" name="position" placeholder="e.g. Runner-up, Participant"></div>
  <div class="field"><label>Award</label><input class="input" name="award"></div>

<?php elseif ($selectedType === 'inst_sports_national'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Sport <span class="req">*</span></label><input class="input" name="sport" placeholder="e.g. Volleyball" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Level</label><input class="input" name="level" value="National" readonly style="background:var(--bg-subtle,#f3f4f6)"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Venue</label><input class="input" name="venue"></div>
  <div class="field"><label>Position / Participation</label><input class="input" name="position" placeholder="e.g. Winner, Participant"></div>
  <div class="field"><label>Award</label><input class="input" name="award"></div>

<?php elseif ($selectedType === 'inst_innovation_events'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Event Type</label><select class="select" name="event_type"><option>Hackathon</option><option>Ideathon</option><option>Prototype Competition</option><option>Innovation Exhibition</option><option>Design Sprint</option><option>Other</option></select></div>
  <div class="field"><label>Date <span class="req">*</span></label><input class="input" name="event_date" type="date" required></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Number of Participants</label><input class="input" name="participants" type="number" min="1"></div>
  <div class="field"><label>Innovation Theme</label><input class="input" name="innovation_theme" placeholder="e.g. AI for Society"></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><textarea class="input" name="outcome" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_iic_activities'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Activity Name <span class="req">*</span></label><input class="input" name="activity_name" required></div>
  <div class="field"><label>Activity Type</label><select class="select" name="activity_type"><option>Seminar</option><option>Workshop</option><option>Mentoring Session</option><option>Innovation Drive</option><option>Startup Bootcamp</option><option>Other</option></select></div>
  <div class="field"><label>Date <span class="req">*</span></label><input class="input" name="activity_date" type="date" required></div>
  <div class="field"><label>Organizer</label><input class="input" name="organizer"></div>
  <div class="field"><label>Coordinator</label><input class="input" name="coordinator"></div>
  <div class="field"><label>Participants</label><input class="input" name="participants" type="number" min="1"></div>
  <div class="field" style="grid-column:span 2"><label>Outcome</label><input class="input" name="outcome"></div>

<?php elseif ($selectedType === 'inst_website_updations'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Update Title <span class="req">*</span></label><input class="input" name="update_title" required></div>
  <div class="field"><label>Page / Section Updated <span class="req">*</span></label><input class="input" name="page_section" required placeholder="e.g. Faculty Page, Events"></div>
  <div class="field"><label>Updated By <span class="req">*</span></label><input class="input" name="updated_by" value="<?= e($iName) ?>" required></div>
  <div class="field"><label>Update Date <span class="req">*</span></label><input class="input" name="update_date" type="date" required></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>
  <div class="field" style="grid-column:span 2"><label>URL</label><input class="input" name="website_url" type="url" placeholder="https://..."></div>

<?php elseif ($selectedType === 'inst_google_ratings'): ?>
  <?php render_dept_field($user, $departments, 'Institution / Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Google Rating (0–5) <span class="req">*</span></label><input class="input" name="google_rating" type="number" step="0.1" min="0" max="5" required placeholder="e.g. 4.3"></div>
  <div class="field"><label>Review Count</label><input class="input" name="review_count" type="number" min="0" placeholder="e.g. 150"></div>
  <div class="field"><label>Measurement Date <span class="req">*</span></label><input class="input" name="measurement_date" type="date" required></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

<?php elseif ($selectedType === 'inst_startups'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Startup Name <span class="req">*</span></label><input class="input" name="startup_name" required></div>
  <div class="field"><label>Founder(s) <span class="req">*</span></label><input class="input" name="founders" required placeholder="Comma-separated names"></div>
  <div class="field"><label>Student / Faculty</label><select class="select" name="founder_type"><option>Student</option><option>Faculty</option><option>Both</option><option>Alumni</option></select></div>
  <div class="field"><label>Register Number <span class="card-sub">(if Student)</span></label><input class="input" name="reg_no"></div>
  <div class="field"><label>Startup Type</label><select class="select" name="startup_type"><option>Technology</option><option>Social Impact</option><option>Ed-Tech</option><option>Health Tech</option><option>Agri-Tech</option><option>Other</option></select></div>
  <div class="field"><label>Registration Number</label><input class="input" name="registration_number"></div>
  <div class="field"><label>Registration Date</label><input class="input" name="registration_date" type="date"></div>
  <div class="field"><label>Status</label><select class="select" name="startup_status"><option>Idea Stage</option><option>Prototype Stage</option><option>Operational</option><option>Funded</option><option>Closed</option></select></div>
  <div class="field" style="grid-column:span 2"><label>Description</label><textarea class="input" name="description" rows="2"></textarea></div>

<?php elseif ($selectedType === 'inst_alumni_chapters'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Chapter Name <span class="req">*</span></label><input class="input" name="chapter_name" required></div>
  <div class="field"><label>Location</label><input class="input" name="location"></div>
  <div class="field"><label>Coordinator <span class="req">*</span></label><input class="input" name="coordinator" required></div>
  <div class="field"><label>Formation Date</label><input class="input" name="formation_date" type="date"></div>
  <div class="field"><label>Member Count</label><input class="input" name="member_count" type="number" min="1"></div>
  <div class="field" style="grid-column:span 2"><label>Activities</label><textarea class="input" name="activities" rows="2" placeholder="Describe key activities"></textarea></div>
  <div class="field"><label>Status</label><select class="select" name="chapter_status"><option>Active</option><option>Inactive</option><option>Newly Formed</option></select></div>

<?php elseif ($selectedType === 'inst_awards_recognitions'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <?php
    $recogTypes = [
      'Department Awards / Recognition',
      'Faculty Awards / Recognition',
      'BoS Member',
      'DC Member',
      'Question Paper / Key Setting',
      'Journal Reviewer',
      'Resource Person',
      'Others',
    ];
    $currRecog = $editRecord['recognition_type'] ?? 'Department Awards / Recognition';
    if ($currRecog === 'Department Award') $currRecog = 'Department Awards / Recognition';
    if ($currRecog === 'Faculty Award' || $currRecog === 'Recognition') $currRecog = 'Faculty Awards / Recognition';
    if ($currRecog === 'QP / Question Paper Setting' || $currRecog === 'Key Setting') $currRecog = 'Question Paper / Key Setting';
    if ($currRecog === 'Other') $currRecog = 'Others';
  ?>
  <div class="field"><label>Recognition Type <span class="req">*</span></label>
    <select class="select" name="recognition_type" id="recogTypeSelect" required onchange="toggleRecogOther(this.value)">
      <?php foreach ($recogTypes as $rt): ?>
        <option value="<?= e($rt) ?>" <?= ($currRecog === $rt) ? 'selected' : '' ?>><?= e($rt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Person Name <span class="req">*</span></label><input class="input" name="person_name" value="<?= e($editRecord['person_name'] ?? '') ?>" required></div>
  <div class="field"><label>Faculty Name (if applicable)</label><input class="input" name="faculty_name" value="<?= e($editRecord['faculty_name'] ?? $iName) ?>"></div>
  <div class="field" style="grid-column:span 2"><label>Title / Award <span class="req">*</span></label><input class="input" name="title" value="<?= e($editRecord['title_award'] ?? $editRecord['title'] ?? '') ?>" required></div>
  <div class="field"><label>Organization</label><input class="input" name="organization" value="<?= e($editRecord['organization'] ?? '') ?>"></div>
  <div class="field"><label>Date</label><input class="input" name="achievement_date" type="date" value="<?= e($editRecord['achievement_date'] ?? $editRecord['event_date'] ?? '') ?>"></div>
  <div class="field"><label>Role / Responsibility</label><input class="input" name="role_responsibility" value="<?= e($editRecord['role_responsibility'] ?? '') ?>"></div>
  <div class="field"><label>Reference Number</label><input class="input" name="reference_number" value="<?= e($editRecord['reference_number'] ?? '') ?>"></div>
  <div class="field js-recog-other-box" style="grid-column:span 2; display:<?= ($currRecog === 'Others') ? 'block' : 'none' ?>;">
    <label>Other Details / Description <span class="req">*</span></label>
    <input class="input" name="other_details" id="recogOtherInput" placeholder="Specify recognition details..." value="<?= e($editRecord['remarks'] ?? '') ?>">
  </div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks" value="<?= e($editRecord['remarks'] ?? '') ?>"></div>
  <script>
  function toggleRecogOther(val) {
    var box = document.querySelector('.js-recog-other-box');
    var inp = document.getElementById('recogOtherInput');
    if (!box) return;
    if (val === 'Others') {
      box.style.display = 'block';
      if (inp) inp.required = true;
    } else {
      box.style.display = 'none';
      if (inp) inp.required = false;
    }
  }
  </script>

<?php elseif ($selectedType === 'inst_spoken_tutorials'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Course Name <span class="req">*</span></label><input class="input" name="course_name" required></div>
  <div class="field"><label>Tutorial Name</label><input class="input" name="tutorial_name"></div>
  <div class="field"><label>Completion Date</label><input class="input" name="completion_date" type="date"></div>
  <div class="field"><label>Certificate ID</label><input class="input" name="certificate_id"></div>
  <div class="field"><label>Platform</label><input class="input" name="platform" value="IIT Bombay Spoken Tutorial"></div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var istToday = <?= json_encode(app_ist_today()) ?>;
  var allowFuture = ['valid_upto', 'expiry_date', 'planned_end_date', 'project_end_date'];
  document.querySelectorAll('input[type="date"]').forEach(function (inp) {
    if (!allowFuture.includes(inp.name) && !inp.hasAttribute('max')) {
      inp.setAttribute('max', istToday);
    }
  });
});
</script>
