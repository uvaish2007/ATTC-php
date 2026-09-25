<?php
/**
 * Lightweight, native XLSX file generator using ZipArchive and OpenXML.
 * Generates valid .xlsx files compatible with Microsoft Excel, Google Sheets, LibreOffice.
 */

class SimpleXlsxWriter
{
    /**
     * Generate a single-sheet XLSX workbook.
     */
    public static function createXlsx(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        return self::createMultiSheetXlsx([
            [
                'title'   => $sheetTitle,
                'headers' => $headers,
                'rows'    => $rows,
                'meta'    => $meta,
            ]
        ]);
    }

    /**
     * Generate a multi-tab/multi-sheet XLSX workbook.
     *
     * @param array $sheets Array of sheet specifications:
     *   [
     *     [
     *       'title'   => 'Overview', // Tab name, max 31 chars
     *       'headers' => ['Col 1', 'Col 2', ...],
     *       'rows'    => [...],
     *       'meta'    => ['Report Title', 'Date: ...'],
     *     ],
     *     ...
     *   ]
     * @return string Raw binary XLSX data
     */
    public static function createMultiSheetXlsx(array $sheets): string
    {
        if (!class_exists('ZipArchive') || empty($sheets)) {
            return '';
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_m_');
        if ($tempFile === false) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return '';
        }

        $sheetCount = count($sheets);

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        for ($s = 1; $s <= $sheetCount; $s++) {
            $contentTypes .= "\n  <Override PartName=\"/xl/worksheets/sheet{$s}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $contentTypes .= "\n</Types>";
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($s = 1; $s <= $sheetCount; $s++) {
            $wbRels .= "\n  <Relationship Id=\"rId{$s}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$s}.xml\"/>";
        }
        $stylesRId = $sheetCount + 1;
        $wbRels .= "\n  <Relationship Id=\"rId{$stylesRId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";
        $wbRels .= "\n</Relationships>";
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $usedTitles = [];
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>';
        $sheetIdx = 1;
        foreach ($sheets as $sh) {
            $rawTitle = trim((string)($sh['title'] ?? 'Sheet' . $sheetIdx));
            $cleanTitle = preg_replace('/[\\\\\\/\?\*\:\[\]]/', '', $rawTitle);
            $cleanTitle = trim($cleanTitle) ?: 'Sheet' . $sheetIdx;
            $cleanTitle = mb_substr($cleanTitle, 0, 31);

            $uniqueTitle = $cleanTitle;
            $tSuffix = 1;
            while (isset($usedTitles[strtolower($uniqueTitle)])) {
                $uniqueTitle = mb_substr($cleanTitle, 0, 28) . '_' . ($tSuffix++);
            }
            $usedTitles[strtolower($uniqueTitle)] = true;

            $workbook .= "\n    <sheet name=\"" . self::sanitizeXml($uniqueTitle) . "\" sheetId=\"{$sheetIdx}\" r:id=\"rId{$sheetIdx}\"/>";
            $sheetIdx++;
        }
        $workbook .= "\n  </sheets>\n</workbook>";
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 5. xl/styles.xml
        $styles = self::getStylesXml();
        $zip->addFromString('xl/styles.xml', $styles);

        // 6. Each worksheet: xl/worksheets/sheet{N}.xml
        $sheetNum = 1;
        foreach ($sheets as $sh) {
            $headers = $sh['headers'] ?? [];
            $rows    = $sh['rows'] ?? [];
            $meta    = $sh['meta'] ?? [];

            $xml = self::buildWorksheetXml($headers, $rows, $meta);
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", $xml);
            $sheetNum++;
        }

        $zip->close();
        $data = (string) file_get_contents($tempFile);
        @unlink($tempFile);
        return $data;
    }

    public static function getStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="4">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF000000"/></font>
    <font><b/><sz val="13"/><name val="Calibri"/><color rgb="FF1A2547"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF1A2547"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEAEFE9"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF2F4F7"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD0D5DD"/></left>
      <right style="thin"><color rgb="FFD0D5DD"/></right>
      <top style="thin"><color rgb="FFD0D5DD"/></top>
      <bottom style="thin"><color rgb="FFD0D5DD"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="5">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>';
    }

