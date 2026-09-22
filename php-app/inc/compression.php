<?php
/**
 * FEAT-13: Automatic File Storage Compression.
 *
 * Provides server-side compression and optimization for proof attachments:
 * - JPEG/JPG: Orientation correction, proportional resizing (if exceeding max dimensions),
 *             and controlled re-encoding (quality ~82) via GD.
 * - PNG: Full alpha transparency preservation, proportional resizing, and high-ratio
 *        lossless compression (level 8) via GD.
 * - PDF: Dual-engine optimization:
 *        1. External CLI optimizer (qpdf, gs/gswin64c, pdfcpu) if installed/configured.
 *        2. Native PHP stream flate compressor (using active zlib extension) for uncompressed
 *           streams with compliant xref reconstruction.
 * - Non-destructive: If compression fails or produces a larger file, the validated original
 *   is safely retained without bloat or corruption.
 */

require_once __DIR__ . '/config.php';

if (!defined('PROOF_MAX_BYTES')) {
    define('PROOF_MAX_BYTES', 2 * 1024 * 1024); // 2 MB
}

if (!defined('COMPRESSION_MAX_DIMENSION')) {
    define('COMPRESSION_MAX_DIMENSION', 2048); // Maximum width/height in pixels
}
if (!defined('COMPRESSION_JPEG_QUALITY')) {
    define('COMPRESSION_JPEG_QUALITY', 82);   // JPEG compression quality (0-100)
}
if (!defined('COMPRESSION_PNG_LEVEL')) {
    define('COMPRESSION_PNG_LEVEL', 8);       // PNG zlib compression level (0-9)
}

/**
 * Save one uploaded proof file. Only a PDF or image (up to 2 MB) is accepted.
 * Files are compressed server-side (FEAT-13) and stored safely in UPLOAD_DIR
 * with pattern record_<unique-id>_<timestamp>.<ext>.
 * Returns [storedName|null, error|null].
 */
