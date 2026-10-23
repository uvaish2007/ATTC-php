<?php
/**
 * Patent & Copyright Handler
 * 
 * Handles:
 * 1. Author Method / Co-Authors (Multiple dynamic co-authors, validation, DB storage)
 * 2. Document Link validation and display
 * 3. Categorized Multiple Document Attachments (Patent Copy, Certificate, Supporting Document)
 * 4. Patent / Registration Number Normalization & Consolidated Report Grouping
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/compression.php';

if (!defined('PATENT_DOC_TYPES')) {
    define('PATENT_DOC_TYPES', ['Patent Copy', 'Certificate', 'Supporting Document']);
}

/**
 * Parse and sanitize submitted co-author names.
 *
 * @param mixed $input Array or string of co-author names
 * @return array Cleaned array of non-empty co-author names
 */
function patent_parse_coauthors_input($input): array
{
    $clean = [];
    if (is_array($input)) {
        foreach ($input as $name) {
            $n = trim(strip_tags((string)$name));
            $n = preg_replace('/\s+/', ' ', $n);
            if ($n !== '' && !in_array($n, $clean, true)) {
                $clean[] = $n;
            }
        }
    } elseif (is_string($input)) {
        // Handle comma or newline separated names
        $parts = preg_split('/[,\r\n]+/', $input);
        foreach ($parts as $p) {
            $n = trim(strip_tags((string)$p));
            $n = preg_replace('/\s+/', ' ', $n);
            if ($n !== '' && !in_array($n, $clean, true)) {
                $clean[] = $n;
            }
        }
    }
    return $clean;
}

/**
 * Fetch co-authors for a patent record.
 */
function patent_get_coauthors(int $patentId): array
{
    if ($patentId <= 0) {
        return [];
    }
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT coauthor_name FROM patent_coauthors WHERE patent_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$patentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($rows)) {
            return $rows;
        }
        
        // Fallback to patents.co_authors column
        $pStmt = $pdo->prepare("SELECT co_authors FROM patents WHERE id = ?");
        $pStmt->execute([$patentId]);
        $raw = $pStmt->fetchColumn();
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return patent_parse_coauthors_input($raw);
        }
    } catch (\Throwable $e) {
        error_log('patent_get_coauthors error: ' . $e->getMessage());
    }
    return [];
}

/**
 * Save co-authors for a patent record into both patent_coauthors and patents.co_authors.
 */
function patent_save_coauthors(int $patentId, array $coAuthors, ?\PDO $pdo = null): void
{
    if ($patentId <= 0) {
        return;
    }
    $pdo = $pdo ?: db();
    $clean = patent_parse_coauthors_input($coAuthors);

    // Save to relational table
    try {
        $delStmt = $pdo->prepare("DELETE FROM patent_coauthors WHERE patent_id = ?");
        $delStmt->execute([$patentId]);

        if (!empty($clean)) {
            $insStmt = $pdo->prepare("INSERT INTO patent_coauthors (patent_id, coauthor_name, sort_order) VALUES (?, ?, ?)");
            $order = 1;
            foreach ($clean as $ca) {
                $insStmt->execute([$patentId, $ca, $order++]);
            }
        }

        // Also update patents.co_authors column as formatted readable string / JSON
        $formattedStr = !empty($clean) ? implode(', ', $clean) : null;
        $upStmt = $pdo->prepare("UPDATE patents SET co_authors = ? WHERE id = ?");
        $upStmt->execute([$formattedStr, $patentId]);
    } catch (\Throwable $e) {
        error_log('patent_save_coauthors error: ' . $e->getMessage());
    }
}

/**
 * Fetch attachments for a patent record.
 */
