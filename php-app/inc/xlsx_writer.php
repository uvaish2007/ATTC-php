<?php
/**
<<<<<<< HEAD
 * SimpleXlsxWriter - Pure PHP XLSX generator with ZIP/XML support.
 *
 * Generates standards-compliant Excel (.xlsx) files without any external
 * dependencies (ZipArchive or Pure-PHP fallback).
 */

class SimpleZipWriter
{
    private string $data = '';
    private array $entries = [];

    public function addFromString(string $name, string $content): void
    {
        $name    = str_replace('\\', '/', $name);
        $rawSize = strlen($content);
        $crc     = crc32($content);

        $deflated = gzdeflate($content);
        if ($deflated !== false && strlen($deflated) < $rawSize) {
            $comp     = $deflated;
            $method   = 8;
            $compSize = strlen($comp);
        } else {
            $comp     = $content;
            $method   = 0;
            $compSize = $rawSize;
        }

        $offset = strlen($this->data);
        [$time, $date] = self::dosTimestamp();

        $local = pack('VvvvvvVVVvv',
            0x04034b50,                        20,                                0,                                 $method,
            $time,                             $date,                             $crc,                              $compSize,
            $rawSize,                          strlen($name),                     0
        ) . $name . $comp;

        $this->data .= $local;

        $this->entries[] = [
            'name'     => $name,
            'method'   => $method,
            'time'     => $time,
            'date'     => $date,
            'crc'      => $crc,
            'compSize' => $compSize,
            'rawSize'  => $rawSize,
            'offset'   => $offset,
        ];
    }

    public function getContents(): string
    {
        $centralStart = strlen($this->data);
        $central      = '';

        foreach ($this->entries as $e) {
            $central .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,                        20,                                20,                                0,                                 $e['method'],
                $e['time'],
                $e['date'],
                $e['crc'],
                $e['compSize'],
                $e['rawSize'],
                strlen($e['name']),
                0,                                 0,                                 0,                                 0,                                 0x20,                              $e['offset']
            ) . $e['name'];
        }

        $count = count($this->entries);

        return $this->data . $central . pack('VvvvvVVv',
            0x06054b50,                        0,                                 0,                                 $count,                            $count,                            strlen($central),
            $centralStart,
            0                              );
    }

    private static function dosTimestamp(): array
    {
        $t    = getdate();
        $year = max(1980, (int) $t['year']);

        return [
            (($t['hours'] << 11) | ($t['minutes'] << 5) | ((int) ($t['seconds'] / 2))) & 0xFFFF,
            ((($year - 1980) << 9) | ($t['mon'] << 5) | $t['mday']) & 0xFFFF,
        ];
    }
}
=======
 * Lightweight, native XLSX file generator using ZipArchive and OpenXML.
 * Generates valid .xlsx files compatible with Microsoft Excel, Google Sheets, LibreOffice.
 */
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1

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
        if (empty($sheets)) {
            return '';
        }

<<<<<<< HEAD
        $zip = new SimpleZipWriter();
        $sheetCount = count($sheets);

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n" .
            '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n" .
            '  <Default Extension="xml" ContentType="application/xml"/>' . "\n" .
            '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n" .
            '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";
        for ($i = 1; $i <= $sheetCount; $i++) {
            $contentTypes .= "  <Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n";
        }
        $contentTypes .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
            '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n" .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
            '  <Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $wbRels .= "\n  <Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>";
        }
=======
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
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
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

<<<<<<< HEAD
            $built = self::buildWorksheetXmlAndRels($headers, $rows, $meta);
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", $built['xml']);
            if (!empty($built['rels'])) {
                $zip->addFromString("xl/worksheets/_rels/sheet{$sheetNum}.xml.rels", $built['rels']);
            }
            $sheetNum++;
        }

        return $zip->getContents();
=======
            $xml = self::buildWorksheetXml($headers, $rows, $meta);
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", $xml);
            $sheetNum++;
        }

        $zip->close();
        $data = (string) file_get_contents($tempFile);
        @unlink($tempFile);
        return $data;
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
    }

    public static function getStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<<<<<<< HEAD
  <fonts count="5">