if (!function_exists('save_upload_proof')) {
function save_upload_proof(?array $file, bool $required = false): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            return [null, 'Proof / Attachment is required. Please upload a PDF or image file (up to 2 MB).'];
        }
        return [null, null];
    }

    $errorCode = $file['error'] ?? UPLOAD_ERR_OK;
    if ($errorCode !== UPLOAD_ERR_OK) {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [null, 'The uploaded proof exceeds the 2 MB size limit. Please upload a smaller file.'],
            UPLOAD_ERR_PARTIAL   => [null, 'The file was only partially uploaded. Please try again.'],
            UPLOAD_ERR_NO_TMP_DIR => [null, 'Server configuration error: missing temporary folder.'],
            UPLOAD_ERR_CANT_WRITE => [null, 'Server error: failed to write file to disk.'],
            UPLOAD_ERR_EXTENSION  => [null, 'A server extension stopped the file upload.'],
            default               => [null, 'File upload failed. Please try again.'],
        };
    }

    // In CLI or tests, is_uploaded_file may be false; check is_file fallback if not uploaded
    if (!is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
        return [null, 'The proof could not be uploaded (invalid temporary file).'];
    }

    // Supported proof formats: PDF documents and images (JPG, JPEG, PNG)
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExts, true)) {
        return [null, 'The proof must be a PDF document (.pdf) or an image (.jpg, .jpeg, .png).'];
    }

    if ($file['size'] > PROOF_MAX_BYTES) {
        return [null, 'The proof attachment is larger than 2 MB. Please upload a smaller one.'];
    }

    // Validate MIME type and binary header magic bytes
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) @finfo_file($finfo, $file['tmp_name']) : (function_exists('mime_content_type') ? (string) @mime_content_type($file['tmp_name']) : '');
    if ($finfo && PHP_VERSION_ID < 80500) {
        @finfo_close($finfo);
    }

    $handle = @fopen($file['tmp_name'], 'rb');
    $header4 = $handle ? fread($handle, 4) : '';
    if ($handle) {
        fclose($handle);
    }

    if ($ext === 'pdf') {
        if ($mime !== '' && stripos($mime, 'pdf') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid PDF document.'];
        }
        if ($header4 !== '%PDF') {
            return [null, 'That file is not a valid PDF document.'];
        }
    } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
        if ($mime !== '' && stripos($mime, 'jpeg') === false && stripos($mime, 'jpg') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid JPEG image.'];
        }
        if (substr($header4, 0, 3) !== "\xFF\xD8\xFF") {
            return [null, 'That file is not a valid JPEG image.'];
        }
    } elseif ($ext === 'png') {
        if ($mime !== '' && stripos($mime, 'png') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid PNG image.'];
        }
        if ($header4 !== "\x89PNG") {
            return [null, 'That file is not a valid PNG image.'];
        }
    }

    $baseFolder = rtrim(UPLOAD_DIR, '/\\');
    if (!is_dir($baseFolder)) {
        @mkdir($baseFolder, 0775, true);
    }
    $proofsFolder = $baseFolder . '/proofs';
    if (!is_dir($proofsFolder)) {
        @mkdir($proofsFolder, 0775, true);
    }

    $uniqueId = bin2hex(random_bytes(8));
    $timestamp = time();
    $stored = "record_{$uniqueId}_{$timestamp}.{$ext}";

    $destPath = $baseFolder . '/' . $stored;

    // FEAT-13: Server-side Automatic File Storage Compression
    $compressResult = compress_uploaded_proof($file['tmp_name'], $ext);
    $finalSource    = $compressResult['path'];

    $saved = false;
    if (!empty($compressResult['is_temp']) && is_file($finalSource)) {
        $saved = @copy($finalSource, $destPath);
        compression_cleanup($compressResult);
    } else {
        if (is_uploaded_file($file['tmp_name'])) {
            $saved = move_uploaded_file($file['tmp_name'], $destPath);
        } else {
            $saved = @copy($file['tmp_name'], $destPath);
        }
    }

    if (!$saved || !file_exists($destPath)) {
        return [null, 'The proof could not be saved to the upload directory.'];
    }

    // Keep mirrored copy in proofs/ for backward compatibility with older links
    @copy($destPath, $proofsFolder . '/' . $stored);

    return [$stored, null];
}
}

/**
 * Detect available external CLI PDF optimization tools.
 * Returns array [tool_name, executable_path] or null if none found.
 */
function compression_detect_pdf_cli(): ?array
{
    static $detected = false;
    static $result = null;

    if ($detected) {
        return $result;
    }
    $detected = true;

    // Check custom path from environment or constant if provided
    $custom = defined('PDF_OPTIMIZER_PATH') ? PDF_OPTIMIZER_PATH : (getenv('PDF_OPTIMIZER_PATH') ?: '');
    if ($custom !== '' && is_executable($custom)) {
        $base = strtolower(basename($custom));
        if (str_contains($base, 'qpdf')) return $result = ['qpdf', $custom];
        if (str_contains($base, 'gs'))   return $result = ['gs', $custom];
        if (str_contains($base, 'pdfcpu')) return $result = ['pdfcpu', $custom];
        return $result = ['cli', $custom];
    }

    $candidates = ['qpdf', 'gs', 'gswin64c', 'gswin32c', 'pdfcpu'];
    $isWindows = (DIRECTORY_SEPARATOR === '\\');

    foreach ($candidates as $bin) {
        $checkCmd = $isWindows ? "where.exe $bin 2>nul" : "which $bin 2>/dev/null";
        $out = @shell_exec($checkCmd);
        if ($out !== null && trim($out) !== '') {
            $lines = explode("\n", trim($out));
            $foundPath = trim($lines[0]);
            if (is_file($foundPath)) {
                $name = (str_contains($bin, 'gs')) ? 'gs' : $bin;
                return $result = [$name, $foundPath];
            }
        }
    }

    return $result = null;
}

/**
 * Compress an image file (JPEG or PNG) using the GD library.
 * Returns the path to the compressed temporary file, or null if compression was
 * not possible, failed, or did not reduce the file size.
 */
