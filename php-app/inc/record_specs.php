<?php
/**
 * Record report templates — the exact column layout of each IQAC report, taken
 * from the official Excel/Word templates.
 *
 * For each record type: the report title, an optional subtitle, and the columns
 * in order as [label, field]. `field` is the DB column to print, or '#' for the
 * auto S.No. The `department` column is the consolidated "Dept" column — it is
 * shown only in an all-departments report; a single-department report drops it
 * and puts DEPARTMENT OF <name> in the heading (this is exactly how the Excel
 * template — which spans departments — differs from the Word one, which is per
 * department).
 *
 * record-report.php renders any of these; adding a new report type is just a new
 * entry here plus its upload-form fields.
 */

function record_report_specs(): array
{
    return [

        'journal' => [
            'title'   => 'JOURNAL PUBLICATIONS',
            'columns' => [
                ['S.No', '#'],
                ['Name of the Faculty', 'faculty_name'],
                ['Dept', 'department'],
                ['Author Type', 'author_type'],
                ['Names of Co-Authors at MSEC', 'co_authors'],
                ['Title of the Paper', 'paper_title'],
                ['Journal Name', 'journal_name'],
                ['Journal Type', 'journal_type'],
                ['ISSN Number', 'issn'],
                ['Volume & Issue No', 'volume_issue'],
                ['Month & Year of Publication (mm/yyyy)', 'publication_month'],
                ['Link to the Article / DOI', 'doi'],
                ['Link to Journal Website', 'journal_link'],
                ['Document Link', 'document_link'],
            ],
        ],

        'book' => [
            'title'   => 'BOOK / BOOK CHAPTER PUBLICATIONS',
            'columns' => [
                ['S.No', '#'],
                ['Name of the Faculty', 'faculty_name'],
                ['Dept', 'department'],
                ['Book / Book Chapter', 'publication_category'],
                ['Title of the Book / Book Chapter', 'title'],
                ['Publisher Name', 'publisher_name'],
                ['ISSN / ISBN Number', 'isbn'],
                ['Month & Year of Publication (mm/yyyy)', 'publication_month'],
                ['Document Link', 'document_link'],
            ],
        ],

        'event' => [
            'title'    => 'LIST OF EVENTS ORGANIZED',
            'subtitle' => '(SEMINAR / WORKSHOP / WEBINAR / FDP / CONFERENCE / SYMPOSIUM)',
            'columns'  => [
                ['S.No', '#'],
                ['Dept', 'department'],
                ['Date (dd/mm/yyyy)', 'event_date'],
                ['Event Title', 'event_title'],
                ['Event Type', 'event_type'],
                ['Mode', 'mode'],
                ['Chief Guest / Resource Person Name & Designation (with Contact Details)', 'resource_person'],
                ['No. of Participants', 'participants'],
                ['Sponsorship (if any)', 'sponsorship'],
                ['Web Link to Event Report', 'report_link'],
            ],
        ],

        'fdp' => [
            'title'   => 'FACULTY PARTICIPATIONS IN FDP / WORKSHOP / SEMINAR',
            'columns' => [
                ['S.No', '#'],
                ['Name of the Faculty', 'faculty_name'],
                ['Dept', 'department'],
                ['Duration', 'duration'],
                ['Event Type', 'event_type'],
                ['Name of the FDP / Seminar / Workshop', 'title'],
                ['Mode', 'mode'],
                ['Organized By (Name of the Institution / Agency)', 'organized_by'],
                ['Certificate Link', 'certificate_link'],
            ],
        ],

        'mou' => [
            'title'   => 'LIST OF MoUs SIGNED',
            'columns' => [
                ['S.No', '#'],
                ['Dept', 'department'],
                ['Signed Date (dd/mm/yyyy)', 'signed_date'],
                ['Name & Address of the Collaborating Body (Industry / Institution / Agency / etc.,)', 'organization'],
                ['Valid upto (dd/mm/yyyy)', 'valid_upto'],
                ['Purpose of Collaboration', 'purpose'],
                ['Document Link', 'document_link'],
            ],
        ],

        'nptel' => [
            'title'   => 'SWAYAM-NPTEL COURSE COMPLETION',
            'columns' => [
                ['S.No', '#'],
                ['Candidate Name', 'candidate_name'],
                ['Dept', 'department'],
                ['Category', 'category'],
                ['Course Title', 'course_title'],
                ['Session', 'session'],
                ['Grade', 'grade'],
                ['Certificate Link', 'certificate_link'],
            ],
        ],

        'patent' => [
            'title'   => 'PATENTS & COPYRIGHTS',
            'columns' => [
                ['S.No', '#'],
                ['Name of the Faculty', 'faculty_name'],
                ['Dept', 'department'],
                ['Patent / Copyright', 'category'],
                ['Title of the Patent / Copyright', 'title'],
                ['Patent / Copyright Number', 'patent_number'],
                ['Date of Publication (dd/mm/yyyy)', 'publication_date'],
                ['Document Link', 'document_link'],
            ],
        ],

        'internship' => [
            'title'   => 'STUDENTS INTERNSHIP DETAILS',
            'columns' => [
                ['S.No', '#'],
                ['Reg. No', 'reg_no'],
                ['Name of the student', 'student_name'],
                ['Dept / Branch', 'department'],
                ['Title of Internship', 'title'],
                ['Industry/Institution Name & Address', 'industry'],
                ['Duration', 'duration'],
                ['No. of Days', 'days'],
                ['Link to the Certificate / Document', 'certificate_link'],
            ],
        ],

        'placement' => [
            'title'   => 'LIST OF PLACEMENTS',
            'columns' => [
                ['S. No', '#'],
                ['Reg. No', 'reg_no'],
                ['Student Name', 'student_name'],
                ['Dept / Branch', 'department'],
                ['Job Name', 'job_title'],
                ['Mode (On Campus / Off Campus)', 'mode'],
                ['Company Name & Address (with Contact Details)', 'company'],
                ['Pay Scale', 'pay_scale'],
                ['Web Link to Appointment Order', 'appointment_order_link'],
            ],
        ],

        'nss' => [
            'title'   => 'NSS / YRC / RRC PROGRAMMES ORGANIZED',
            'columns' => [
                ['S.No', '#'],
                ['Date (dd/mm/yyyy)', 'activity_date'],
                ['Name of the Activity', 'activity_name'],
                ['Activity Type', 'activity_type'],
                ['Venue', 'venue'],
                ['Name of external Agency / Member involved (Name & Designation with Contact Details)', 'external_agency'],
                ['No. of Student Participated', 'participants'],
                ['Web Link to Event Report', 'report_link'],
            ],
        ],

        'online_course' => [
            'title'   => 'ONLINE COURSES COMPLETED',
            'columns' => [
                ['S.No', '#'],
                ['Candidate Name', 'candidate_name'],
                ['Dept', 'department'],
                ['Category', 'category'],
                ['Course Title', 'course_title'],
                ['Provider', 'provider'],
                ['Duration', 'duration'],
                ['Month & Year', 'month_year'],
                ['Certificate Link', 'certificate_link'],
            ],
        ],

        'student_achievement' => [
            'title'   => 'STUDENTS ACHIEVEMENTS',
            'columns' => [
                ['S.No', '#'],
                ['Reg. No', 'reg_no'],
                ['Name of the student', 'student_name'],
                ['Dept / Branch', 'department'],
                ['Event Type', 'event_type'],
                ['Name of the Event', 'event_name'],
                ['Name of the Function / Programme', 'function_name'],
                ['Date of the event', 'event_date'],
                ['Team / Individual', 'team_individual'],
                ['University / State / National / International', 'level_secured'],
                ['Position secured', 'position_secured'],
                ['Name of the Organising Institution', 'organising_institution'],
                ['Link to the Certificate / Document', 'certificate_link'],
            ],
        ],

        'student_participation' => [
            'title'   => 'STUDENTS PARTICIPATIONS',
            'columns' => [
                ['S.No', '#'],
                ['Reg. No', 'reg_no'],
                ['Name of the student', 'student_name'],
                ['Dept / Branch', 'department'],
                ['Event Type', 'event_type'],
                ['Name of the Event', 'event_name'],
                ['Name of the Function / Programme', 'function_name'],
                ['Date of the event', 'event_date'],
                ['Team / Individual', 'team_individual'],
                ['University / State / National / International', 'level_secured'],
                ['Position secured', 'position_secured'],
                ['Name of the Organising Institution', 'organising_institution'],
                ['Link to the Certificate / Document', 'certificate_link'],
            ],
        ],

        'summer_training' => [
            'title'   => 'STUDENTS TRAINING (SUMMER / WINTER) DETAILS',
            'columns' => [
                ['S.No', '#'],
                ['Reg. No', 'reg_no'],
                ['Name of the student', 'student_name'],
                ['Dept / Branch', 'department'],
                ['Title of Training', 'title'],
                ['Industry/Institution Name & Address', 'industry'],
                ['Duration', 'duration'],
                ['No. of Days', 'days'],
                ['Link to the Certificate / Document', 'certificate_link'],
            ],
        ],

        'value_added' => [
            'title'   => 'LIST OF VALUE ADDED COURSES CONDUCTED',
            'columns' => [
                ['S.No', '#'],
                ['Dept', 'department'],
                ['From (dd/mm/yyyy)', 'from_date'],
                ['To (dd/mm/yyyy)', 'to_date'],
                ['Course Title', 'course_title'],
                ['Mode', 'mode'],
                ['Resource Person Name & Designation (with Contact Details)', 'resource_person'],
                ['No. of Participants', 'participants'],
                ['Web Link to Event Report', 'report_link'],
            ],
        ],

        'training' => [
            'title'    => 'LIST OF EVENTS ORGANIZED',
            'subtitle' => '(CAREER GUIDANCE / COUNSELLING / ICT / LIFE SKILLS / SOFT SKILLS TRAINING)',
            'columns'  => [
                ['S.No', '#'],
                ['Dept', 'department'],
                ['Date (dd/mm/yyyy)', 'event_date'],
                ['Event Title', 'event_title'],
                ['Event Type', 'event_type'],
                ['Mode', 'mode'],
                ['Chief Guest / Resource Person Name & Designation (with Contact Details)', 'resource_person'],
                ['No. of Participants', 'participants'],
                ['Sponsorship (if any)', 'sponsorship'],
                ['Web Link to Event Report', 'report_link'],
            ],
        ],

    ];
}

/** One report's spec, or null for a type that has no template yet. */
function record_report_spec(string $type): ?array
{
    return record_report_specs()[$type] ?? null;
}
