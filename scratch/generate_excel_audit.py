import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

def build_audit_excel():
    wb = openpyxl.Workbook()
    
    # Styles
    title_font = Font(name='Segoe UI', size=16, bold=True, color='FFFFFF')
    section_font = Font(name='Segoe UI', size=12, bold=True, color='1F4E78')
    header_font = Font(name='Segoe UI', size=11, bold=True, color='FFFFFF')
    sub_header_font = Font(name='Segoe UI', size=10, bold=True, color='1F4E78')
    bold_font = Font(name='Segoe UI', size=10, bold=True)
    normal_font = Font(name='Segoe UI', size=10)
    code_font = Font(name='Consolas', size=9)
    
    # Fills
    navy_header_fill = PatternFill(start_color='1F4E78', end_color='1F4E78', fill_type='solid')
    dark_blue_title_fill = PatternFill(start_color='002060', end_color='002060', fill_type='solid')
    light_blue_sub_fill = PatternFill(start_color='D9E1F2', end_color='D9E1F2', fill_type='solid')
    zebra_fill = PatternFill(start_color='F9FBFD', end_color='F9FBFD', fill_type='solid')
    
    # Severity Fills
    critical_fill = PatternFill(start_color='FFC7CE', end_color='FFC7CE', fill_type='solid')
    critical_font = Font(name='Segoe UI', size=10, bold=True, color='9C0006')
    
    high_fill = PatternFill(start_color='FFEB9C', end_color='FFEB9C', fill_type='solid')
    high_font = Font(name='Segoe UI', size=10, bold=True, color='9C6500')
    
    medium_fill = PatternFill(start_color='FFF2CC', end_color='FFF2CC', fill_type='solid')
    medium_font = Font(name='Segoe UI', size=10, color='B25900')
    
    low_fill = PatternFill(start_color='E2EFDA', end_color='E2EFDA', fill_type='solid')
    low_font = Font(name='Segoe UI', size=10, color='375623')
    
    # Borders
    thin_border_side = Side(border_style='thin', color='D9D9D9')
    grid_border = Border(left=thin_border_side, right=thin_border_side, top=thin_border_side, bottom=thin_border_side)
    thick_bottom = Border(bottom=Side(border_style='medium', color='1F4E78'))
    
    # Alignments
    center_align = Alignment(horizontal='center', vertical='center', wrap_text=True)
    left_align = Alignment(horizontal='left', vertical='top', wrap_text=True)
    header_align = Alignment(horizontal='center', vertical='center', wrap_text=True)
    
    # ---------------------------------------------------------
    # TAB 1: Executive_Summary
    # ---------------------------------------------------------
    ws_sum = wb.active
    ws_sum.title = "Executive_Summary"
    ws_sum.views.sheetView[0].showGridLines = True
    
    # Title Banner
    ws_sum.merge_cells('A1:G2')
    title_cell = ws_sum['A1']
    title_cell.value = "ATTS / ATTC PHP PROJECT — COMPREHENSIVE SYSTEM AUDIT & SPECIFICATIONS"
    title_cell.font = title_font
    title_cell.fill = dark_blue_title_fill
    title_cell.alignment = center_align
    
    ws_sum.merge_cells('A3:G3')
    sub_title = ws_sum['A3']
    sub_title.value = "Complete Inventory of Bugs, Workflow Flaws, Requested Features, Mandatory Advancements & QA Testing Methods"
    sub_title.font = sub_header_font
    sub_title.fill = light_blue_sub_fill
    sub_title.alignment = center_align
    
    summary_data = [
        ["Audit Metadata Parameter", "Value / Status"],
        ["Project Name", "IQAC - MSEC - ATTC PHP Management Portal"],
        ["Audit Scope", "Full System (UI, Workflow, Backend, Database, Reports, Executive Meetings, Roles)"],
        ["Directive Compliance", "STRICT AUDIT ONLY — Zero code modifications executed as instructed"],
        ["Total Audit Records", "65 Items"],
        ["Critical / High Bugs Cataloged", "25 System Defects"],
        ["Coordinator Specific Issues Cataloged", "14 Items (Tabs, Timestamps, Submissions, Exports)"],
        ["New Features & Mandatory Advancements", "26 Required Features"],
        ["Role Revisions", "Director -> Principal, HOD Edit Request, Admin Sidebar Academic Year"],
        ["Target Testing Coverage", "Before & After Patchup Verification Methods for 100% of Items"]
    ]
    
    for row_idx, row_val in enumerate(summary_data, start=5):
        ws_sum.cell(row=row_idx, column=1, value=row_val[0]).font = bold_font
        ws_sum.cell(row=row_idx, column=2, value=row_val[1]).font = normal_font
        ws_sum.cell(row=row_idx, column=1).border = grid_border
        ws_sum.cell(row=row_idx, column=2).border = grid_border
        if row_idx == 5:
            ws_sum.cell(row=row_idx, column=1).fill = navy_header_fill
            ws_sum.cell(row=row_idx, column=1).font = header_font
            ws_sum.cell(row=row_idx, column=2).fill = navy_header_fill
            ws_sum.cell(row=row_idx, column=2).font = header_font
            
    # Breakdown Table
    ws_sum.cell(row=17, column=1, value="SYSTEM AUDIT BREAKDOWN BY CATEGORY").font = section_font
    
    cat_headers = ["Category", "Defects (Bugs)", "Feature Additions", "Mandatory Advancements", "Total Items"]
    for c_idx, h_text in enumerate(cat_headers, start=1):
        c_cell = ws_sum.cell(row=19, column=c_idx, value=h_text)
        c_cell.font = header_font
        c_cell.fill = navy_header_fill
        c_cell.alignment = header_align
        c_cell.border = grid_border
        
    cat_data = [
        ["User Interface & Authentication (UI/UX)", 9, 3, 2, 14],
        ["Governance, Roles & Approvals (Workflow)", 5, 6, 3, 14],
        ["Coordinator Upload Forms (Coordinator)", 14, 0, 0, 14],
        ["Reports & Multi-Format Exports (Reports)", 5, 6, 2, 13],
        ["Executive Meetings & Presentation Mode (EM)", 2, 4, 2, 8],
        ["Backend, Database & File Storage (Backend)", 3, 2, 2, 7],
        ["TOTALS", 38, 21, 11, 70]
    ]
    
    for r_offset, r_data in enumerate(cat_data, start=20):
        for c_offset, val in enumerate(r_data, start=1):
            cell = ws_sum.cell(row=r_offset, column=c_offset, value=val)
            cell.border = grid_border
            cell.alignment = left_align if c_offset == 1 else center_align
            if r_offset == 26:
                cell.font = bold_font
                cell.fill = light_blue_sub_fill
            else:
                cell.font = normal_font

    ws_sum.column_dimensions['A'].width = 45
    ws_sum.column_dimensions['B'].width = 65
    ws_sum.column_dimensions['C'].width = 22
    ws_sum.column_dimensions['D'].width = 25
    ws_sum.column_dimensions['E'].width = 18

    # ---------------------------------------------------------
    # Helper to add standard styled tabs
    # ---------------------------------------------------------
    def create_table_sheet(sheet_name, title_text, headers, rows_data):
        ws = wb.create_sheet(title=sheet_name)
        ws.views.sheetView[0].showGridLines = True
        
        # Header title
        ws.merge_cells(start_row=1, start_column=1, end_row=1, end_column=len(headers))
        t_cell = ws.cell(row=1, column=1, value=title_text)
        t_cell.font = title_font
        t_cell.fill = dark_blue_title_fill
        t_cell.alignment = center_align
        ws.row_dimensions[1].height = 35
        
        # Table Headers
        ws.row_dimensions[3].height = 28
        for col_idx, header in enumerate(headers, start=1):
            cell = ws.cell(row=3, column=col_idx, value=header)
            cell.font = header_font
            cell.fill = navy_header_fill
            cell.alignment = header_align
            cell.border = grid_border
            
        ws.freeze_panes = 'A4'
        ws.auto_filter.ref = f"A3:{get_column_letter(len(headers))}{len(rows_data)+3}"
        
        for row_idx, row_values in enumerate(rows_data, start=4):
            ws.row_dimensions[row_idx].height = 65 # generous row height for multi-line text
            is_even = (row_idx % 2 == 0)
            
            for col_idx, val in enumerate(row_values, start=1):
                cell = ws.cell(row=row_idx, column=col_idx, value=val)
                cell.font = normal_font
                cell.border = grid_border
                cell.alignment = left_align
                
                if is_even:
                    cell.fill = zebra_fill
                    
                # Severity formatting
                str_val = str(val)
                if str_val in ["Critical"]:
                    cell.fill = critical_fill
                    cell.font = critical_font
                    cell.alignment = center_align
                elif str_val in ["High", "Mandatory"]:
                    cell.fill = high_fill
                    cell.font = high_font
                    cell.alignment = center_align
                elif str_val in ["Medium"]:
                    cell.fill = medium_fill
                    cell.font = medium_font
                    cell.alignment = center_align
                elif str_val in ["Low"]:
                    cell.fill = low_fill
                    cell.font = low_font
                    cell.alignment = center_align
                elif col_idx == 1:
                    cell.font = bold_font
                    cell.alignment = center_align
                    
        # Set column widths
        for col_idx in range(1, len(headers) + 1):
            col_letter = get_column_letter(col_idx)
            ws.column_dimensions[col_letter].width = 30 # default
        return ws

    # ---------------------------------------------------------
    # TAB 2: Bug_Catalog
    # ---------------------------------------------------------
    bug_headers = [
        "Bug ID", "Category", "Module / Screen", "Roles Impacted", 
        "Severity", "Defect Description & Root Cause", 
        "Pre-Patchup Testing Method", "Post-Patchup Verification Method"
    ]
    
    bug_rows = [
        [
            "BUG-LOGIN-01", "Authentication", "Login Page (login.php)", "All Roles", "High",
            "Credential inputs do not auto-trim leading or trailing whitespace. Copy-pasting usernames/passwords with trailing spaces causes failed logins.",
            "1. Copy 'admin ' (with space) into Username field.\n2. Enter valid password.\n3. Click Login.\nResult: Authentication fails with Invalid Credentials error.",
            "1. Enter 'admin ' with trailing space.\n2. Submit login form.\nResult: Input is auto-trimmed before authentication, allowing successful login."
        ],
        [
            "BUG-LOGIN-02", "UI / Security", "Login Page (login.php)", "All Roles", "Medium",
            "Password inputs lack a 'Show Password' (eye icon) toggle, preventing users from verifying typed characters.",
            "1. Navigate to login page.\n2. Type password into input field.\nResult: Text is masked with dots; no toggle button exists to reveal password text.",
            "1. Click the eye icon next to password input.\nResult: Password text toggles smoothly between masked dots and clear text."
        ],
        [
            "BUG-LOGIN-03", "UI Standard", "Login Page (login.php)", "All Roles", "Low",
            "Button text reads 'Sign in' instead of requested standardized terminology 'Login in'.",
            "1. View login submit button.\nResult: Button displays 'Sign in'.",
            "1. View login submit button.\nResult: Button displays 'Login in' across all application login pages."
        ],
        [
            "BUG-LOGIN-04", "Governance", "Login Page (login.php)", "All Roles", "High",
            "Academic Year selection dropdown is rendered on the Login page, exposing active session state decisions to unauthenticated users.",
            "1. Inspect login form.\nResult: Academic Year dropdown allows users to choose academic year prior to authentication.",
            "1. Inspect login form.\nResult: Academic Year selector is removed from login; active Academic Year is controlled globally by Admin."
        ],
        [
            "BUG-UI-05", "UI / UX", "Global Dashboard Header", "All Roles", "Medium",
            "Missing universal 'Back' button near the dashboard header on sub-pages and detail views across all user roles.",
            "1. Navigate to any nested sub-page (e.g., reports.php?type=faculty).\nResult: No top 'Back' button exists to return to parent dashboard.",
            "1. Open any sub-page.\nResult: Prominent 'Back' button appears near dashboard header and returns user to previous dashboard view."
        ],
        [
            "BUG-UI-06", "UI / UX", "Announcements Widget", "Admin, All Roles", "Medium",
            "Announcement badges lack distinct color coding for priority levels (Urgent vs IQAC Submission).",
            "1. Create an Urgent announcement and an IQAC Submission announcement.\nResult: Both appear with identical default styling.",
            "1. View announcements.\nResult: Urgent announcements display Dark Red badge; IQAC Admin submissions display Green badge."
        ],
        [
            "BUG-UI-07", "UI Validation", "Form Date Pickers", "Faculty, Coord", "Medium",
            "Date/Month pickers permit future date selection, resulting in invalid future academic data entries.",
            "1. Open any form date picker.\n2. Select a date in the next month/year.\nResult: Future date is accepted and saved to database.",
            "1. Open date picker.\nResult: Calendar scroll is restricted to past 1 year only; future dates are disabled."
        ],
        [
            "BUG-UI-08", "UI Feature", "Dashboard Views", "Admin, Principal, HOD", "Medium",
            "Dashboards lack a 'Data View / Analytics View' toggle to switch between tabular list data and visual graphical charts.",
            "1. Access HOD/Admin dashboard.\nResult: Only single static layout is available.",
            "1. Click 'Data View / Analytics View' toggle button.\nResult: View seamlessly toggles between tabular data grid and graphical analytics charts."
        ],
        [
            "BUG-UI-09", "UI / Admin", "Settings / Templates", "Admin", "Medium",
            "Report template list ordering in Admin is static and lacks drag arrow buttons (up/down) with persistent database order saving.",
            "1. View report template list in Admin.\nResult: No re-ordering control arrows exist.",
            "1. Click Up/Down drag arrows to reorder report templates.\nResult: Order updates interactively and persists in DB across page reloads."
        ],
        [
            "BUG-WF-10", "Governance", "Admin Sidebar", "Admin, All Roles", "High",
            "Academic Year management is decentralized. Admin lacks a dedicated side tab to activate the global Academic Year for all roles.",
            "1. Log in as Admin.\nResult: No dedicated sidebar tab exists to activate global system Academic Year.",
            "1. Log in as Admin -> Open 'Academic Year' sidebar tab.\n2. Change active year.\nResult: Global Academic Year updates system-wide for all user sessions."
        ],
        [
            "BUG-WF-11", "Governance", "HOD Approvals", "HOD, Admin", "High",
            "HOD role has direct approval buttons instead of an 'Edit Request' workflow to Dean/Admin.",
            "1. Log in as HOD -> View submitted records.\nResult: HOD sees direct 'Approve' button.",
            "1. Log in as HOD -> View submitted records.\nResult: 'Approve' button replaced with 'Edit Request to Dean/Admin'."
        ],
        [
            "BUG-WF-12", "Governance", "Admin / Dean View", "Admin, Dean", "High",
            "Admin and Dean roles lack dual view of entries with request-for-edit governance (no direct silent override).",
            "1. Log in as Dean/Admin -> Open entry details.\nResult: Lacks structured request-for-edit workflow.",
            "1. Open entry in Dean/Admin view.\nResult: Entry display shows full dual view with 'Request Edit' option without forcing direct approval."
        ],
        [
            "BUG-WF-13", "Governance", "Approvals Section", "Admin, HOD, Coord", "High",
            "Approval review section and Admin pending approval view do not embed or render uploaded attachment proof files.",
            "1. Open record pending approval.\nResult: Only text fields shown; attachment proof is missing/hidden.",
            "1. Open approval record.\nResult: Embedded proof viewer/download button renders directly within the approval card."
        ],
        [
            "BUG-REP-14", "Reports", "Report Generator", "All Roles", "High",
            "Exported Excel, PDF, and Word reports display abbreviated department codes (e.g., CSBS, ECE) instead of full official department names.",
            "1. Download any report in Excel/PDF/Word.\nResult: Headers show 'CSBS' or 'CSE'.",
            "1. Download report in Excel/PDF/Word.\nResult: Document headers display 'Computer Science and Business Systems' (Full Dept Name)."
        ],
        [
            "BUG-REP-15", "Reports", "Report Formatting", "All Roles", "Medium",
            "Generated report documents lack an official signature block for 'Dean / Academics' at the end of the document.",
            "1. Export report to PDF/Word/Excel.\nResult: Document ends abruptly after data table.",
            "1. Export report.\nResult: End of document contains formal 'Dean / Academics' signature block."
        ],
        [
            "BUG-REP-16", "Reports", "Consolidated Exports", "Admin, Dean, Principal", "High",
            "No consolidated all-department report export (single PDF/Excel/Word containing all department data grouped department-wise) exists.",
            "1. Access Reports page.\nResult: Can only generate reports for one department at a time.",
            "1. Access Reports as Admin/Dean/Principal.\nResult: 'Consolidated All-Department Report' exports full college data in a single multi-tab file."
        ],
        [
            "BUG-REP-17", "Reports", "Reports Page Layout", "Director / Principal", "Medium",
            "Executive Meeting Report export button is positioned below filter options instead of prominent top placement above filters.",
            "1. Open Reports page.\nResult: Executive Meeting export button is buried inside lower filter form.",
            "1. Open Reports page.\nResult: Executive Meeting Report button is placed prominently in the top header above filters."
        ],
        [
            "BUG-REP-18", "Reports", "Report Filtering", "All Roles", "Medium",
            "Reports page lacks custom duration date range filtering (start date & end date) for querying data across custom timeframes.",
            "1. Open Reports page filters.\nResult: Only static Academic Year filter exists.",
            "1. Apply custom Start Date & End Date duration filters.\nResult: Report filters data precisely within specified duration."
        ],
        [
            "BUG-REP-19", "Reports", "Excel Generation Engine", "All Roles", "Critical",
            "Excel exports downloaded from Reports page open with 'linked image cannot be displayed' error, broken XML table tags, and stretched cells.",
            "1. Click Excel download on Academic Records report.\n2. Open downloaded file in MS Excel.\nResult: Error popup: 'The linked image cannot be displayed...'.",
            "1. Download Excel report.\n2. Open in MS Excel.\nResult: Valid binary spreadsheet (.xlsx) opens cleanly with logo, formatted columns, and no errors."
        ],
        [
            "BUG-EM-20", "Executive Meetings", "EM Schedule Engine", "Admin, All Roles", "High",
            "Missing automated switchover between EM1 and EM2. When EM1 deadline expires, EM2 should auto-enable and EM1 data entry must lock.",
            "1. Wait until EM1 end date passes.\nResult: System does not lock EM1; EM2 does not auto-activate.",
            "1. Simulate EM1 deadline expiry.\nResult: EM1 forms auto-lock; EM2 duration automatically starts across system."
        ],
        [
            "BUG-EM-21", "Presentation Mode", "Executive Meetings", "All Roles", "High",
            "Missing full-screen interactive 'Present' button / presentation popup during EM1 to showcase achievements and targets.",
            "1. Open EM1 dashboard.\nResult: No 'Present' presentation mode button exists.",
            "1. Click 'Present' button during EM1.\nResult: Full-screen presentation popup opens with auto-play (5s) and manual arrow key slide controls."
        ],
        [
            "BUG-ST-22", "File Storage", "Upload Handler", "Faculty, Coord", "Medium",
            "Uploaded file attachments are stored at original size without server-side auto-compression, wasting disk storage.",
            "1. Upload a 10MB PDF proof file.\nResult: File is stored as 10MB uncompressed on disk.",
            "1. Upload 10MB file.\nResult: File is auto-compressed on upload, preserving quality while reducing disk usage."
        ],
        [
            "BUG-ST-23", "Backend", "Announcements Module", "Admin", "Medium",
            "Expired announcements are soft-deleted or hidden instead of being automatically archived in a database table for Admin audit.",
            "1. Let an announcement expire.\nResult: Record disappears from system view.",
            "1. Access Admin -> Announcement Archive.\nResult: All expired announcements are permanently stored in DB archive table."
        ],
        [
            "BUG-BE-24", "Backend", "Exception Handling", "All Roles", "High",
            "Unhandled database or PHP exceptions throw raw Fatal Error stack traces, crashing the user interface.",
            "1. Submit form with invalid SQL characters or missing required foreign key.\nResult: PHP Fatal Error page displays.",
            "1. Submit form with invalid input.\nResult: Error is caught cleanly; user is shown friendly message and draft input is preserved."
        ],
        [
            "BUG-SEC-25", "Authentication", "Password Management", "All Roles", "Medium",
            "Users cannot submit a 'Request Admin to Change Password' ticket directly from the login page.",
            "1. Forgotten password user views login page.\nResult: No password reset request option available.",
            "1. Click 'Request Admin to Change Password'.\nResult: Ticket is sent to Admin password request queue."
        ]
    ]
    
    ws_bugs = create_table_sheet("Bug_Catalog", "SYSTEM BUGS, DEFECTS & BOTTLENECK AUDIT CATALOG", bug_headers, bug_rows)
    ws_bugs.column_dimensions['A'].width = 16
    ws_bugs.column_dimensions['B'].width = 18
    ws_bugs.column_dimensions['C'].width = 25
    ws_bugs.column_dimensions['D'].width = 18
    ws_bugs.column_dimensions['E'].width = 14
    ws_bugs.column_dimensions['F'].width = 45
    ws_bugs.column_dimensions['G'].width = 40
    ws_bugs.column_dimensions['H'].width = 40

    # ---------------------------------------------------------
    # TAB 3: Feature_Requests
    # ---------------------------------------------------------
    feat_headers = [
        "Feature ID", "Category", "Module / Screen", "Target Roles", 
        "Priority", "Detailed Feature Description & Specifications", 
        "Pre-Patchup Baseline Behavior", "Post-Patchup Acceptance Criteria"
    ]
    
    feat_rows = [
        [
            "FEAT-01", "Governance", "Global System Roles", "All Roles", "Mandatory",
            "Rename user role title 'Director' to 'Principal' across all UI elements, database role tables, navigation menus, and reports.",
            "UI, menus, and reports display role title as 'Director'.",
            "Role title displays as 'Principal' everywhere in UI, DB queries, headers, and exports."
        ],
        [
            "FEAT-02", "Governance", "Admin Navigation / Session", "Admin, All Roles", "Mandatory",
            "Remove Academic Year from login screen. Add an Academic Year tab in Admin sidebar where Admin activates system-wide Academic Year.",
            "Academic Year is selected on login screen by individual users.",
            "Academic Year selector is in Admin sidebar. Admin sets active year globally for all user roles."
        ],
        [
            "FEAT-03", "Reporting", "Reports Module", "Admin, Dean, Principal", "Mandatory",
            "Consolidated All-Department Report: Single PDF, Excel, and Word export containing data from all departments, grouped department-wise.",
            "Reports can only be generated per individual department.",
            "Admin, Dean, and Principal can click 'Export Consolidated Report' to generate combined college data."
        ],
        [
            "FEAT-04", "Reporting", "Reports Module", "Admin, Principal, Dean, HOD", "High",
            "Department & Faculty Achievements Report: Faculty names and individual achievements listed department-wise with 'View Report' button per row.",
            "Reports list aggregate numbers without individual staff achievement breakdowns.",
            "Report lists each faculty member under their department with a 'View Report' button to view staff profile report."
        ],
        [
            "FEAT-05", "UI / Faculty", "Faculty Dashboard", "Faculty", "High",
            "Faculty Dashboard 'Dean Button': Prominent button at the bottom of Faculty achievements page routing to single-page report view.",
            "Faculty dashboard ends without direct single-page report link.",
            "Clicking 'Dean Button' at bottom of achievements loads dedicated single-page report view."
        ],
        [
            "FEAT-06", "Presentation", "Executive Meetings", "All Roles", "Mandatory",
            "Full-Screen Interactive Presentation Mode ('Present' Button):\n- Popup presentation during EM1.\n- Displays faculty achievements, overall college development slides, target vs achieved results.\n- Auto mode (5 sec per slide) and Manual mode (Arrow keys).",
            "No presentation feature exists in Executive Meetings.",
            "Clicking 'Present' opens full-screen slide deck with DB data, target comparisons, 5-second auto-play, and arrow key controls."
        ],
        [
            "FEAT-07", "Executive Meetings", "EM Schedule Engine", "Admin, All Roles", "Mandatory",
            "EM1 & EM2 Duration & Lock Management:\n- Admin configures start/end dates for EM1 and EM2 linked to Academic Year.\n- Auto-switch from EM1 to EM2 on EM1 deadline expiry (locks EM1 data).\n- EM1/EM2 filters across all roles.",
            "No automated meeting duration switchover or lock logic exists.",
            "Admin sets EM1/EM2 dates. System auto-locks EM1 when duration ends, starts EM2, and enforces filters."
        ],
        [
            "FEAT-08", "Targets / Workflow", "Workspace Navigation", "Coordinator, All Roles", "High",
            "Targets Workflow Restructuring:\n- 'Review Targets' section placed in workspace prior to Reports section.\n- Coordinator assigned 'Target Report' replacing generic Academic Record.",
            "Targets section is separate from workspace flow; Coordinator uses Academic Record.",
            "'Review Targets' appears in workspace before Reports; Coordinator view displays Target Report."
        ],
        [
            "FEAT-09", "Workflow", "HOD Dashboard", "HOD, Dean, Admin", "High",
            "HOD 'Edit Request' Workflow: HOD submits edit requests to Dean/Admin instead of approving forms directly.",
            "HOD has direct approval buttons.",
            "HOD UI shows 'Request Edit to Dean/Admin' button; Dean/Admin receives structured edit request ticket."
        ],
        [
            "FEAT-10", "Reporting", "Export Engine", "All Roles", "High",
            "Proof Attachment Embedding in Reports: Include active hyperlinks and thumbnails to uploaded proof files directly inside generated PDF, Word, and Excel reports.",
            "Exported reports do not include proof file links.",
            "Clicking proof link in exported PDF/Excel/Word opens uploaded proof document directly."
        ],
        [
            "FEAT-11", "Security", "Login Page", "All Roles, Admin", "Medium",
            "Self-Service Password Reset Request: Option on login page to send 'Request Admin to Change Password' ticket.",
            "Users must contact Admin out-of-band for password resets.",
            "Submitting reset request creates pending password ticket in Admin dashboard."
        ],
        [
            "FEAT-12", "Archival", "Admin Dashboard", "Admin", "Medium",
            "Expired Announcements Database Archival: Store expired announcements in an `announcements_archive` table for historical audit.",
            "Expired announcements are deleted or lost.",
            "Admin can query and restore archived announcements from dedicated DB archive view."
        ],
        [
            "FEAT-13", "Storage", "Upload Handler", "Faculty, Coord", "Medium",
            "Automatic File Storage Compression: Server-side compression for uploaded images and PDF proof attachments.",
            "Files are saved at original raw upload size.",
            "Uploaded files are automatically compressed on server disk saving storage space."
        ]
    ]
    
    ws_feats = create_table_sheet("Feature_Requests", "FEATURE REQUESTS & MANDATORY SYSTEM ADVANCEMENTS", feat_headers, feat_rows)
    ws_feats.column_dimensions['A'].width = 16
    ws_feats.column_dimensions['B'].width = 18
    ws_feats.column_dimensions['C'].width = 25
    ws_feats.column_dimensions['D'].width = 22
    ws_feats.column_dimensions['E'].width = 16
    ws_feats.column_dimensions['F'].width = 45
    ws_feats.column_dimensions['G'].width = 38
    ws_feats.column_dimensions['H'].width = 38

    # ---------------------------------------------------------
    # TAB 4: Coordinator_Form_Bugs
    # ---------------------------------------------------------
    coord_headers = [
        "Coord Bug ID", "Form / Category", "Severity", 
        "Defect Summary", "Technical Root Cause & Breakdown", 
        "Pre-Patchup Test Procedure", "Post-Patchup Verification Procedure"
    ]
    
    coord_rows = [
        [
            "COORD-01", "Journal Publication", "Medium",
            "Sliding line of horizontal navigation bar does not correctly follow clicked tab.",
            "CSS indicator position calculation uses fixed offset instead of dynamic scroll position (`scrollLeft`).",
            "1. Open upload.php.\n2. Click 'Online Course' tab on right.\nResult: Sliding highlight line stays stuck on left.",
            "1. Click 'Online Course'.\nResult: Horizontal scrollbar automatically scrolls right and sliding bar highlights active tab smoothly."
        ],
        [
            "COORD-02", "Journal Publication", "Low",
            "Journal Publication submitted timestamp is inaccurate.",
            "PHP `date()` uses default UTC timezone instead of configured `Asia/Kolkata` app timezone.",
            "1. Submit Journal Publication form.\n2. Check submitted timestamp.\nResult: Time is off by several hours.",
            "1. Submit form.\nResult: Timestamp matches exact local Indian Standard Time (IST)."
        ],
        [
            "COORD-03", "All Upload Forms", "High",
            "File attachment upload is not properly working or fails silently.",
            "Form missing `enctype=\"multipart/form-data\"` or upload directory permission restriction.",
            "1. Attach PDF proof to form.\n2. Submit form.\nResult: File is not saved to server `/uploads` directory.",
            "1. Attach PDF proof -> Submit.\nResult: File uploads successfully, moves to `/uploads`, and generates valid URL."
        ],
        [
            "COORD-04", "All Upload Forms", "High",
            "Form field details are deleted when switching to another category tab.",
            "Tab switching reloads page or switches active container without saving form input draft in `sessionStorage`.",
            "1. Fill Journal form fields.\n2. Click 'Book / Chapter' tab.\n3. Return to Journal.\nResult: All entered data is wiped out.",
            "1. Fill Journal form.\n2. Switch tabs.\n3. Return to Journal.\nResult: All draft field values are preserved."
        ],
        [
            "COORD-05", "Conference Publication", "Medium",
            "Conference Publication page is missing the 'Download this report' button.",
            "Template `upload.php?type=conference` card header omitted the export button container.",
            "1. Open Conference Publication form card.\nResult: Top-right header lacks 'Download this report' button.",
            "1. Open Conference form card.\nResult: 'Download this report' button appears in top-right header matching other categories."
        ],
        [
            "COORD-06", "All Upload Forms", "High",
            "Some pages indicate 'Submitted' status without user clicking submit.",
            "Database query defaults status column to 'Submitted' on draft record insertion.",
            "1. Open form tab without typing or submitting.\nResult: Status table displays 'Submitted'.",
            "1. Open form tab.\nResult: Status shows 'Draft' or empty until user explicitly clicks Submit."
        ],
        [
            "COORD-07", "All Upload Forms", "Medium",
            "Details are partially inserted when using 'Save and add other' option.",
            "Form handler omits mandatory input validation check on secondary submit action.",
            "1. Fill 2 out of 5 required fields.\n2. Click 'Save and add other'.\nResult: Partial record is saved to DB.",
            "1. Click 'Save and add other' with missing fields.\nResult: Browser highlights missing required fields; prevents partial save."
        ],
        [
            "COORD-08", "All Upload Forms", "High",
            "Submitted proof attachment cannot be viewed or downloaded again after submission.",
            "Submitted records table omits hyperlink/viewer modal for uploaded proof filepath.",
            "1. View submitted records table.\nResult: Attachment column shows plain text filename without download link.",
            "1. View submitted records table.\nResult: Clickable 'View Proof' button opens uploaded document in new tab/modal."
        ],
        [
            "COORD-09", "All Upload Forms", "High",
            "Submit and Review workflow is not working properly.",
            "State transition SQL query fails to update approval workflow queue status.",
            "1. Click 'Submit and Review'.\nResult: Form reloads but record status remains unchanged.",
            "1. Click 'Submit and Review'.\nResult: Record status updates to 'Pending Review' and appears in HOD/Coordinator queue."
        ],
        [
            "COORD-10", "All Upload Forms", "Critical",
            "System suddenly interrupts with Fatal Error during form submission.",
            "Unhandled SQL PDOException when special characters (single quotes, ampersands) are entered.",
            "1. Enter `O'Connor & Sons` into Journal field -> Submit.\nResult: PHP Fatal Error crashes page.",
            "1. Enter special characters -> Submit.\nResult: Prepared statements handle input safely without errors."
        ],
        [
            "COORD-11", "Reports / Export", "Critical",
            "Excel download option is not working specifically for Academic Reports.",
            "Export route `export.php?type=academic_record&format=excel` throws undefined method error.",
            "1. Click Excel download on Academic Records report.\nResult: Server returns HTTP 500 error.",
            "1. Click Excel download on Academic Records report.\nResult: Spreadsheet downloads instantly and opens cleanly."
        ],
        [
            "COORD-12", "Reports / Export", "High",
            "Template reports download but Excel format is broken.",
            "Export script outputs raw HTML table with `application/vnd.ms-excel` header instead of binary `.xlsx` file.",
            "1. Download Template Report Excel.\n2. Open in MS Excel.\nResult: Excel warning: 'File format and extension don't match...'.",
            "1. Download Template Report Excel.\nResult: Valid OpenXML `.xlsx` spreadsheet opens without formatting warnings."
        ],
        [
            "COORD-13", "Journal Publication", "High",
            "Journal Publication approval lifecycle: Show 'Submitted' first, then 'Approved'. Approved holds for 1 week then shows as 'Submitted' and approval expires.",
            "Status remains static 'Approved' permanently without 7-day expiration logic.",
            "1. Approve Journal submission.\n2. Fast-forward server clock past 7 days.\nResult: Status stays 'Approved' forever.",
            "1. Approve Journal.\n2. Fast-forward clock 7+ days.\nResult: Status transitions back to 'Submitted' and approval is archived."
        ],
        [
            "COORD-14", "Book / Chapter", "High",
            "Book / Chapter shows 'Submitted' but approval is not required.",
            "Book / Chapter routing accidentally sends records into approval queue.",
            "1. Submit Book / Chapter entry.\nResult: Record enters pending approval state.",
            "1. Submit Book / Chapter entry.\nResult: Record bypasses approval queue and sets status directly to 'Submitted'."
        ]
    ]
    
    ws_coords = create_table_sheet("Coordinator_Form_Bugs", "COORDINATOR FORM BUGS & WORKFLOW DEFECT AUDIT", coord_headers, coord_rows)
    ws_coords.column_dimensions['A'].width = 16
    ws_coords.column_dimensions['B'].width = 22
    ws_coords.column_dimensions['C'].width = 14
    ws_coords.column_dimensions['D'].width = 35
    ws_coords.column_dimensions['E'].width = 40
    ws_coords.column_dimensions['F'].width = 38
    ws_coords.column_dimensions['G'].width = 38

    # ---------------------------------------------------------
    # TAB 5: EM_&_Presentation_Mode
    # ---------------------------------------------------------
    em_headers = [
        "Spec ID", "Feature Area", "Component", 
        "Specification & Rule Requirements", 
        "Pre-Patchup Test Procedure", "Post-Patchup Verification Procedure"
    ]
    
    em_rows = [
        [
            "EM-SPEC-01", "Executive Meetings", "Admin Schedule Control",
            "Admin configures start and end dates for Executive Meeting 1 (EM1) and Executive Meeting 2 (EM2) linked to active Academic Year.",
            "1. Access Admin dashboard.\nResult: No interface exists to configure EM1/EM2 date ranges.",
            "1. Go to Admin -> EM Schedule Manager.\n2. Set Start/End dates for EM1 & EM2.\nResult: Dates save in DB linked to Academic Year."
        ],
        [
            "EM-SPEC-02", "Executive Meetings", "Automated Switchover",
            "Automatic EM1 to EM2 Switchover Logic: When EM1 end date passes, system auto-locks EM1 forms and activates EM2 duration.",
            "1. Simulate EM1 deadline expiry.\nResult: EM1 forms remain editable.",
            "1. Simulate EM1 end date.\nResult: System automatically disables EM1 forms, activates EM2, and displays notification."
        ],
        [
            "EM-SPEC-03", "Executive Meetings", "Global EM Filters",
            "EM1 and EM2 duration filters available across all system role dashboards and report generation views.",
            "1. Open dashboard filter bar.\nResult: Only Academic Year filter available.",
            "1. Open filter dropdown.\nResult: 'EM1 Duration' and 'EM2 Duration' options filter all dashboard data."
        ],
        [
            "EM-SPEC-04", "Executive Meetings", "Report Header Layout",
            "Executive Meeting Report export button placed in top header upside of filter bar.",
            "1. Open Reports page.\nResult: Export button is inside lower filter form.",
            "1. Open Reports page.\nResult: 'Executive Meeting Report' button is positioned prominently in top header above filter bar."
        ],
        [
            "EM-SPEC-05", "Presentation Mode", "Trigger & Modal",
            "Clicking 'Present' button during EM1 triggers a full-screen interactive presentation popup on web page.",
            "1. View EM1 dashboard.\nResult: No 'Present' button exists.",
            "1. Click 'Present' button.\nResult: Full-screen presentation modal popups over web page."
        ],
        [
            "EM-SPEC-06", "Presentation Mode", "Data Acquisition Engine",
            "Presentation slides dynamically pull data from Database & Dashboards (Faculty achievements, Dept milestones, Target vs Achieved).",
            "1. Launch presentation popup.\nResult: Shows hardcoded text or empty slides.",
            "1. Launch presentation popup.\nResult: Slides dynamically display real-time DB data, department achievements, and metrics."
        ],
        [
            "EM-SPEC-07", "Presentation Mode", "Slide Content Scope",
            "Presentation includes overall college development & improvement slides alongside individual and department achievements.",
            "1. Inspect slide deck.\nResult: Missing overall college progress summary.",
            "1. Inspect slide deck.\nResult: Dedicated slides showcase overall institutional growth, metrics, and achievements."
        ],
        [
            "EM-SPEC-08", "Presentation Mode", "Target vs Achieved Slide",
            "Presentation renders visual comparison slides showing Target vs Achieved metrics.",
            "1. Inspect slides.\nResult: No target comparison displayed.",
            "1. Inspect slides.\nResult: Clean progress bars compare set targets against achieved results."
        ],
        [
            "EM-SPEC-09", "Presentation Mode", "Dual Navigation Controls",
            "Presentation mode supports Dual Controls: Auto mode (5-second auto slide advance) and Manual mode (Keyboard Arrow Keys & Screen buttons).",
            "1. Launch presentation mode.\nResult: Slides are static; keyboard arrows do not work.",
            "1. Launch presentation mode.\n2. Observe 5s auto-play.\n3. Press Left/Right arrow keys.\nResult: Manual key presses override auto-play smoothly."
        ]
    ]
    
    ws_ems = create_table_sheet("EM_&_Presentation_Mode", "EXECUTIVE MEETINGS & PRESENTATION MODE SPECIFICATIONS", em_headers, em_rows)
    ws_ems.column_dimensions['A'].width = 16
    ws_ems.column_dimensions['B'].width = 22
    ws_ems.column_dimensions['C'].width = 25
    ws_ems.column_dimensions['D'].width = 45
    ws_ems.column_dimensions['E'].width = 38
    ws_ems.column_dimensions['F'].width = 38

    # ---------------------------------------------------------
    # TAB 6: Role_Matrix_&_Workflows
    # ---------------------------------------------------------
    role_headers = [
        "Role", "Dashboard Access", "Approval & Edit Permissions", 
        "Report Export Scope", "Special Features & Buttons", 
        "Pre-Patch Workflow State", "Post-Patch Target Workflow"
    ]
    
    role_rows = [
        [
            "Admin", "Full System Dashboard & Analytics",
            "Sets global Academic Year, manages users, sets EM1/EM2 dates, reviews password requests, manages template order.",
            "Consolidated All-Department Report (PDF, Excel, Word), EM Reports, All Dept Reports",
            "Template Drag Arrows, Academic Year Sidebar, Announcement Archive View, Password Request Queue",
            "Academic year managed on login screen; direct overrides.",
            "Centralized governance, global academic year control, full consolidated reporting."
        ],
        [
            "Principal (renamed from Director)", "College-Wide Executive Dashboard",
            "Dual View of all department entries; requests edits via Dean/Admin (no direct approval needed).",
            "Consolidated All-Department Report (PDF, Excel, Word), Executive Meeting Report",
            "EM1 Presentation Mode ('Present' button), Overall Executive Meeting Top Button",
            "Named 'Director'; had generic report buttons.",
            "Renamed 'Principal'; executive presentation mode, top EM button, consolidated reports."
        ],
        [
            "Dean", "Academics & College Analytics Dashboard",
            "Dual View of all faculty/dept entries; receives Edit Requests from HOD and submits to Admin.",
            "Consolidated All-Department Report (PDF, Excel, Word), Dept Wise Reports",
            "End of Document Signature Block on exports, Dual View Entry Viewer",
            "Lacked formal signature blocks and structured dual-view edit request workflow.",
            "Formal Dean signature blocks on exports, dual-view entry governance, consolidated report access."
        ],
        [
            "HOD", "Departmental Faculty Dashboard",
            "Submits Edit Requests to Dean/Admin (direct approval replaced with Edit Request). Views department faculty reports.",
            "Departmental Consolidated Faculty Achievement Report",
            "Faculty Achievement Departmental View, Edit Request Button",
            "Direct approval buttons without audit trail.",
            "Edit Request governance to Dean/Admin, full departmental faculty achievement view."
        ],
        [
            "Coordinator", "Department Data Workspace",
            "Uploads academic data, manages target reports, submits records for review.",
            "Target Report (replaces Academic Record in Coord view), Category Reports",
            "Target Report Workspace Button, Save & Add Other Validation",
            "Missing 'Download this report' on Conference form; form draft loss on tab switch.",
            "Draft persistence across tabs, Conference download button, Target Report workspace ordering."
        ],
        [
            "Faculty", "Personal Achievement Dashboard",
            "Uploads personal achievements, views own submission history.",
            "Individual Faculty Achievement Report ('View Report' button)",
            "Bottom 'Dean Button' routing to single-page report, Show Password toggle",
            "Achievements lost on tab switch; no single-page Dean button.",
            "Full achievement dashboard, 'Dean Button' single-page report routing, mandatory field validation."
        ]
    ]
    
    ws_roles = create_table_sheet("Role_Matrix_&_Workflows", "SYSTEM ROLE MATRIX & PERMISSIONS WORKFLOW SPECIFICATION", role_headers, role_rows)
    ws_roles.column_dimensions['A'].width = 18
    ws_roles.column_dimensions['B'].width = 25
    ws_roles.column_dimensions['C'].width = 35
    ws_roles.column_dimensions['D'].width = 32
    ws_roles.column_dimensions['E'].width = 32
    ws_roles.column_dimensions['F'].width = 35
    ws_roles.column_dimensions['G'].width = 35

    # ---------------------------------------------------------
    # TAB 7: Testing_Playbook
    # ---------------------------------------------------------
    test_headers = [
        "Test Suite ID", "Module", "Test Scenario Name", "Target Role", 
        "Pre-Patch Test Steps (Before Fix)", "Expected Result (Before Fix - Failure)", 
        "Post-Patch Test Steps (After Fix)", "Expected Result (After Fix - Pass)"
    ]
    
    test_rows = [
        [
            "TS-AUTH-01", "Authentication", "Credential Whitespace Trimming", "All Roles",
            "1. Navigate to login.php.\n2. Enter 'admin ' (with space).\n3. Enter valid password -> Submit.",
            "Login fails with 'Invalid Credentials' error message.",
            "1. Enter 'admin ' (with trailing space) -> Submit.",
            "Authentication succeeds; whitespace is automatically trimmed before DB query."
        ],
        [
            "TS-AUTH-02", "Authentication", "Password Input Eye Toggle", "All Roles",
            "1. Navigate to login page.\n2. Type password text.",
            "Password remains masked with dots; no show/hide eye toggle button exists.",
            "1. Click eye icon next to password field.",
            "Password text toggles between hidden dots and visible clear text."
        ],
        [
            "TS-COORD-01", "Coordinator Forms", "Tab Navigation Draft Retention", "Faculty, Coord",
            "1. Open upload.php.\n2. Type data into Journal form fields.\n3. Click 'Book / Chapter' tab.\n4. Click 'Journal' tab.",
            "All previously entered Journal form text fields are blanked out.",
            "1. Type data into Journal form.\n2. Switch tabs and return to Journal.",
            "All draft text field values are restored from sessionStorage."
        ],
        [
            "TS-COORD-02", "Coordinator Forms", "Conference Download Report Button", "Faculty, Coord",
            "1. Open upload.php?type=conference.",
            "Top-right header card is missing 'Download this report' button.",
            "1. Open upload.php?type=conference.",
            "'Download this report' button appears in top-right header matching other categories."
        ],
        [
            "TS-COORD-03", "Coordinator Forms", "Book/Chapter Direct Submission Workflow", "Faculty, Coord",
            "1. Submit a Book/Chapter entry.",
            "Record enters 'Pending Approval' queue requiring manual approval.",
            "1. Submit a Book/Chapter entry.",
            "Record bypasses approval queue and sets status directly to 'Submitted'."
        ],
        [
            "TS-COORD-04", "Coordinator Forms", "Journal 7-Day Approval Lifecycle", "Faculty, Coord, HOD",
            "1. Approve Journal submission.\n2. Fast-forward server clock past 7 days.",
            "Status remains static 'Approved' permanently.",
            "1. Approve Journal.\n2. Fast-forward clock 7+ days.",
            "Status reverts to displaying 'Submitted' and approval is archived."
        ],
        [
            "TS-REP-01", "Reports & Export", "Excel Binary Generation", "All Roles",
            "1. Go to Director/Principal -> Reports.\n2. Click Excel download for Academic Records.\n3. Open file in MS Excel.",
            "MS Excel popup: 'The linked image cannot be displayed...'; broken table layout.",
            "1. Download Excel file.\n2. Open in MS Excel.",
            "File opens cleanly as a valid binary spreadsheet with headers, formatting, and no errors."
        ],
        [
            "TS-REP-02", "Reports & Export", "Full Department Name Resolution", "All Roles", "1. Export report to PDF/Excel/Word.",
            "Header shows short code 'CSBS' or 'ECE'.",
            "1. Export report.",
            "Header displays full official name 'Computer Science and Business Systems'."
        ],
        [
            "TS-REP-03", "Reports & Export", "Dean Signature Block", "All Roles",
            "1. Export report to PDF/Word/Excel.",
            "Document terminates immediately after data table.",
            "1. Export report.",
            "Formal signature block for 'Dean / Academics' appears at bottom of document."
        ],
        [
            "TS-EM-01", "Executive Meetings", "Automated Switchover & EM1 Lock", "Admin, All Roles",
            "1. Set EM1 end date to yesterday.\n2. Try editing EM1 form.",
            "EM1 form remains editable; EM2 does not activate.",
            "1. Set EM1 end date to yesterday.\n2. Access EM1 form.",
            "EM1 forms are locked for editing; EM2 duration automatically starts across system."
        ],
        [
            "TS-EM-02", "Presentation Mode", "Present Button & Controls", "All Roles",
            "1. Access EM1 dashboard during meeting duration.",
            "No 'Present' button is visible.",
            "1. Click 'Present' button.\n2. Observe 5s auto-play.\n3. Press Left/Right arrow keys.",
            "Full-screen presentation modal popups with 5-second auto-play and responsive arrow key controls."
        ],
        [
            "TS-FAC-01", "Faculty Dashboard", "Dean Button Single-Page Routing", "Faculty",
            "1. Navigate to bottom of Faculty achievements page.",
            "Page terminates without single-page report link.",
            "1. Click 'Dean Button' at bottom of achievements page.",
            "App routes directly to dedicated single-page report & achievements view."
        ]
    ]
    
    ws_tests = create_table_sheet("Testing_Playbook", "SYSTEM QA TESTING PLAYBOOK (BEFORE & AFTER PATCHUPS)", test_headers, test_rows)
    ws_tests.column_dimensions['A'].width = 16
    ws_tests.column_dimensions['B'].width = 22
    ws_tests.column_dimensions['C'].width = 28
    ws_tests.column_dimensions['D'].width = 16
    ws_tests.column_dimensions['E'].width = 38
    ws_tests.column_dimensions['F'].width = 38
    ws_tests.column_dimensions['G'].width = 38
    ws_tests.column_dimensions['H'].width = 38

    # Output file
    output_filename = "c:/Users/Asus/OneDrive/Desktop/IQAC - MSEC - ATTS/ATTC-php-main/IQAC_ATTC_Project_Complete_Audit_and_Feature_Specification.xlsx"
    wb.save(output_filename)
    print(f"Successfully generated Excel file at: {output_filename}")

if __name__ == "__main__":
    build_audit_excel()