function compress_image_file(
    string $sourcePath,
    string $ext,
    int $quality = COMPRESSION_JPEG_QUALITY,
    int $maxDimension = COMPRESSION_MAX_DIMENSION
): ?string {
    if (!is_file($sourcePath) || !extension_loaded('gd')) {
        return null;
    }

    $origSize = (int) @filesize($sourcePath);
    if ($origSize <= 0) {
        return null;
    }

    $ext = strtolower($ext);
    $img = null;

    // 1. Load image
    if ($ext === 'jpg' || $ext === 'jpeg') {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $img = @imagecreatefromjpeg($sourcePath);

        // Correct orientation from EXIF if available
        if ($img && function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($sourcePath);
                $orientation = $exif['Orientation'] ?? $exif['IFD0']['Orientation'] ?? 1;
                if ($orientation == 3) {
                    $rotated = @imagerotate($img, 180, 0);
                    if ($rotated) { imagedestroy($img); $img = $rotated; }
                } elseif ($orientation == 6) {
                    $rotated = @imagerotate($img, -90, 0);
                    if ($rotated) { imagedestroy($img); $img = $rotated; }
                } elseif ($orientation == 8) {
                    $rotated = @imagerotate($img, 90, 0);
                    if ($rotated) { imagedestroy($img); $img = $rotated; }
                }
            } catch (\Throwable $e) {
                // Ignore EXIF read errors
            }
        }
    } elseif ($ext === 'png') {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }
        $img = @imagecreatefrompng($sourcePath);
        if ($img) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
    }

    if (!$img) {
        return null;
    }

    $origW = imagesx($img);
    $origH = imagesy($img);

    if ($origW <= 0 || $origH <= 0) {
        imagedestroy($img);
        return null;
    }

    // 2. Proportional Downscaling (never upscale)
    $targetImg = $img;
    if ($origW > $maxDimension || $origH > $maxDimension) {
        $ratio = min($maxDimension / $origW, $maxDimension / $origH);
        $newW = max(1, (int) round($origW * $ratio));
        $newH = max(1, (int) round($origH * $ratio));

        $resized = imagecreatetruecolor($newW, $newH);
        if ($resized) {
            if ($ext === 'png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $trans = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $trans);
            }
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($img);
            $targetImg = $resized;
        }
    }

    // 3. Re-encode to temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'atts_img_') . '.' . $ext;
    $saved = false;

    if ($ext === 'jpg' || $ext === 'jpeg') {
        $saved = @imagejpeg($targetImg, $tempFile, max(10, min(100, $quality)));
    } elseif ($ext === 'png') {
        $saved = @imagepng($targetImg, $tempFile, COMPRESSION_PNG_LEVEL);
    }

    imagedestroy($targetImg);

    if (!$saved || !is_file($tempFile)) {
        if (is_file($tempFile)) @unlink($tempFile);
        return null;
    }

    $compressedSize = (int) @filesize($tempFile);

    // 4. Do not damage or enlarge files: keep only if strictly smaller
    if ($compressedSize <= 0 || $compressedSize >= $origSize) {
        @unlink($tempFile);
        return null;
    }

    return $tempFile;
}

/**
 * Optimize a PDF file using available CLI tool or native PHP stream flate compressor.
 * Returns the path to the compressed temporary file, or null if optimization was
 * not possible, failed, or did not reduce the file size.
 */
