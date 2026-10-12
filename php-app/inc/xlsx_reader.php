<?php
/**
 * SimpleXlsxReader - Pure PHP XLSX and CSV reader using ZipArchive and XMLReader.
 *
 * Fully supports multi-sheet workbooks, shared strings, inline strings,
 * formulas, numeric/date cells, and CSV fallback with zero third-party dependencies.
 */

class SimpleXlsxReader
{
    /**
     * Parse an XLSX or CSV file into an associative array of sheets.
     *
     * @param string $filePath Full path to the file.
     * @return array [ 'SheetName' => [ [col0, col1, ...], [row2_col0, ...], ... ], ... ]
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return self::parseCsv($filePath);
        }

        $zip = new ZipArchive();
        $res = $zip->open($filePath);
        if ($res !== true) {
            // Check if it's actually a plain CSV file with .xlsx extension
            $handle = @fopen($filePath, 'r');
            if ($handle) {
                $firstLine = fgets($handle);
                fclose($handle);
                if ($firstLine !== false && (strpos($firstLine, ',') !== false || strpos($firstLine, "\t") !== false)) {
                    return self::parseCsv($filePath);
                }
            }
            throw new RuntimeException("Could not open file as an Excel workbook. Please verify it is a valid .xlsx file.");
        }

        // 1. Shared Strings Table (xl/sharedStrings.xml)
        $sharedStrings = [];
        $ssXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXmlContent !== false) {
            $sharedStrings = self::parseSharedStrings($ssXmlContent);
        }

        // 2. Workbook structure & Relationships
        $sheetFiles = [];
        $wbXmlContent = $zip->getFromName('xl/workbook.xml');
        $wbRelsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($wbXmlContent !== false) {
            $sheetMap = self::parseWorkbookSheets($wbXmlContent);
            $relsMap  = $wbRelsContent !== false ? self::parseRelationships($wbRelsContent) : [];

            foreach ($sheetMap as $sheetId => $sheetInfo) {
                $rId = $sheetInfo['rId'];
                $target = $relsMap[$rId] ?? "worksheets/sheet{$sheetId}.xml";
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $sheetFiles[$sheetInfo['name']] = $target;
            }
        }

        // Fallback: search all sheet XMLs directly in archive
        if (empty($sheetFiles)) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/^xl\/worksheets\/sheet(\d+)\.xml$/i', $name, $m)) {
                    $sheetFiles['Sheet' . $m[1]] = $name;
                }
            }
        }

        $result = [];
        foreach ($sheetFiles as $sheetName => $sheetPath) {
            $wsContent = $zip->getFromName($sheetPath);
            if ($wsContent === false) {
                continue;
            }
            $result[$sheetName] = self::parseWorksheet($wsContent, $sharedStrings);
        }

        $zip->close();

        if (empty($result)) {
            throw new RuntimeException("No readable worksheets were found in the uploaded workbook.");
        }

        return $result;
    }

    /**
     * Locate the primary data sheet from the workbook, prioritizing sheets
     * named "Student Records", "Data", "Records", or the first non-instruction sheet.
     */
    public static function getPrimaryDataSheet(array $sheets): array
    {
        if (empty($sheets)) {
            return [];
        }

        $preferred = ['student records', 'internship', 'summer training', 'training', 'records', 'data', 'sheet1'];
        foreach ($preferred as $pref) {
            foreach ($sheets as $name => $rows) {
                if (stripos($name, $pref) !== false && !empty($rows)) {
                    return $rows;
                }
            }
        }

        // If no preferred sheet matches, return first sheet that is not named "Instructions"
        foreach ($sheets as $name => $rows) {
            if (stripos($name, 'instruction') === false && !empty($rows)) {
                return $rows;
            }
        }

        // Fallback to the first sheet
        return reset($sheets) ?: [];
    }

