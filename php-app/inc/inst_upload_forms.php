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

// Helper: read-only academic year field
if (!function_exists('inst_yr')) {
function inst_yr(string $yr): void {
    echo '<div class="field"><label>Academic Year</label><input class="input" value="' . htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') . '" readonly style="background:var(--bg-subtle,#f3f4f6);cursor:not-allowed;"></div>';
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
  <div class="field">
    <label>Academic Year <span class="card-sub">— Locked</span></label>
    <input class="input" id="upercentAcademicYear" name="academic_year" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>.">
  </div>
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
  <div class="field"><label>Faculty Name <span class="req">*</span></label><input class="input" name="faculty_name" value="<?= e($iName) ?>" required></div>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Professional Society <span class="req">*</span></label><input class="input" name="society_name" placeholder="e.g. IEEE, ISTE, CSI" required></div>
  <div class="field"><label>Membership Number <span class="req">*</span></label><input class="input" name="membership_number" required></div>
  <div class="field"><label>Membership Type</label><select class="select" name="membership_type"><option>Life Member</option><option>Annual Member</option><option>Student Member</option><option>Fellow</option></select></div>
  <div class="field"><label>Start Date</label><input class="input" name="start_date" type="date"></div>
  <div class="field"><label>Expiry Date</label><input class="input" name="end_date" type="date"></div>
  <div class="field"><label>Status</label><select class="select" name="mem_status"><option>Active</option><option>Expired</option><option>Renewed</option></select></div>

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

<?php elseif ($selectedType === 'inst_inter_inst_within'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Host Institution <span class="req">*</span></label><input class="input" name="host_institution" required></div>
  <div class="field"><label>Location</label><input class="input" name="location"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Participation Type</label><select class="select" name="participation_type"><option>Participant</option><option>Presenter</option><option>Winner</option><option>Runner-up</option></select></div>
  <div class="field"><label>Position / Award</label><input class="input" name="position_award" placeholder="e.g. First Place"></div>

<?php elseif ($selectedType === 'inst_inter_inst_outside'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Host Institution <span class="req">*</span></label><input class="input" name="host_institution" required></div>
  <div class="field"><label>State <span class="req">*</span></label><input class="input" name="state" required placeholder="e.g. Maharashtra"></div>
  <div class="field"><label>City</label><input class="input" name="location"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Participation Type</label><select class="select" name="participation_type"><option>Participant</option><option>Presenter</option><option>Winner</option><option>Runner-up</option></select></div>
  <div class="field"><label>Position / Award</label><input class="input" name="position_award" placeholder="e.g. 2nd Place"></div>

<?php elseif ($selectedType === 'inst_inter_inst_awards'): ?>
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
  <div class="field"><label>Register Number <span class="req">*</span></label><input class="input" name="reg_no" required></div>
  <div class="field"><label>Event Name <span class="req">*</span></label><input class="input" name="event_name" required></div>
  <div class="field"><label>Host Institution</label><input class="input" name="host_institution"></div>
  <div class="field"><label>State</label><input class="input" name="state"></div>
  <div class="field"><label>Location</label><input class="input" name="location"></div>
  <div class="field"><label>Award / Medal <span class="req">*</span></label><input class="input" name="award" required placeholder="e.g. Gold Medal, Trophy"></div>
  <div class="field"><label>Position</label><input class="input" name="position" placeholder="e.g. 1st, Runner-up"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>

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