function patent_get_attachments(int $patentId): array
{
    if ($patentId <= 0) {
        return [];
    }
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT * FROM patent_attachments WHERE patent_id = ? ORDER BY id ASC");
        $stmt->execute([$patentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return $rows;
        }

        // Fallback to patents.proof_file or attachments column
        $pStmt = $pdo->prepare("SELECT proof_file, category FROM patents WHERE id = ?");
        $pStmt->execute([$patentId]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow && !empty($pRow['proof_file'])) {
            $docType = ($pRow['category'] === 'Copyrights') ? 'Certificate' : 'Patent Copy';
            return [[
                'id'         => 0,
                'patent_id'  => $patentId,
                'doc_type'   => $docType,
                'file_path'  => $pRow['proof_file'],
                'file_name'  => $pRow['proof_file'],
                'file_size'  => 0,
                'mime_type'  => 'application/pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]];
        }
    } catch (\Throwable $e) {
        error_log('patent_get_attachments error: ' . $e->getMessage());
    }
    return [];
}

/**
 * Validate and save multiple categorized document attachments for a patent record.
 * 
 * Supports: Patent Copy, Certificate, Supporting Document.
 * Supported file types: PDF, JPG, JPEG, PNG. Max 2 MB per file.
 * 
 * @return array [bool $success, ?string $errorMessage, array $attachmentsSaved]
 */
function patent_process_attachments(
    int $patentId,
    ?array $files,
    array $docTypes,
    array $keepAttachmentIds = [],
    bool $isRequired = false
): array {
    $pdo = db();
    $existing = patent_get_attachments($patentId);
    $kept = [];

    // Filter existing attachments by keepAttachmentIds if provided
    if ($patentId > 0 && !empty($existing)) {
        foreach ($existing as $ex) {
            $exId = (int)($ex['id'] ?? 0);
            if (empty($keepAttachmentIds) || in_array($exId, $keepAttachmentIds, true) || $exId === 0) {
                $kept[] = $ex;
            } else {
                // Remove unkept attachment from DB
                try {
                    $pdo->prepare("DELETE FROM patent_attachments WHERE id = ?")->execute([$exId]);
                } catch (\Throwable $e) {}
            }
        }
    }

    $newUploaded = [];
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    $validDocTypes = PATENT_DOC_TYPES;

    // Check if files array is present in multi-file format
    if (!empty($files) && !empty($files['name']) && is_array($files['name'])) {
        $fileCount = count($files['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($err !== UPLOAD_ERR_OK) {
                $origName = $files['name'][$i] ?? "File #{$i}";
                return [false, "File '{$origName}' upload failed (error code: {$err}).", []];
            }

            $tmpName = $files['tmp_name'][$i] ?? '';
            $origName = basename((string)($files['name'][$i] ?? ''));
            $size = (int)($files['size'][$i] ?? 0);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExts, true)) {
                return [false, "Invalid format for file '{$origName}'. Only PDF documents (.pdf) and images (.jpg, .jpeg, .png) are permitted.", []];
            }

            if ($size > PROOF_MAX_BYTES) {
                return [false, "The file '{$origName}' exceeds the maximum allowed size of 2 MB.", []];
            }

            if (!is_uploaded_file($tmpName) && !is_file($tmpName)) {
                return [false, "Security error: invalid temporary upload for '{$origName}'.", []];
            }

            // Magic bytes verification
            $handle = @fopen($tmpName, 'rb');
            $header4 = $handle ? fread($handle, 4) : '';
            if ($handle) {
                fclose($handle);
            }

            if ($ext === 'pdf') {
                if ($header4 !== '%PDF') {
                    return [false, "The file '{$origName}' is not a valid PDF document.", []];
                }
            } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
                if (substr($header4, 0, 3) !== "\xFF\xD8\xFF") {
                    return [false, "The file '{$origName}' is not a valid JPEG image.", []];
                }
            } elseif ($ext === 'png') {
                if ($header4 !== "\x89PNG") {
                    return [false, "The file '{$origName}' is not a valid PNG image.", []];
                }
            }

            // Determine document type
            $rawDocType = trim((string)($docTypes[$i] ?? 'Patent Copy'));
            $docType = in_array($rawDocType, $validDocTypes, true) ? $rawDocType : 'Supporting Document';

            // Save to uploads/proofs/
            $uploadBase = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads'), '/\\');
            $proofsFolder = $uploadBase . '/proofs';
            if (!is_dir($proofsFolder)) {
                @mkdir($proofsFolder, 0755, true);
            }
            $targetFolder = is_dir($proofsFolder) ? $proofsFolder : $uploadBase;

            $storedFileName = 'proof_patent_' . md5(uniqid((string)mt_rand(), true)) . '.' . $ext;
            $destination = $targetFolder . '/' . $storedFileName;

            if (!@move_uploaded_file($tmpName, $destination)) {
                if (!@copy($tmpName, $destination)) {
                    return [false, "Failed to store uploaded attachment '{$origName}' to server disk.", []];
                }
                @unlink($tmpName);
            }

            $mime = ($ext === 'pdf') ? 'application/pdf' : (($ext === 'png') ? 'image/png' : 'image/jpeg');

            $newUploaded[] = [
                'doc_type'  => $docType,
                'file_path' => $storedFileName,
                'file_name' => $origName,
                'file_size' => $size,
                'mime_type' => $mime,
            ];
        }
    }

    $allAttachments = array_merge($kept, $newUploaded);

    if ($isRequired && empty($allAttachments)) {
        return [false, 'At least one attachment (Patent Copy, Certificate, or Supporting Document) is required.', []];
    }

    // Persist new attachments to DB if patentId exists
    if ($patentId > 0 && !empty($newUploaded)) {
        $insStmt = $pdo->prepare("
            INSERT INTO patent_attachments (patent_id, doc_type, file_path, file_name, file_size, mime_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        foreach ($newUploaded as $nu) {
            $insStmt->execute([
                $patentId,
                $nu['doc_type'],
                $nu['file_path'],
                $nu['file_name'],
                $nu['file_size'],
                $nu['mime_type'],
            ]);
        }
    }

    // Update primary proof_file and attachments JSON cache on patents record
    if ($patentId > 0 && !empty($allAttachments)) {
        $primaryProof = $allAttachments[0]['file_path'] ?? null;
        $jsonCache = json_encode(array_map(function($a) {
            return [
                'doc_type'  => $a['doc_type'],
                'file_path' => $a['file_path'],
                'file_name' => $a['file_name'] ?? $a['file_path'],
            ];
        }, $allAttachments));

        $pdo->prepare("UPDATE patents SET proof_file = ?, attachments = ? WHERE id = ?")
            ->execute([$primaryProof, $jsonCache, $patentId]);
    }

    return [true, null, $allAttachments];
}

/**
 * Persist an array of processed attachment items to patent_attachments and update cache.
 */
function patent_persist_attachments(int $patentId, array $attachments, ?\PDO $pdo = null): void
{
    if ($patentId <= 0 || empty($attachments)) {
        return;
    }
    $pdo = $pdo ?: db();
    try {
        $insStmt = $pdo->prepare("
            INSERT INTO patent_attachments (patent_id, doc_type, file_path, file_name, file_size, mime_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        foreach ($attachments as $nu) {
            $insStmt->execute([
                $patentId,
                $nu['doc_type'] ?? 'Supporting Document',
                $nu['file_path'],
                $nu['file_name'] ?? $nu['file_path'],
                (int)($nu['file_size'] ?? 0),
                $nu['mime_type'] ?? 'application/pdf',
            ]);
        }

        $primaryProof = $attachments[0]['file_path'] ?? null;
        $jsonCache = json_encode(array_map(function($a) {
            return [
                'doc_type'  => $a['doc_type'] ?? 'Supporting Document',
                'file_path' => $a['file_path'],
                'file_name' => $a['file_name'] ?? $a['file_path'],
            ];
        }, $attachments));

        $pdo->prepare("UPDATE patents SET proof_file = ?, attachments = ? WHERE id = ?")
            ->execute([$primaryProof, $jsonCache, $patentId]);
    } catch (\Throwable $e) {
        error_log('patent_persist_attachments error: ' . $e->getMessage());
    }
}

/**
 * Normalize Patent / Copyright / Registration Number for standardized consolidated reporting.
 *
 * Rules:
 * 1. Trims leading/trailing whitespace.
 * 2. Normalizes internal whitespace and hyphens for case-insensitive matching.
 * 3. Does NOT alter the original number string displayed to users.
 */
function patent_normalize_number(?string $number): string
{
    $num = trim((string)$number);
    if ($num === '') {
        return '';
    }
    // Remove all whitespace, hyphens, slashes, and periods for strict canonical matching
    $normalized = preg_replace('/[\s\-_.\/]+/', '', $num);
    return mb_strtoupper($normalized, 'UTF-8');
}

/**
 * Generate a grouping scope key for consolidated report deduplication.
 * Prevents false merges across different academic years, categories, or record types.
 * Returns null if registration number is missing (forcing review rather than merging).
 */
function patent_grouping_key(array $record): ?string
{
    $regNum = trim((string)($record['patent_number'] ?? $record['copyright_number'] ?? ''));
    if ($regNum === '') {
        return null; // Missing registration number — flag for review, do NOT merge!
    }

    $normNum = patent_normalize_number($regNum);
    if ($normNum === '') {
        return null;
    }

    $category = strtoupper(trim((string)($record['category'] ?? $record['patent_type'] ?? 'PATENT')));
    $recType  = strtoupper(trim((string)($record['_type_key'] ?? $record['record_type'] ?? 'PATENT')));
    $ay       = trim((string)($record['academic_year'] ?? ''));

    return $recType . '|' . $category . '|' . $ay . '|' . $normNum;
}

/**
 * Aggregate and deduplicate patent/copyright records for the Consolidated Report.
 *
 * Requirements:
 * - Records with the same normalized registration number are not double-counted.
 * - Displays registration number as primary reference.
 * - Aggregates associated faculty and co-authors without information loss.
 * - Missing registration numbers are flagged for review.
 * - Unrelated categories, record types, or academic years are kept separate.
 */
function patent_consolidate_records(array $records): array
{
    $grouped = [];
    $unmerged = [];

    foreach ($records as $r) {
        $type = $r['_type_key'] ?? $r['record_type'] ?? 'patent';
        $isPatentType = in_array($type, ['patent', 'inst_patents_published', 'inst_patents_granted', 'inst_copyrights'], true);

        if (!$isPatentType) {
            $unmerged[] = $r;
            continue;
        }

        $groupKey = patent_grouping_key($r);
        if ($groupKey === null) {
            // Missing registration number: flag for review and keep independent
            $flaggedRecord = $r;
            $flaggedRecord['_needs_review'] = true;
            $flaggedRecord['_review_flag_reason'] = 'Missing Registration Number';
            $flaggedRecord['_display_reg_no'] = '— [Needs Review: Missing No.]';
            $unmerged[] = $flaggedRecord;
            continue;
        }

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'primary'      => $r,
                'submissions'  => [$r],
                'faculties'    => [],
                'coauthors'    => [],
                'departments'  => [],
                'doc_links'    => [],
                'attachments'  => [],
            ];
        } else {
            $grouped[$groupKey]['submissions'][] = $r;
        }

        // Aggregate faculty
        $fName = trim((string)($r['faculty_name'] ?? $r['_person'] ?? ''));
        $fDept = trim((string)($r['department'] ?? ''));
        if ($fName !== '') {
            $facEntry = $fName . ($fDept ? " ({$fDept})" : '');
            if (!in_array($facEntry, $grouped[$groupKey]['faculties'], true)) {
                $grouped[$groupKey]['faculties'][] = $facEntry;
            }
        }

        // Aggregate department
        if ($fDept !== '' && !in_array($fDept, $grouped[$groupKey]['departments'], true)) {
            $grouped[$groupKey]['departments'][] = $fDept;
        }

        // Aggregate co-authors
        $caList = [];
        if (!empty($r['id'])) {
            $caList = patent_get_coauthors((int)$r['id']);
        }
        if (empty($caList) && !empty($r['co_authors'])) {
            $caList = patent_parse_coauthors_input($r['co_authors']);
        }
        if (empty($caList) && !empty($r['inventors'])) {
            $caList = patent_parse_coauthors_input($r['inventors']);
        }
        foreach ($caList as $ca) {
            if ($ca !== '' && !in_array($ca, $grouped[$groupKey]['coauthors'], true)) {
                $grouped[$groupKey]['coauthors'][] = $ca;
            }
        }

        // Aggregate document links
        $dLink = trim((string)($r['document_link'] ?? ''));
        if ($dLink !== '' && !in_array($dLink, $grouped[$groupKey]['doc_links'], true)) {
            $grouped[$groupKey]['doc_links'][] = $dLink;
        }

        // Aggregate attachments
        if (!empty($r['id'])) {
            $atts = patent_get_attachments((int)$r['id']);
            foreach ($atts as $at) {
                $grouped[$groupKey]['attachments'][] = $at;
            }
        } elseif (!empty($r['proof_file'])) {
            $grouped[$groupKey]['attachments'][] = [
                'doc_type'  => 'Patent Copy',
                'file_path' => $r['proof_file'],
                'file_name' => $r['proof_file'],
            ];
        }
    }

    $consolidatedList = [];

    foreach ($grouped as $gKey => $g) {
        $p = $g['primary'];
        $subCount = count($g['submissions']);

        // Merge status: if any is Approved, consolidated status is Approved
        $statuses = array_map(fn($s) => $s['status'] ?? 'Draft', $g['submissions']);
        if (in_array('Approved', $statuses, true)) {
            $finalStatus = 'Approved';
        } elseif (in_array('Submitted', $statuses, true) || in_array('Resubmitted', $statuses, true) || in_array('HOD Pending', $statuses, true) || in_array('Dean Pending', $statuses, true)) {
            $finalStatus = 'Submitted';
        } else {
            $finalStatus = reset($statuses) ?: 'Draft';
        }

        // Build composite Author / Co-author string
        $allAuthors = [];
        foreach ($g['faculties'] as $f) {
            $allAuthors[] = $f;
        }
        foreach ($g['coauthors'] as $ca) {
            if (!in_array($ca, $allAuthors, true)) {
                $allAuthors[] = $ca . ' (Co-Author)';
            }
        }
        $authorSummary = implode('; ', $allAuthors);

        // Build document link
        $primaryDocLink = !empty($g['doc_links']) ? reset($g['doc_links']) : '';

        // Attachment types summary
        $attTypes = [];
        foreach ($g['attachments'] as $at) {
            $dt = $at['doc_type'] ?? 'Document';
            if (!in_array($dt, $attTypes, true)) {
                $attTypes[] = $dt;
            }
        }
        $attSummary = !empty($attTypes) ? implode(', ', $attTypes) . ' (' . count($g['attachments']) . ')' : 'None';

        $consolidatedRecord = $p;
        $consolidatedRecord['status'] = $finalStatus;
        $consolidatedRecord['_display_reg_no'] = $p['patent_number'] ?? $p['copyright_number'] ?? '—';
        $consolidatedRecord['_person'] = !empty($g['faculties']) ? implode(', ', $g['faculties']) : ($p['faculty_name'] ?? '');
        $consolidatedRecord['_department'] = !empty($g['departments']) ? implode(', ', $g['departments']) : ($p['department'] ?? '');
        $consolidatedRecord['_author_summary'] = $authorSummary ?: $consolidatedRecord['_person'];
        $consolidatedRecord['_document_link'] = $primaryDocLink;
        $consolidatedRecord['_attachment_summary'] = $attSummary;
        $consolidatedRecord['_submissions_count'] = $subCount;
        $consolidatedRecord['_is_consolidated'] = ($subCount > 1);
        $consolidatedRecord['_all_submissions'] = $g['submissions'];
        $consolidatedRecord['_needs_review'] = false;

        $consolidatedList[] = $consolidatedRecord;
    }

    return array_merge($consolidatedList, $unmerged);
}
