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
  <?php render_dept_field($user, $departments, 'Department', true); ?>
  <?php inst_yr($iYear); ?>
  <div class="field"><label>Programme / Course <span class="req">*</span></label><input class="input" name="programme" placeholder="e.g. B.E. CSE" required></div>
  <div class="field"><label>Class / Year <span class="req">*</span></label><select class="select" name="class_year" required><option>I Year</option><option>II Year</option><option>III Year</option><option>IV Year</option></select></div>
  <div class="field"><label>Semester <span class="req">*</span></label><select class="select" name="semester" required><option>Sem 1</option><option>Sem 2</option><option>Sem 3</option><option>Sem 4</option><option>Sem 5</option><option>Sem 6</option><option>Sem 7</option><option>Sem 8</option></select></div>
  <div style="grid-column:span 2;margin-top:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <strong style="font-size:13px;">Subject-wise / Student-wise Rows</strong>
      <button type="button" class="btn btn-outline btn-sm" onclick="instPpAddRow()"><?= icon('plus',14) ?> Add Row</button>
    </div>
    <div style="overflow-x:auto;">
    <table class="data" id="instPpTable" style="min-width:860px;font-size:12px;">
      <thead><tr>
        <th style="width:40px">S.No</th><th>Subject Code *</th><th>Subject Name *</th>
        <th>Reg. No</th><th>Student Name</th><th>Pass Status</th>
        <th style="width:80px">Total *</th><th style="width:80px">Passed *</th>
        <th style="width:70px">Pass %</th><th style="width:36px"></th>
      </tr></thead>
      <tbody id="instPpBody"></tbody>
    </table>
    </div>
    <div id="instPpSummary" style="margin-top:10px;padding:10px 14px;background:var(--bg-subtle,#f3f4f6);border-radius:8px;font-size:12px;display:none;">
      <span style="margin-right:16px;"><strong>Subjects:</strong> <span id="ppSumSubj">0</span></span>
      <span style="margin-right:16px;"><strong>Total Students:</strong> <span id="ppSumTot">0</span></span>
      <span style="margin-right:16px;"><strong>Passed:</strong> <span id="ppSumPass">0</span></span>
      <span><strong>Overall Pass %:</strong> <span id="ppSumPct">0.00</span>%</span>
    </div>
    <input type="hidden" name="pass_percentage_rows" id="instPpRowsJson" value="[]">
  </div>
  <script>
  (function(){
    var rows=[];
    function h(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    function render(){
      var tb=document.getElementById('instPpBody');if(!tb)return;tb.innerHTML='';
      rows.forEach(function(r,i){
        var tr=document.createElement('tr');
        var pct=(parseInt(r.total_members)||0)>0?(((parseInt(r.passed_members)||0)/(parseInt(r.total_members)||1))*100).toFixed(2):'0.00';
        r.pass_percentage=pct;
        tr.innerHTML='<td style="text-align:center;color:#888">'+(i+1)+'</td>'+
          '<td><input class="input" style="font-size:11px;min-width:90px" value="'+h(r.subject_code)+'" oninput="ppSet('+i+',\'subject_code\',this.value)" placeholder="Code"></td>'+
          '<td><input class="input" style="font-size:11px;min-width:130px" value="'+h(r.subject_name)+'" oninput="ppSet('+i+',\'subject_name\',this.value)" placeholder="Subject Name"></td>'+
          '<td><input class="input" style="font-size:11px;min-width:90px" value="'+h(r.reg_no)+'" oninput="ppSet('+i+',\'reg_no\',this.value)" placeholder="Reg No"></td>'+
          '<td><input class="input" style="font-size:11px;min-width:120px" value="'+h(r.student_name)+'" oninput="ppSet('+i+',\'student_name\',this.value)" placeholder="Name"></td>'+
          '<td><select class="select" style="font-size:11px;" onchange="ppSet('+i+',\'pass_status\',this.value)"><option value="Pass"'+(r.pass_status==='Pass'?' selected':'')+'>Pass</option><option value="Fail"'+(r.pass_status==='Fail'?' selected':'')+'>Fail</option></select></td>'+
          '<td><input class="input" style="font-size:11px;width:68px" type="number" min="1" value="'+h(r.total_members)+'" oninput="ppSet('+i+',\'total_members\',this.value)"></td>'+
          '<td><input class="input" style="font-size:11px;width:68px" type="number" min="0" value="'+h(r.passed_members)+'" oninput="ppSet('+i+',\'passed_members\',this.value)"></td>'+
          '<td style="text-align:center;font-weight:600;color:var(--primary)">'+pct+'%</td>'+
          '<td><button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger,#ef4444)" onclick="ppDel('+i+')">&#x2715;</button></td>';
        tb.appendChild(tr);
      });
      updateSummary();
      var el=document.getElementById('instPpRowsJson');if(el)el.value=JSON.stringify(rows);
    }
    function updateSummary(){
      var sm=document.getElementById('instPpSummary');
      if(rows.length===0){if(sm)sm.style.display='none';return;}
      if(sm)sm.style.display='';
      var t=0,p=0;rows.forEach(function(r){t+=parseInt(r.total_members)||0;p+=parseInt(r.passed_members)||0;});
      document.getElementById('ppSumSubj').textContent=rows.length;
      document.getElementById('ppSumTot').textContent=t;
      document.getElementById('ppSumPass').textContent=p;
      document.getElementById('ppSumPct').textContent=t>0?((p/t)*100).toFixed(2):'0.00';
    }
    window.instPpAddRow=function(){rows.push({subject_code:'',subject_name:'',reg_no:'',student_name:'',pass_status:'Pass',total_members:'',passed_members:'',pass_percentage:'0.00'});render();};
    window.ppDel=function(i){rows.splice(i,1);render();};
    window.ppSet=function(i,k,v){rows[i][k]=v;render();};
    document.addEventListener('DOMContentLoaded',function(){if(rows.length===0)instPpAddRow();});
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
  <div class="field"><label>Recognition Type <span class="req">*</span></label>
    <select class="select" name="recognition_type" required>
      <option>Department Award</option><option>Faculty Award</option><option>Recognition</option>
      <option>BoS Member</option><option>DC Member</option>
      <option>QP / Question Paper Setting</option><option>Key Setting</option><option>Other</option>
    </select>
  </div>
  <div class="field"><label>Person Name <span class="req">*</span></label><input class="input" name="person_name" required></div>
  <div class="field"><label>Faculty Name (if applicable)</label><input class="input" name="faculty_name" value="<?= e($iName) ?>"></div>
  <div class="field" style="grid-column:span 2"><label>Title / Award <span class="req">*</span></label><input class="input" name="title" required></div>
  <div class="field"><label>Organization</label><input class="input" name="organization"></div>
  <div class="field"><label>Date</label><input class="input" name="event_date" type="date"></div>
  <div class="field"><label>Role / Responsibility</label><input class="input" name="role_responsibility"></div>
  <div class="field"><label>Reference Number</label><input class="input" name="reference_number"></div>
  <div class="field" style="grid-column:span 2"><label>Remarks</label><input class="input" name="remarks"></div>

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