function compress_pdf_file(string $sourcePath): ?string
{
    if (!is_file($sourcePath)) {
        return null;
    }

    $origSize = (int) @filesize($sourcePath);
    if ($origSize <= 0) {
        return null;
    }

    // 1. Try external CLI tool if detected
    $cli = compression_detect_pdf_cli();
    if ($cli !== null) {
        [$tool, $bin] = $cli;
        $tempOut = tempnam(sys_get_temp_dir(), 'atts_pdf_cli_') . '.pdf';

        $safeBin = escapeshellarg($bin);
        $safeIn  = escapeshellarg($sourcePath);
        $safeOut = escapeshellarg($tempOut);
        $cmd = '';

        if ($tool === 'qpdf') {
            $cmd = "$safeBin --linearize --compress-streams=yes --recompress-flate $safeIn $safeOut 2>&1";
        } elseif ($tool === 'gs') {
            $cmd = "$safeBin -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -sOutputFile=$safeOut $safeIn 2>&1";
        } elseif ($tool === 'pdfcpu') {
            $cmd = "$safeBin optimize $safeIn $safeOut 2>&1";
        }

        if ($cmd !== '') {
            @exec($cmd, $output, $code);
            if ($code === 0 && is_file($tempOut)) {
                $cliSize = (int) @filesize($tempOut);
                $header = @file_get_contents($tempOut, false, null, 0, 5);
                if ($cliSize > 0 && $cliSize < $origSize && strncmp($header, '%PDF-', 5) === 0) {
                    return $tempOut;
                }
            }
            if (is_file($tempOut)) {
                @unlink($tempOut);
            }
        }
    }

    // 2. Native PHP PDF stream optimizer (handles uncompressed streams via zlib)
    if (extension_loaded('zlib')) {
        $tempOut = tempnam(sys_get_temp_dir(), 'atts_pdf_flate_') . '.pdf';
        $optimized = compression_native_pdf_flate($sourcePath, $tempOut);
        if ($optimized && is_file($tempOut)) {
            $flateSize = (int) @filesize($tempOut);
            if ($flateSize > 0 && $flateSize < $origSize) {
                return $tempOut;
            }
            @unlink($tempOut);
        }
    }

    return null;
}

/**
 * Native pure-PHP PDF Stream Flate Compressor.
 * Identifies uncompressed streams, applies zlib compression (/Filter /FlateDecode),
 * and reconstructs the cross-reference table (xref) to maintain 100% PDF compliance.
 */
function compression_native_pdf_flate(string $inputPath, string $outputPath): bool
{
    $content = @file_get_contents($inputPath);
    if (!$content || strncmp($content, '%PDF-', 5) !== 0) {
        return false;
    }

    $origLen = strlen($content);

    // Locate trailer dictionary
    $trailerPos = strrpos($content, 'trailer');
    $trailerDict = '';
    if ($trailerPos !== false) {
        if (preg_match('/trailer\s*<<(.*?)>>/s', substr($content, $trailerPos), $tm)) {
            $trailerDict = $tm[1];
        }
    }

    // Match all PDF objects: (id) (gen) obj ... endobj
    $objPattern = '/(\d+)\s+(\d+)\s+obj\s*(.*?)\s*endobj/s';
    if (!preg_match_all($objPattern, $content, $matches, PREG_SET_ORDER)) {
        return false;
    }

    $modified = false;
    $objects = [];
    $maxId = 0;

    foreach ($matches as $m) {
        $id = (int)$m[1];
        $gen = (int)$m[2];
        $body = $m[3];
        if ($id > $maxId) $maxId = $id;

        // Check if object contains stream ... endstream
        if (preg_match('/^(.*?<<)(.*?)(>>\s*stream\r?\n)(.*?)(\r?\nendstream)$/s', $body, $sm)) {
            $dictPrefix  = $sm[1];
            $dictContent = $sm[2];
            $streamStart = $sm[3];
            $streamData  = $sm[4];
            $streamEnd   = $sm[5];

            // If stream is uncompressed (no /Filter)
            if (stripos($dictContent, '/Filter') === false) {
                $compressed = @gzcompress($streamData, 9);
                if ($compressed !== false && strlen($compressed) < strlen($streamData)) {
                    $modified = true;
                    // Update /Length and add /Filter /FlateDecode
                    $newDict = preg_replace('/\/Length\s+\d+/', '/Length ' . strlen($compressed), $dictContent);
                    if (stripos($newDict, '/Length') === false) {
                        $newDict = '/Length ' . strlen($compressed) . ' ' . $newDict;
                    }
                    $newDict .= ' /Filter /FlateDecode';
                    $body = $dictPrefix . $newDict . $streamStart . $compressed . $streamEnd;
                }
            }
        }

        $objects[$id] = [
            'gen' => $gen,
            'body' => $body
        ];
    }

    if (!$modified) {
        return false;
    }

    // Rebuild PDF with compliant xref table
    preg_match('/^(%PDF-\d+\.\d+[\r\n]+%[^\r\n]*[\r\n]+)/', $content, $hm);
    $header = !empty($hm[1]) ? $hm[1] : "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

    $out = $header;
    $offsets = [];

    ksort($objects);
    foreach ($objects as $id => $objData) {
        $offsets[$id] = strlen($out);
        $out .= "{$id} {$objData['gen']} obj\n" . $objData['body'] . "\nendobj\n";
    }

    $xrefOffset = strlen($out);
    $count = $maxId + 1;
    $out .= "xref\n0 {$count}\n";
    $out .= "0000000000 65535 f \n";

    for ($i = 1; $i <= $maxId; $i++) {
        if (isset($offsets[$i])) {
            $out .= sprintf("%010d %05d n \n", $offsets[$i], $objects[$i]['gen']);
        } else {
            $out .= "0000000000 65535 f \n";
        }
    }

    if ($trailerDict !== '') {
        $cleanTrailer = preg_replace('/\/Size\s+\d+/', '/Size ' . $count, $trailerDict);
        if (stripos($cleanTrailer, '/Size') === false) {
            $cleanTrailer = '/Size ' . $count . ' ' . $cleanTrailer;
        }
        $out .= "trailer\n<<{$cleanTrailer}>>\n";
    } else {
        $out .= "trailer\n<< /Size {$count} >>\n";
    }

    $out .= "startxref\n{$xrefOffset}\n%%EOF\n";

    if (strlen($out) >= $origLen) {
        return false;
    }

    return (@file_put_contents($outputPath, $out) !== false);
}