    public static function buildWorksheetXml(array $headers, array $rows, array $meta = []): string
    {
        // Calculate column widths
        $colWidths = [];
        $cCount = count($headers);
        foreach ($rows as $r) {
            if (is_array($r)) {
                $cCount = max($cCount, count($r));
            }
        }
        $cCount = max(1, $cCount);

        for ($i = 0; $i < $cCount; $i++) {
            $maxLen = 10;
            if (isset($headers[$i])) {
                $maxLen = max($maxLen, mb_strlen((string)$headers[$i]));
            }
            foreach ($rows as $r) {
                if (isset($r[$i])) {
                    $valStr = (string)$r[$i];
                    $lines = explode("\n", $valStr);
                    foreach ($lines as $line) {
                        $maxLen = max($maxLen, mb_strlen($line));
                    }
                }
            }
            $colWidths[$i] = min(60, max(10, $maxLen + 3));
        }

        $colsXml = '<cols>';
        foreach ($colWidths as $ci => $w) {
            $colsXml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $sheetData = '';
        $rIdx = 1;

        if (!empty($meta)) {
            foreach ($meta as $mIdx => $line) {
                if (trim((string)$line) === '') {
                    $sheetData .= '<row r="' . $rIdx . '"/>';
                } else {
                    $sheetData .= '<row r="' . $rIdx . '">';
                    $styleId = ($mIdx === 0) ? '2' : '3';
                    $sheetData .= '<c r="A' . $rIdx . '" s="' . $styleId . '" t="inlineStr"><is><t>' . self::sanitizeXml((string)$line) . '</t></is></c>';
                    $sheetData .= '</row>';
                }
                $rIdx++;
            }
            $sheetData .= '<row r="' . $rIdx . '"/>';
            $rIdx++;
        }

        // Header Row
        if (!empty($headers)) {
            $sheetData .= '<row r="' . $rIdx . '" ht="24" customHeight="1">';
            $cIdx = 0;
            foreach ($headers as $h) {
                $colLetter = self::getColLetter($cIdx);
                $sheetData .= '<c r="' . $colLetter . $rIdx . '" s="1" t="inlineStr"><is><t>' . self::sanitizeXml((string)$h) . '</t></is></c>';
                $cIdx++;
            }
            $sheetData .= '</row>';
            $rIdx++;
        }

        // Data Rows
        foreach ($rows as $row) {
            $sheetData .= '<row r="' . $rIdx . '">';
            $cIdx = 0;
            $isTotalRow = (isset($row[1]) && strtoupper(trim((string)$row[1])) === 'TOTAL') || (isset($row[0]) && strtoupper(trim((string)$row[0])) === 'TOTAL');
            $cellStyle = $isTotalRow ? '4' : '0';

            foreach ($row as $val) {
                $colLetter = self::getColLetter($cIdx);
                $valStr = (string)$val;
                if (is_numeric($val) && !preg_match('/^0\d+/', $valStr)) {
                    $sheetData .= '<c r="' . $colLetter . $rIdx . '" s="' . $cellStyle . '"><v>' . $valStr . '</v></c>';
                } else {
                    $sheetData .= '<c r="' . $colLetter . $rIdx . '" s="' . $cellStyle . '" t="inlineStr"><is><t>' . self::sanitizeXml($valStr) . '</t></is></c>';
                }
                $cIdx++;
            }
            $sheetData .= '</row>';
            $rIdx++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  ' . $colsXml . '
  <sheetData>' . $sheetData . '</sheetData>
</worksheet>';
    }

    private static function sanitizeXml(string $str): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str);
        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function getColLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex >= 0) {
            $letter = chr($colIndex % 26 + 65) . $letter;
            $colIndex = intdiv($colIndex, 26) - 1;
        }
        return $letter;
    }
}