=======
  <fonts count="4">
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF000000"/></font>
    <font><b/><sz val="13"/><name val="Calibri"/><color rgb="FF1A2547"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF1A2547"/></font>
<<<<<<< HEAD
    <font><u/><sz val="11"/><name val="Calibri"/><color rgb="FF0044CC"/></font>
=======
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
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
<<<<<<< HEAD
  <cellXfs count="6">
=======
  <cellXfs count="5">
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<<<<<<< HEAD
    <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>
=======
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>';
    }

<<<<<<< HEAD
    public static function buildWorksheetXmlAndRels(array $headers, array $rows, array $meta = []): array
    {
=======
    public static function buildWorksheetXml(array $headers, array $rows, array $meta = []): string
    {
        // Calculate column widths
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
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
<<<<<<< HEAD
                    $valStr = is_array($r[$i]) ? (string)($r[$i]['text'] ?? '') : (string)$r[$i];
=======
                    $valStr = (string)$r[$i];
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
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

        $sheetData  = '';
        $hyperlinks = [];
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
                if (is_array($val) && (!empty($val['url']) || !empty($val['href']))) {
                    $hUrl   = (string) ($val['url'] ?? $val['href']);
                    $hText  = (string) ($val['text'] ?? $val['label'] ?? $hUrl);
                    $hRelId = 'rIdH' . (count($hyperlinks) + 1);
                    $cellRef = $colLetter . $rIdx;
                    $hyperlinks[] = [
                        'ref'     => $cellRef,
                        'url'     => $hUrl,
                        'rId'     => $hRelId,
                        'display' => $hText,
                    ];
                    $sheetData .= '<c r="' . $cellRef . '" s="5" t="inlineStr"><is><t>' . self::sanitizeXml($hText) . '</t></is></c>';
                } else {
                    $valStr = (string)$val;
                    if (is_numeric($val) && !preg_match('/^0\d+/', $valStr)) {
                        $sheetData .= '<c r="' . $colLetter . $rIdx . '" s="' . $cellStyle . '"><v>' . $valStr . '</v></c>';
                    } else {
                        $sheetData .= '<c r="' . $colLetter . $rIdx . '" s="' . $cellStyle . '" t="inlineStr"><is><t>' . self::sanitizeXml($valStr) . '</t></is></c>';
                    }
                }
                $cIdx++;
            }
            $sheetData .= '</row>';
            $rIdx++;
        }

<<<<<<< HEAD
        $hyperlinksXml = '';
        $relsXml = '';
        if (!empty($hyperlinks)) {
            $hyperlinksXml = '<hyperlinks>';
            foreach ($hyperlinks as $h) {
                $hyperlinksXml .= '<hyperlink ref="' . $h['ref'] . '" r:id="' . $h['rId'] . '" display="' . self::sanitizeXml($h['display']) . '"/>';
            }
            $hyperlinksXml .= '</hyperlinks>';

            $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            foreach ($hyperlinks as $h) {
                $relsXml .= '  <Relationship Id="' . $h['rId'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . self::sanitizeXml($h['url']) . '" TargetMode="External"/>' . "\n";
            }
            $relsXml .= '</Relationships>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
=======
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
  ' . $colsXml . '
  <sheetData>' . $sheetData . '</sheetData>
  ' . $hyperlinksXml . '
</worksheet>';
<<<<<<< HEAD

        return ['xml' => $sheet, 'rels' => $relsXml];
    }

    public static function buildWorksheetXml(array $headers, array $rows, array $meta = []): string
    {
        $res = self::buildWorksheetXmlAndRels($headers, $rows, $meta);
        return $res['xml'];
=======
>>>>>>> d9afdd10e230ecbc2906ca35576f34d17f2467d1
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

    public static function writeXlsx(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        return self::createXlsx($headers, $rows, $sheetTitle, $meta);
    }

    public static function export(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        return self::createXlsx($headers, $rows, $sheetTitle, $meta);
    }

    public static function download(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        return self::createXlsx($headers, $rows, $sheetTitle, $meta);
    }

    public static function write(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        return self::createXlsx($headers, $rows, $sheetTitle, $meta);
    }

    public static function generate(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        return self::createXlsx($headers, $rows, $sheetTitle, $meta);
    }
}