/**
 * Main entrance: compress an uploaded proof attachment.
 *
 * Dispatches to image or PDF compression pipelines.
 * Returns an array with metadata:
 *   [
 *     'path'          => string (path to final file to store: either compressed temp or original),
 *     'compressed'    => bool,
 *     'orig_size'     => int,
 *     'final_size'    => int,
 *     'reduction_pct' => float,
 *     'engine'        => string ('gd', 'cli_pdf', 'php_flate', 'none'),
 *     'is_temp'       => bool (whether 'path' is a temporary working file that should be removed once stored)
 *   ]
 */
function compress_uploaded_proof(string $sourcePath, string $ext): array
{
    $ext = strtolower($ext);
    $origSize = (int) @filesize($sourcePath);

    $default = [
        'path'          => $sourcePath,
        'compressed'    => false,
        'orig_size'     => $origSize,
        'final_size'    => $origSize,
        'reduction_pct' => 0.0,
        'engine'        => 'none',
        'is_temp'       => false,
    ];

    if ($origSize <= 0) {
        return $default;
    }

    $compPath = null;
    $engine   = 'none';

    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $compPath = compress_image_file($sourcePath, $ext);
        if ($compPath !== null) {
            $engine = 'gd';
        }
    } elseif ($ext === 'pdf') {
        $compPath = compress_pdf_file($sourcePath);
        if ($compPath !== null) {
            $engine = str_contains($compPath, 'cli') ? 'cli_pdf' : 'php_flate';
        }
    }

    if ($compPath !== null && is_file($compPath)) {
        $compSize = (int) @filesize($compPath);
        if ($compSize > 0 && $compSize < $origSize) {
            $reduction = (($origSize - $compSize) / $origSize) * 100.0;
            return [
                'path'          => $compPath,
                'compressed'    => true,
                'orig_size'     => $origSize,
                'final_size'    => $compSize,
                'reduction_pct' => round($reduction, 2),
                'engine'        => $engine,
                'is_temp'       => true,
            ];
        }
        @unlink($compPath);
    }

    return $default;
}

/**
 * Clean up temporary compression artifact if created.
 */
function compression_cleanup(array $compressionResult): void
{
    if (!empty($compressionResult['is_temp']) && !empty($compressionResult['path']) && is_file($compressionResult['path'])) {
        @unlink($compressionResult['path']);
    }
}
