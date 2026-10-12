<?php
/**
 * ATTS-SMARTBULK — Production-Ready Bulk Student Import Engine
 *
 * Handles Excel template generation, workbook parsing, data extraction,
 * multi-level validation, duplicate detection, transactional persistence,
 * error reporting, and audit logging for Internship and Summer/Winter Training.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/xlsx_writer.php';
require_once __DIR__ . '/../inc/xlsx_reader.php';
require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/Department.php';
require_once __DIR__ . '/Target.php';

class BulkImport
{
    public const MODULE_INTERNSHIP = 'internship';
    public const MODULE_SUMMER_TRAINING = 'summer_training';

    /**
     * Module configuration definition.
     */
    public static function getModuleConfig(string $module): ?array
    {
        $configs = [
            self::MODULE_INTERNSHIP => [
                'key'             => self::MODULE_INTERNSHIP,
                'label'           => 'Internship',
                'table'           => 'internships',
                'title_col'       => 'title',
                'sheet_name'      => 'Internship Records',
                'template_file'   => 'ATTS_Internship_Bulk_Template.xlsx',
                'error_file'      => 'Internship_Validation_Errors.xlsx',
                'item_type_label' => 'Internship',
            ],
            self::MODULE_SUMMER_TRAINING => [
                'key'             => self::MODULE_SUMMER_TRAINING,
                'label'           => 'Summer / Winter Training',
                'table'           => 'summer_training',
                'title_col'       => 'title',
                'sheet_name'      => 'Training Records',
                'template_file'   => 'ATTS_Summer_Winter_Training_Bulk_Template.xlsx',
                'error_file'      => 'Training_Validation_Errors.xlsx',
                'item_type_label' => 'Summer/Winter Training',
            ],
        ];

        return $configs[$module] ?? null;
    }

    /**
     * Column specifications and aliases for normalization.
     */
    public static function getColumnAliases(string $module): array
    {
        return [
            'reg_no' => [
                'student register number', 'register number', 'reg no', 'reg_no',
                'student reg no', 'register no', 'student id', 'roll no', 'roll number'
            ],
            'student_name' => [
                'student name', 'name of the student', 'student_name', 'candidate name', 'candidate_name', 'student'
            ],
            'department' => [
                'department', 'dept', 'branch', 'dept branch', 'department branch'
            ],
            'academic_year' => [
                'academic year', 'academic_year'
            ],
            'exam_session' => [
                'exam session', 'academic session', 'exam_session', 'academic_session', 'session'
            ],
            'title' => [
                'title of internship', 'title of training', 'internship title', 'training title', 'title', 'programme title', 'topic'
            ],
            'industry' => [
                'organization company provider', 'industry institution name address',
                'industry institution name and address', 'industry', 'organization', 'company',
                'company name', 'training provider', 'institution', 'industry name'
            ],
            'duration' => [
                'duration', 'internship duration', 'training duration', 'period'
            ],
            'days' => [
                'no of days', 'number of days', 'days', 'total days'
            ],
            'certificate_link' => [
                'certificate document link', 'link to the certificate document', 'link to certificate document',
                'certificate link', 'certificate_link', 'proof link', 'document link', 'weblink', 'url',
                'certificate document link or upload in zip'
            ],
            'proof_file_name' => [
                'proof file name', 'proof file', 'proof document', 'proof', 'attachment file', 'certificate file', 'pdf file', 'filename'
            ],
        ];
    }

    /**
     * Generate an Excel Template with Instructions and Sample Data.
     */
    public static function generateTemplate(string $module, string $activeYear, ?string $userDept = null): string
    {
        $cfg = self::getModuleConfig($module);
        if (!$cfg) {
            throw new InvalidArgumentException("Unsupported module: {$module}");
        }

        $isIntern = ($module === self::MODULE_INTERNSHIP);
        $titleColHeader = $isIntern ? 'Title of Internship *' : 'Title of Training *';
        $orgColHeader   = $isIntern ? 'Organization / Company Name & Address *' : 'Training Provider / Institution & Address *';

        $headers = [
            'Student Register Number *',
            'Student Name *',
            'Department *',
            'Academic Year *',
            'Exam Session *',
            $titleColHeader,
            $orgColHeader,
            'Duration *',
            'No. of Days *',
            'Proof File Name in ZIP (Optional - defaults to <RegNo>.pdf)',
        ];

        $deptVal = $userDept ?: 'CSE';
        $sessionVal = function_exists('exam_session_current') ? exam_session_current() : 'Odd';

        // Sample rows clearly designated as example rows
        if ($isIntern) {
            $sampleRows = [
                [
                    '[EXAMPLE - DELETE BEFORE IMPORT] 711322104001',
                    'S. Arun Kumar',
                    $deptVal,
                    $activeYear,
                    $sessionVal,
                    'Full Stack Web Development Internship',
                    'Zoho Corporation, Estancia IT Park, Chennai',
                    '1 month',
                    30,
                    '711322104001.pdf',
                ],
                [
                    '[EXAMPLE - DELETE BEFORE IMPORT] 711322104002',
                    'R. Priya Dharshini',
                    $deptVal,
                    $activeYear,
                    $sessionVal,
                    'Data Analytics & BI Internship',
                    'Cognizant Technology Solutions, Coimbatore',
                    '4 weeks',
                    28,
                    '711322104002.pdf',
                ],
            ];
        } else {
            $sampleRows = [
                [
                    '[EXAMPLE - DELETE BEFORE IMPORT] 711322104001',
                    'S. Arun Kumar',
                    $deptVal,
                    $activeYear,
                    $sessionVal,
                    'Industrial IoT & Embedded Systems Training',
                    'L&T EduTech Center, Chennai',
                    '2 weeks',
                    14,
                    '711322104001.pdf',
                ],
                [
                    '[EXAMPLE - DELETE BEFORE IMPORT] 711322104002',
                    'R. Priya Dharshini',
                    $deptVal,
                    $activeYear,
                    $sessionVal,
                    'Hands-on Cloud DevOps Winter Training',
                    'AWS Academy Training Partner, Chennai',
                    '15 days',
                    15,
                    '711322104002.pdf',
                ],
            ];
        }

        // Instruction worksheet
        $instHeaders = ['Field Name', 'Mandatory?', 'Accepted Format / Allowed Values', 'Guidelines & Rules'];
        $instRows = [
            [
                'Student Register Number',
                'Yes',
                'Alphanumeric register number (e.g. 711322104001, 21CS001)',
                'Unique university or institutional register number. Used for student record matching and auto-attaching ZIP proofs.',
            ],
            [
                'Student Name',
                'Yes',
                'Text (e.g. S. Arun Kumar)',
                'Full student name as recorded in official college enrollment.',
            ],
            [
                'Department',
                'Yes',
                'Department code (e.g. CSE, IT, ECE, EEE, MECH, CIVIL, AI & DS, CSBS)',
                'Must match your authorized academic department. Cross-department uploads are prevented by system authorization.',
            ],
            [
                'Academic Year',
                'Yes',
                'YYYY-YY format (e.g. ' . $activeYear . ')',
                'Must match the academic year of the activity. Active academic year is ' . $activeYear . '.',
            ],
            [
                'Exam Session',
                'Yes',
                'Odd or Even',
                'Academic semester cycle: Odd (Semester 1, 3, 5, 7) or Even (Semester 2, 4, 6, 8).',
            ],
            [
                $isIntern ? 'Title of Internship' : 'Title of Training',
                'Yes',
                'Text (minimum 3 characters)',
                'Official topic or domain title of the completed internship or training program.',
            ],
            [
                $isIntern ? 'Organization / Company Name & Address' : 'Training Provider / Institution & Address',
                'Yes',
                'Text (minimum 3 characters)',
                'Full company or provider name, city/state, or research organization.',
            ],
            [
                'Duration',
                'Yes',
                'Text (e.g. "1 month", "4 weeks", "15 days")',
                'Descriptive duration representation.',
            ],
            [
                'No. of Days',
                'Yes',
                'Positive integer (e.g. 30, 14)',
                'Total number of days spent. Must be a numeric whole number greater than 0.',
            ],
            [
                'Proof File Name in ZIP',
                'Optional (Defaults to <RegNo>.pdf)',
                'Filename inside ZIP (e.g. 711322104001.pdf)',
                'Leave blank if your certificate PDF inside the ZIP is named as <RegisterNumber>.pdf (recommended). Only specify if using a custom filename.',
            ],
            [
                'BULK PROOFS: Compulsory ZIP Archive',
                'Compulsory (*)',
                'Single .zip archive containing all student certificates',
                'Every student record MUST have a corresponding certificate PDF (<= 2 MB) inside the uploaded ZIP archive. Name each PDF as <RegisterNumber>.pdf (e.g. 711322104001.pdf). The system matches them 1:1 automatically.',
            ],
            [
                'GENERAL RULE: Multiple Students',
                'Info',
                '1 row per student record',
                'Enter multiple student records row-by-row. Do not combine multiple students into one row.',
            ],
            [
                'GENERAL RULE: Sample Rows',
                'Info',
                'Delete or overwrite example rows',
                'Rows starting with [EXAMPLE] are automatically filtered out during validation.',
            ],
            [
                'GENERAL RULE: Duplicates',
                'Info',
                'Automatic duplicate prevention',
                'Duplicate records matching existing student entries in the database are detected and flagged.',
            ],
        ];

        return SimpleXlsxWriter::createMultiSheetXlsx([
            [
                'title'   => $cfg['sheet_name'],
                'headers' => $headers,
                'rows'    => $sampleRows,
                'meta'    => [],
            ],
            [
                'title'   => 'Instructions',
                'headers' => $instHeaders,
                'rows'    => $instRows,
                'meta'    => [
                    'ATTS SmartBulk Import Guidelines — ' . $cfg['label'],
                    'Review all required formats before uploading. Ensure all fields are filled accurately.',
                ],
            ],
        ]);
    }

    /**
     * Map a raw header string to standard column name using alias dictionary.
     */
    public static function mapHeaderToField(string $header, string $module): ?string
    {
        $clean = strtolower(trim(preg_replace('/[\*\(].*?[\)\*]/', '', $header)));
        $clean = preg_replace('/[^a-z0-9_\s]/', '', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        if ($clean === '') {
            return null;
        }

        $aliases = self::getColumnAliases($module);

        // Pass 1: Exact matches
        foreach ($aliases as $field => $patterns) {
            foreach ($patterns as $pattern) {
                $cleanPattern = preg_replace('/[^a-z0-9_\s]/', '', strtolower($pattern));
                $cleanPattern = trim(preg_replace('/\s+/', ' ', $cleanPattern));
                if ($clean === $cleanPattern) {
                    return $field;
                }
            }
        }

        // Pass 2: Whole word boundary matches
        foreach ($aliases as $field => $patterns) {
            foreach ($patterns as $pattern) {
                $cleanPattern = preg_replace('/[^a-z0-9_\s]/', '', strtolower($pattern));
                $cleanPattern = trim(preg_replace('/\s+/', ' ', $cleanPattern));
                if (strlen($cleanPattern) >= 3 && preg_match('/\b' . preg_quote($cleanPattern, '/') . '\b/i', $clean)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Delete a directory and all its contents recursively.
     */
    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? self::deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Safely extract and index PDF proofs from an uploaded ZIP archive.
     */
    public static function extractAndIndexProofsZip(string $zipPath, string $batchId): array
    {
        if (!class_exists('ZipArchive') || !is_file($zipPath) || filesize($zipPath) === 0) {
            return [
                'temp_dir'    => null,
                'by_reg_no'   => [],
                'by_filename' => [],
                'total_pdfs'  => 0,
                'errors'      => [],
            ];
        }

        $scratchDir = dirname(__DIR__, 2) . '/scratch';
        if (!is_dir($scratchDir)) {
            @mkdir($scratchDir, 0775, true);
        }
        $tempDir = $scratchDir . '/proofs_' . preg_replace('/[^a-zA-Z0-9_]/', '', $batchId);
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return [
                'temp_dir'    => $tempDir,
                'by_reg_no'   => [],
                'by_filename' => [],
                'total_pdfs'  => 0,
                'errors'      => ['Failed to open ZIP archive (code ' . $res . ').'],
            ];
        }

        $byRegNo    = [];
        $byFilename = [];
        $errors     = [];
        $count      = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) continue;

            $normalized = str_replace('\\', '/', $entryName);
            if (str_contains($normalized, '../') || str_starts_with($normalized, '/') || str_contains($normalized, '__MACOSX')) {
                continue;
            }

            $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                continue;
            }

            $baseName = basename($normalized);
            $stat = $zip->statIndex($i);
            $size = $stat['size'] ?? 0;
            if ($size > 2 * 1024 * 1024) { // 2MB max
                $errors[] = "File '{$baseName}' in ZIP exceeds maximum allowed size of 2 MB.";
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false || strlen($content) === 0) {
                continue;
            }

            if (substr($content, 0, 4) !== '%PDF') {
                $errors[] = "File '{$baseName}' in ZIP is not a valid PDF document (missing %PDF signature).";
                continue;
            }

            $safeFilename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $baseName);
            $destFile = $tempDir . '/' . $safeFilename;
            file_put_contents($destFile, $content);

            $rawNameNoExt = strtolower(pathinfo($baseName, PATHINFO_FILENAME));
            $cleanReg = preg_replace('/[^a-zA-Z0-9]/', '', $rawNameNoExt);

            $byFilename[strtolower($baseName)] = $destFile;
            $byFilename[$rawNameNoExt]          = $destFile;

            if ($cleanReg !== '') {
                $byRegNo[strtolower($cleanReg)] = $destFile;
            }
            $count++;
        }

        $zip->close();

        return [
            'temp_dir'    => $tempDir,
            'by_reg_no'   => $byRegNo,
            'by_filename' => $byFilename,
            'total_pdfs'  => $count,
            'errors'      => $errors,
        ];
    }

    /**
     * Parse and validate an uploaded Excel file for bulk import.
     */
    public static function validateUpload(string $module, string $filePath, array $currentUser, string $activeYear, ?string $zipPath = null): array
    {
        $cfg = self::getModuleConfig($module);
        if (!$cfg) {
            throw new InvalidArgumentException("Invalid module specified: {$module}");
        }

        // Parse workbook into sheets
        $sheets = SimpleXlsxReader::parse($filePath);
        if (empty($sheets)) {
            throw new RuntimeException("Uploaded workbook contains no data.");
        }

        // Get primary data sheet
        $rows = SimpleXlsxReader::getPrimaryDataSheet($sheets);
        if (empty($rows) || count($rows) < 2) {
            throw new RuntimeException("Spreadsheet contains no student data rows. Ensure you have entered student records below the header row.");
        }

        // 1. Locate header row within first 5 rows
        $headerRowIdx = null;
        $fieldIndexMap = []; // field -> colIndex
        $detectedHeaders = [];

        for ($r = 0; $r < min(5, count($rows)); $r++) {
            $candidateRow = $rows[$r];
            $matchedFields = [];
            foreach ($candidateRow as $cIdx => $cell) {
                $cellStr = trim((string)$cell);
                if ($cellStr === '') continue;
                $f = self::mapHeaderToField($cellStr, $module);
                if ($f !== null && !isset($matchedFields[$f])) {
                    $matchedFields[$f] = $cIdx;
                }
            }

            // A valid header row must at least match reg_no and student_name
            if (isset($matchedFields['reg_no']) && isset($matchedFields['student_name'])) {
                $headerRowIdx = $r;
                $fieldIndexMap = $matchedFields;
                $detectedHeaders = $candidateRow;
                break;
            }
        }

        if ($headerRowIdx === null) {
            throw new RuntimeException("Could not detect required column headers (Student Register Number, Student Name) in the spreadsheet. Please download and use the official ATTS template.");
        }

        // Check required fields presence in headers
        $requiredFields = ['reg_no', 'student_name', 'title', 'industry', 'duration', 'days'];
        $missingHeaders = [];
        foreach ($requiredFields as $rf) {
            if (!isset($fieldIndexMap[$rf])) {
                $missingHeaders[] = ucwords(str_replace('_', ' ', $rf));
            }
        }
        if (!empty($missingHeaders)) {
            throw new RuntimeException("The uploaded file is missing mandatory columns: " . implode(', ', $missingHeaders) . ". Please use the official template.");
        }

        $batchId = 'BULK_' . strtoupper(substr($module, 0, 3)) . '_' . dechex(time()) . '_' . substr(md5(uniqid()), 0, 6);

        // Process optional ZIP archive of student PDF proofs
        $proofsIndex = [
            'temp_dir'    => null,
            'by_reg_no'   => [],
            'by_filename' => [],
            'total_pdfs'  => 0,
            'errors'      => [],
        ];
        if (empty($zipPath) || !is_file($zipPath)) {
            throw new InvalidArgumentException("Student proofs archive (.zip) is compulsory. Please select and upload a .zip file containing PDF certificates for all students.");
        }
        $proofsIndex = self::extractAndIndexProofsZip($zipPath, $batchId);
        if (($proofsIndex['total_pdfs'] ?? 0) === 0) {
            $errDetail = !empty($proofsIndex['errors']) ? ' (' . implode(' ', $proofsIndex['errors']) . ')' : '';
            throw new InvalidArgumentException("The uploaded proofs ZIP archive contains no valid PDF certificates" . $errDetail . ". Each student certificate must be a .pdf file (<= 2 MB) named after the student's register number (e.g. 711322104001.pdf).");
        }
        $proofsMatchedCount = 0;

        // Existing records in database for duplicate detection
        $db = db();
        $table = $cfg['table'];
        $existingRecords = [];
        try {
            $stmt = $db->query("SELECT id, reg_no, title, industry, academic_year FROM `{$table}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $regKey = strtoupper(trim((string)$row['reg_no']));
                $titleKey = strtolower(trim((string)$row['title']));
                $ayKey = trim((string)($row['academic_year'] ?? ''));
                if ($regKey !== '') {
                    $dupHash = md5($regKey . '|' . $ayKey . '|' . $titleKey);
                    $existingRecords[$dupHash] = (int)$row['id'];
                }
            }
        } catch (\PDOException $e) {
            // Degrade gracefully if table has slightly different columns
        }

        // Role authorization parameters
        $userRole = $currentUser['role'] ?? 'Faculty';
        $userDept = $currentUser['department'] ?? null;
        $isDeptRestricted = in_array($userRole, ['Faculty', 'Coordinator'], true) && !empty($userDept);

        // Process student data rows
        $dataRowStart = $headerRowIdx + 1;
        $parsedRows   = [];
        $errors       = [];
        $seenInFile   = []; // dupHash -> excelRow

        $validCount     = 0;
        $duplicateCount = 0;
        $invalidCount   = 0;

        for ($r = $dataRowStart; $r < count($rows); $r++) {
            $rawRow = $rows[$r];
            $excelRowNumber = $r + 1; // 1-indexed Excel row

            // Check if row is completely empty
            $isEmpty = true;
            foreach ($rawRow as $c) {
                if (trim((string)$c) !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if ($isEmpty) {
                continue;
            }

            // Check if row is an example row
            $firstCell = trim((string)($rawRow[0] ?? ''));
            $regCell   = trim((string)($rawRow[$fieldIndexMap['reg_no']] ?? ''));
            if (stripos($firstCell, '[EXAMPLE') !== false || stripos($regCell, '[EXAMPLE') !== false || stripos($firstCell, 'DO NOT REMOVE') !== false) {
                continue;
            }

            // Extract values according to mapped columns
            $rowValues = [];
            foreach ($fieldIndexMap as $f => $cIdx) {
                $rowValues[$f] = trim((string)($rawRow[$cIdx] ?? ''));
            }

            $rowErrors = [];

            // 1. Validate Register Number
            $regNo = trim((string)($rowValues['reg_no'] ?? ''));
            if ($regNo === '') {
                $rowErrors[] = ['field' => 'Student Register Number', 'problem' => 'Required value is missing', 'value' => ''];
            } elseif (strlen($regNo) < 3 || strlen($regNo) > 50) {
                $rowErrors[] = ['field' => 'Student Register Number', 'problem' => 'Invalid register number length (must be 3-50 characters)', 'value' => $regNo];
            } elseif (in_array(strtoupper($regNo), ['N/A', 'NONE', 'NULL', '—', '-'], true)) {
                $rowErrors[] = ['field' => 'Student Register Number', 'problem' => 'Placeholder values like N/A or dashes are not allowed', 'value' => $regNo];
            }

            // 2. Validate Student Name
            $studentName = trim((string)($rowValues['student_name'] ?? ''));
            if ($studentName === '') {
                $rowErrors[] = ['field' => 'Student Name', 'problem' => 'Required field is missing', 'value' => ''];
            } elseif (strlen($studentName) < 2) {
                $rowErrors[] = ['field' => 'Student Name', 'problem' => 'Student name is too short', 'value' => $studentName];
            }

            // 3. Validate & Enforce Department
            $rowDept = trim((string)($rowValues['department'] ?? ''));
            if ($rowDept === '') {
                $rowDept = $userDept ?: 'CSE';
            }
            if ($isDeptRestricted) {
                if (strcasecmp($rowDept, $userDept) !== 0) {
                    $rowErrors[] = [
                        'field'   => 'Department',
                        'problem' => "Cross-department student mismatch: you are authorized for {$userDept}, but file specifies '{$rowDept}'",
                        'value'   => $rowDept
                    ];
                }
                $effectiveDept = $userDept;
            } else {
                $effectiveDept = $rowDept;
            }

            // 4. Validate Academic Year
            $rowAy = trim((string)($rowValues['academic_year'] ?? ''));
            if ($rowAy === '') {
                $rowAy = $activeYear;
            } else {
                if (!preg_match('/^\d{4}-\d{2}$/', $rowAy)) {
                    $rowErrors[] = ['field' => 'Academic Year', 'problem' => 'Invalid academic year format (must be YYYY-YY, e.g. ' . $activeYear . ')', 'value' => $rowAy];
                } elseif (function_exists('academic_year_is_locked') && academic_year_is_locked($rowAy) && $userRole !== 'Admin') {
                    $rowErrors[] = ['field' => 'Academic Year', 'problem' => "Academic year {$rowAy} cycle is locked by Administrator", 'value' => $rowAy];
                }
            }

            // 5. Validate Exam Session
            $rowSession = trim((string)($rowValues['exam_session'] ?? ''));
            if ($rowSession === '') {
                $rowSession = function_exists('exam_session_current') ? exam_session_current() : 'Odd';
            } else {
                $sessionClean = ucfirst(strtolower($rowSession));
                if (!in_array($sessionClean, ['Odd', 'Even'], true)) {
                    $rowErrors[] = ['field' => 'Exam Session', 'problem' => "Must be 'Odd' or 'Even'", 'value' => $rowSession];
                } else {
                    $rowSession = $sessionClean;
                }
            }

            // 6. Validate Title
            $title = trim((string)($rowValues['title'] ?? ''));
            if ($title === '') {
                $rowErrors[] = ['field' => $cfg['item_type_label'] . ' Title', 'problem' => 'Required field is missing', 'value' => ''];
            } elseif (strlen($title) < 3) {
                $rowErrors[] = ['field' => $cfg['item_type_label'] . ' Title', 'problem' => 'Title is too short (min 3 characters)', 'value' => $title];
            }

            // 7. Validate Industry / Organization
            $industry = trim((string)($rowValues['industry'] ?? ''));
            if ($industry === '') {
                $rowErrors[] = ['field' => 'Organization / Company Name', 'problem' => 'Required field is missing', 'value' => ''];
            } elseif (strlen($industry) < 2) {
                $rowErrors[] = ['field' => 'Organization / Company Name', 'problem' => 'Organization name is too short', 'value' => $industry];
            }

            // 8. Validate Duration
            $duration = trim((string)($rowValues['duration'] ?? ''));
            if ($duration === '') {
                $rowErrors[] = ['field' => 'Duration', 'problem' => 'Required field is missing', 'value' => ''];
            }

            // 9. Validate Days
            $daysRaw = trim((string)($rowValues['days'] ?? ''));
            $daysInt = filter_var($daysRaw, FILTER_VALIDATE_INT);
            if ($daysRaw === '') {
                $rowErrors[] = ['field' => 'No. of Days', 'problem' => 'Required field is missing', 'value' => ''];
            } elseif ($daysInt === false || $daysInt <= 0) {
                $rowErrors[] = ['field' => 'No. of Days', 'problem' => 'Must be a positive whole number greater than 0', 'value' => $daysRaw];
            }

            // 10. Validate Proof Attachment (Dual support: Matched from ZIP archive OR Cloud Link)
            $cleanReg = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $regNo));
            $matchedPdf = null;
            if (!empty($proofsIndex['by_reg_no'][$cleanReg])) {
                $matchedPdf = $proofsIndex['by_reg_no'][$cleanReg];
            } elseif (!empty($proofsIndex['by_filename'][$cleanReg . '.pdf'])) {
                $matchedPdf = $proofsIndex['by_filename'][$cleanReg . '.pdf'];
            } elseif (!empty($rowValues['proof_file_name'])) {
                $customName = strtolower(trim((string)$rowValues['proof_file_name']));
                if (!empty($proofsIndex['by_filename'][$customName])) {
                    $matchedPdf = $proofsIndex['by_filename'][$customName];
                }
            }

            $certLink = trim((string)($rowValues['certificate_link'] ?? ''));
            $proofSource = 'none';
            $proofDisplay = '';

            if ($matchedPdf !== null && is_file($matchedPdf)) {
                $proofSource = 'zip';
                $proofDisplay = basename($matchedPdf);
                $proofsMatchedCount++;
            } else {
                // Proof certificate in ZIP archive is COMPULSORY for every student
                $rowErrors[] = [
                    'field'   => 'Proof Certificate (ZIP)',
                    'problem' => "Compulsory certificate missing in ZIP archive. Expected PDF: {$cleanReg}.pdf (matching register number {$regNo}).",
                    'value'   => 'Missing in ZIP'
                ];
            }

            // Duplicate Detection
            $isDuplicate = false;
            $dupMessage = '';
            $dupKey = md5(strtoupper($regNo) . '|' . $rowAy . '|' . strtolower($title));

            if (empty($rowErrors)) {
                // Check if duplicate within spreadsheet
                if (isset($seenInFile[$dupKey])) {
                    $isDuplicate = true;
                    $firstRow = $seenInFile[$dupKey];
                    $dupMessage = "Duplicate record within this spreadsheet (matches row {$firstRow})";
                    $rowErrors[] = ['field' => 'Student Register Number', 'problem' => $dupMessage, 'value' => $regNo];
                } else {
                    $seenInFile[$dupKey] = $excelRowNumber;
                }

                // Check if duplicate in database
                if (!$isDuplicate && isset($existingRecords[$dupKey])) {
                    $isDuplicate = true;
                    $dbId = $existingRecords[$dupKey];
                    $dupMessage = "Duplicate record: already exists in ATTS database (Record #{$dbId})";
                    $rowErrors[] = ['field' => 'Student Record', 'problem' => $dupMessage, 'value' => "Reg: {$regNo}, {$title}"];
                }
            }

            // Classification
            $rowStatus = 'valid';
            if (!empty($rowErrors)) {
                if ($isDuplicate) {
                    $rowStatus = 'duplicate';
                    $duplicateCount++;
                } else {
                    $rowStatus = 'invalid';
                    $invalidCount++;
                }
                foreach ($rowErrors as $err) {
                    $errors[] = array_merge(['row' => $excelRowNumber], $err);
                }
            } else {
                $validCount++;
            }

            $parsedRows[] = [
                'excel_row'        => $excelRowNumber,
                'status'           => $rowStatus,
                'reg_no'           => $regNo,
                'student_name'     => $studentName,
                'department'       => $effectiveDept,
                'academic_year'    => $rowAy,
                'exam_session'     => $rowSession,
                'title'            => $title,
                'industry'         => $industry,
                'duration'         => $duration,
                'days'             => (int)$daysInt,
                'certificate_link' => $certLink,
                'proof_source'     => $proofSource,
                'proof_temp_file'  => $matchedPdf,
                'proof_display'    => $proofDisplay,
                'errors'           => $rowErrors,
            ];
        }

        return [
            'batch_id'         => $batchId,
            'module'           => $module,
            'module_label'     => $cfg['label'],
            'total_detected'   => count($parsedRows),
            'valid_count'      => $validCount,
            'duplicate_count'  => $duplicateCount,
            'invalid_count'    => $invalidCount,
            'proofs_matched'   => $proofsMatchedCount,
            'proofs_zip_total' => $proofsIndex['total_pdfs'] ?? 0,
            'proofs_dir'       => $proofsIndex['temp_dir'] ?? null,
            'can_import'       => ($validCount > 0),
            'rows'             => $parsedRows,
            'errors'           => $errors,
            'uploader_id'      => (int)$currentUser['id'],
            'uploader_dept'    => $currentUser['department'] ?? 'CSE',
            'academic_year'    => $activeYear,
        ];
    }

    /**
     * Commit valid previewed records into the database with transaction protection.
     */
    public static function commitImport(array $previewData, array $currentUser): array
    {
        $module = $previewData['module'] ?? '';
        $cfg = self::getModuleConfig($module);
        if (!$cfg) {
            throw new InvalidArgumentException("Invalid module configuration.");
        }

        $validRows = array_filter($previewData['rows'] ?? [], fn($r) => ($r['status'] ?? '') === 'valid');
        if (empty($validRows)) {
            throw new RuntimeException("No valid student records found to import.");
        }

        $batchId    = (string)$previewData['batch_id'];
        $db         = db();
        $table      = $cfg['table'];
        $imported   = 0;
        $failed     = 0;

        $uploaderId   = (int)$currentUser['id'];
        $userDept     = $currentUser['department'] ?? ($previewData['uploader_dept'] ?? 'CSE');
        $academicYear = $previewData['academic_year'] ?? active_academic_year();
        $userRole     = $currentUser['role'] ?? 'Faculty';

        // Imported records enter as 'Submitted' so they enter Coordinator/HoD verification workflow
        $initialStatus = 'Submitted';

        $tableCols = target_record_table_columns($table);

        $db->beginTransaction();
        try {
            foreach ($validRows as $row) {
                $fields = [
                    'reg_no'           => $row['reg_no'],
                    'student_name'     => $row['student_name'],
                    'title'            => $row['title'],
                    'industry'         => $row['industry'],
                    'duration'         => $row['duration'],
                    'days'             => (string)$row['days'],
                    'certificate_link' => $row['certificate_link'],
                    'status'           => $initialStatus,
                    'created_by'       => $uploaderId,
                    'created_at'       => date('Y-m-d H:i:s'),
                ];

                if (in_array('department', $tableCols, true)) {
                    $fields['department'] = $row['department'];
                }
                if (in_array('academic_year', $tableCols, true)) {
                    $fields['academic_year'] = $row['academic_year'];
                }
                if (in_array('exam_session', $tableCols, true)) {
                    $fields['exam_session'] = $row['exam_session'];
                }
                if (in_array('import_batch_id', $tableCols, true)) {
                    $fields['import_batch_id'] = $batchId;
                }

                // If proof was matched from the uploaded ZIP archive, copy it to the official proofs storage
                $storedProof = null;
                if (($row['proof_source'] ?? '') === 'zip' && !empty($row['proof_temp_file']) && is_file($row['proof_temp_file'])) {
                    $baseFolder = rtrim(UPLOAD_DIR, '/\\');
                    if (!is_dir($baseFolder)) {
                        @mkdir($baseFolder, 0775, true);
                    }
                    $proofsFolder = $baseFolder . '/proofs';
                    if (!is_dir($proofsFolder)) {
                        @mkdir($proofsFolder, 0775, true);
                    }
                    $uniqueId  = bin2hex(random_bytes(8));
                    $timestamp = time();
                    $storedProof = "record_{$uniqueId}_{$timestamp}.pdf";
                    $destPath = $baseFolder . '/' . $storedProof;
                    @copy($row['proof_temp_file'], $destPath);
                    @copy($row['proof_temp_file'], $proofsFolder . '/' . $storedProof);
                }

                if ($storedProof !== null && in_array('proof_file', $tableCols, true)) {
                    $fields['proof_file'] = $storedProof;
                }

                $colsSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($fields)));
                $phSql   = implode(', ', array_fill(0, count($fields), '?'));
                $sql     = "INSERT INTO `{$table}` ({$colsSql}) VALUES ({$phSql})";

                $stmt = $db->prepare($sql);
                $stmt->execute(array_values($fields));
                $newId = (int)$db->lastInsertId();

                if ($newId > 0) {
                    $imported++;
                    // Audit log each imported student record
                    if (function_exists('record_workflow_audit')) {
                        record_workflow_audit(
                            $module,
                            $newId,
                            'BULK_STUDENT_IMPORTED',
                            $currentUser,
                            null,
                            $initialStatus,
                            "Imported via Excel SmartBulk (Batch #{$batchId})",
                            [
                                'batch_id' => $batchId,
                                'reg_no'   => $row['reg_no'],
                                'student'  => $row['student_name']
                            ],
                            $row['department'],
                            $row['academic_year']
                        );
                    }
                } else {
                    $failed++;
                }
            }

            // Record into bulk_import_history table
            $histSql = "INSERT INTO bulk_import_history 
                (batch_id, module, file_name, department, academic_year, uploaded_by, total_rows, valid_rows, imported_records, duplicate_records, failed_rows, status, errors_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $histStmt = $db->prepare($histSql);
            $histStmt->execute([
                $batchId,
                $module,
                $previewData['file_name'] ?? 'bulk_upload.xlsx',
                $userDept,
                $academicYear,
                $uploaderId,
                $previewData['total_detected'] ?? count($validRows),
                $previewData['valid_count'] ?? count($validRows),
                $imported,
                $previewData['duplicate_count'] ?? 0,
                ($previewData['invalid_count'] ?? 0) + $failed,
                'Completed',
                json_encode($previewData['errors'] ?? []),
            ]);

            $db->commit();

            // Clean up temporary extracted proofs directory if present
            if (!empty($previewData['proofs_dir']) && is_dir($previewData['proofs_dir'])) {
                self::deleteDirectory($previewData['proofs_dir']);
            }

            // Synchronize target tracking caches for dashboard / reports
            if (function_exists('sync_target_achieved_for_type')) {
                sync_target_achieved_for_type($module);
            }

            return [
                'success'           => true,
                'batch_id'          => $batchId,
                'module'            => $module,
                'module_label'      => $cfg['label'],
                'imported_records'  => $imported,
                'duplicate_records' => $previewData['duplicate_count'] ?? 0,
                'invalid_records'   => $previewData['invalid_count'] ?? 0,
                'total_detected'    => $previewData['total_detected'] ?? $imported,
                'proofs_matched'    => $previewData['proofs_matched'] ?? 0,
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("BulkImport commitImport error: " . $e->getMessage());
            throw new RuntimeException("Database error during import: " . $e->getMessage());
        }
    }

    /**
     * Generate an Excel validation error report for download.
     */
    public static function generateErrorReport(array $errors, string $module): string
    {
        $cfg = self::getModuleConfig($module);
        $headers = ['Excel Row #', 'Column / Field', 'Problem Description', 'Submitted Value', 'Recommended Action'];
        $rows = [];

        foreach ($errors as $err) {
            $field = (string)($err['field'] ?? 'Field');
            $prob  = (string)($err['problem'] ?? 'Validation error');
            $val   = (string)($err['value'] ?? '');
            $rowN  = (int)($err['row'] ?? 0);

            $action = 'Please correct the value in the spreadsheet according to the Instructions sheet.';
            if (str_contains(strtolower($prob), 'duplicate')) {
                $action = 'Remove this row from the spreadsheet or verify if student already completed this activity.';
            } elseif (str_contains(strtolower($prob), 'missing')) {
                $action = 'Provide a non-empty value for this mandatory field.';
            } elseif (str_contains(strtolower($prob), 'url') || str_contains(strtolower($prob), 'http')) {
                $action = 'Enter a valid URL starting with http:// or https://.';
            } elseif (str_contains(strtolower($prob), 'mismatch')) {
                $action = 'Ensure student department matches your authorized department.';
            }

            $rows[] = [
                $rowN > 0 ? (string)$rowN : '—',
                $field,
                $prob,
                $val,
                $action,
            ];
        }

        return SimpleXlsxWriter::createMultiSheetXlsx([
            [
                'title'   => 'Validation Errors',
                'headers' => $headers,
                'rows'    => $rows,
                'meta'    => [
                    'ATTS SmartBulk Validation Error Report — ' . ($cfg['label'] ?? 'Import'),
                    'Generated on ' . date('Y-m-d H:i:s') . ' · Total Issues: ' . count($errors),
                ],
            ]
        ]);
    }

    /**
     * Record a batch into bulk_import_history table.
     */
    public static function recordHistory(array $data): bool
    {
        try {
            $db = db();
            $histSql = "INSERT INTO bulk_import_history 
                (batch_id, module, file_name, department, academic_year, uploaded_by, total_rows, valid_rows, imported_records, duplicate_records, failed_rows, status, errors_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $histStmt = $db->prepare($histSql);
            return $histStmt->execute([
                $data['batch_id'],
                $data['module'],
                $data['file_name'] ?? 'bulk_upload.xlsx',
                $data['department'] ?? null,
                $data['academic_year'] ?? '2025-26',
                $data['uploaded_by'] ?? null,
                $data['total_rows'] ?? 0,
                $data['valid_rows'] ?? 0,
                $data['imported_records'] ?? 0,
                $data['duplicate_records'] ?? 0,
                $data['failed_rows'] ?? 0,
                $data['status'] ?? 'Completed',
                is_string($data['errors_json'] ?? null) ? $data['errors_json'] : json_encode($data['errors_json'] ?? []),
            ]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function logHistory(array $data): bool
    {
        return self::recordHistory($data);
    }

    /**
     * Retrieve recent bulk import history for a department or module.
     */
    public static function getHistory(string $module, ?string $department = null, int $limit = 50, ?int $uploadedBy = null): array
    {
        try {
            $db = db();
            $sql = "SELECT h.*, u.name AS uploader_name, u.role AS uploader_role
                    FROM bulk_import_history h
                    LEFT JOIN users u ON u.id = h.uploaded_by
                    WHERE h.module = ?";
            $params = [$module];

            if ($department) {
                if ($uploadedBy) {
                    $sql .= " AND (h.department = ? OR h.uploaded_by = ?)";
                    $params[] = $department;
                    $params[] = $uploadedBy;
                } else {
                    $sql .= " AND h.department = ?";
                    $params[] = $department;
                }
            }

            $sql .= " ORDER BY h.created_at DESC LIMIT " . (int)$limit;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Count total bulk import batches for a module and optional department.
     */
    public static function countHistory(string $module, ?string $department = null): int
    {
        try {
            $db = db();
            $sql = "SELECT COUNT(*) FROM bulk_import_history WHERE module = ?";
            $params = [$module];
            if ($department) {
                $sql .= " AND department = ?";
                $params[] = $department;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }
}