    private static function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Could not open CSV file.");
        }
        $rows = [];
        // Handle UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($data === [null] || (count($data) === 1 && trim((string)$data[0]) === '')) {
                continue;
            }
            $rows[] = array_map(fn($v) => trim((string)$v), $data);
        }
        fclose($handle);
        return ['Sheet1' => $rows];
    }

    private static function parseSharedStrings(string $xmlContent): array
    {
        $strings = [];
        $reader = new XMLReader();
        $reader->XML($xmlContent, null, LIBXML_NOERROR | LIBXML_NOWARNING);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $node = $reader->readOuterXml();
                $str = '';
                if (preg_match_all('/<t(?:\s+[^>]*)?>(.*?)<\/t>/su', $node, $matches)) {
                    foreach ($matches[1] as $part) {
                        $str .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $strings[] = $str;
            }
        }
        $reader->close();
        return $strings;
    }

    private static function parseWorkbookSheets(string $xmlContent): array
    {
        $sheets = [];
        if (preg_match_all('/<sheet\s+[^>]*name=["\']([^"\']+)["\'][^>]*sheetId=["\'](\d+)["\'][^>]*(?:r:id|id)=["\']([^"\']+)["\'][^>]*\/?>/iu', $xmlContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $sheets[(int)$m[2]] = [
                    'name' => html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    'rId'  => $m[3],
                ];
            }
        } elseif (preg_match_all('/<sheet\s+[^>]*name=["\']([^"\']+)["\'][^>]*>/iu', $xmlContent, $matches, PREG_SET_ORDER)) {
            $i = 1;
            foreach ($matches as $m) {
                $rId = 'rId' . $i;
                if (preg_match('/(?:r:id|id)=["\']([^"\']+)["\']/i', $m[0], $idM)) {
                    $rId = $idM[1];
                }
                $sheets[$i] = [
                    'name' => html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    'rId'  => $rId,
                ];
                $i++;
            }
        }
        return $sheets;
    }

    private static function parseRelationships(string $xmlContent): array
    {
        $rels = [];
        if (preg_match_all('/<Relationship\s+[^>]*Id=["\']([^"\']+)["\'][^>]*Target=["\']([^"\']+)["\'][^>]*\/?>/iu', $xmlContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rels[$m[1]] = $m[2];
            }
        }
        return $rels;
    }

    private static function colLetterToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $len = strlen($letters);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1; // 0-based
    }

    private static function parseWorksheet(string $xmlContent, array $sharedStrings): array
    {
        $rows = [];
        $reader = new XMLReader();
        $reader->XML($xmlContent, null, LIBXML_NOERROR | LIBXML_NOWARNING);

        $currentRow = [];
        $currentCellRef = '';
        $currentCellType = '';
        $currentVal = null;
        $inValue = false;
        $inInline = false;
        $maxCol = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'row') {
                    $currentRow = [];
                    $maxCol = 0;
                } elseif ($reader->localName === 'c') {
                    $currentCellRef  = $reader->getAttribute('r') ?? '';
                    $currentCellType = $reader->getAttribute('t') ?? '';
                    $currentVal = null;
                } elseif ($reader->localName === 'v') {
                    $inValue = true;
                } elseif ($reader->localName === 't' && $currentCellType === 'inlineStr') {
                    $inInline = true;
                }
            } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                if ($inValue || $inInline) {
                    $currentVal = ($currentVal ?? '') . $reader->value;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'v') {
                    $inValue = false;
                } elseif ($reader->localName === 't' && $currentCellType === 'inlineStr') {
                    $inInline = false;
                } elseif ($reader->localName === 'c') {
                    $colIndex = 0;
                    if (preg_match('/^([A-Z]+)(\d+)$/i', $currentCellRef, $cm)) {
                        $colIndex = self::colLetterToIndex($cm[1]);
                    }

                    $resolvedVal = '';
                    if ($currentCellType === 's' && $currentVal !== null && isset($sharedStrings[(int)$currentVal])) {
                        $resolvedVal = $sharedStrings[(int)$currentVal];
                    } elseif ($currentCellType === 'b') {
                        $resolvedVal = ($currentVal === '1') ? 'TRUE' : 'FALSE';
                    } elseif ($currentVal !== null) {
                        $resolvedVal = $currentVal;
                    }

                    $currentRow[$colIndex] = trim((string)$resolvedVal);
                    if ($colIndex > $maxCol) {
                        $maxCol = $colIndex;
                    }
                } elseif ($reader->localName === 'row') {
                    if (!empty($currentRow)) {
                        $denseRow = [];
                        for ($c = 0; $c <= $maxCol; $c++) {
                            $denseRow[$c] = $currentRow[$c] ?? '';
                        }
                        if (array_filter($denseRow, fn($v) => $v !== '') !== []) {
                            $rows[] = $denseRow;
                        }
                    }
                }
            }
        }
        $reader->close();
        return $rows;
    }
}
