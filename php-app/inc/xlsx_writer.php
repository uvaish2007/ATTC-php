<?php
class SimpleZipWriter
{
    private array $entries = [];
    private string $data   = '';

    public function addFromString(string $name, string $content): void
    {
        $crc     = crc32($content);
        $rawSize = strlen($content);

        $method     = 0;
        $compressed = $content;
        $deflated   = @gzdeflate($content, 6);
        if ($deflated !== false && strlen($deflated) < $rawSize) {
            $method     = 8;
            $compressed = $deflated;
        }

        [$dosTime, $dosDate] = self::dosTimestamp();

        $this->entries[] = [
            'name'     => $name,
            'offset'   => strlen($this->data),
            'crc'      => $crc,
            'method'   => $method,
            'compSize' => strlen($compressed),
            'rawSize'  => $rawSize,
            'time'     => $dosTime,
            'date'     => $dosDate,
        ];

        $this->data .= pack('VvvvvvVVVvv',
            0x04034b50,                        20,                                0,                                 $method,
            $dosTime,
            $dosDate,
            $crc,
            strlen($compressed),
            $rawSize,
            strlen($name),
            0                              ) . $name . $compressed;
    }

    public function getContents(): string
    {
        $central      = '';
        $centralStart = strlen($this->data);

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

class SimpleXlsxWriter
{
    public static function createXlsx(array $headers, array $rows, string $sheetTitle = 'Report', array $meta = []): string
    {
        $zip = new SimpleZipWriter();

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
                    $valStr = is_array($r[$i]) ? (string)($r[$i]['text'] ?? '') : (string)$r[$i];
                    $lines = explode("\n", $valStr);
                    foreach ($lines as $line) {
                        $maxLen = max($maxLen, mb_strlen($line));
                    }
                }
            }
            $colWidths[$i] = min(60, max(10, $maxLen + 3));
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        $sheetNameClean = self::sanitizeXml(mb_substr($sheetTitle, 0, 31));
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="' . $sheetNameClean . '" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="5">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF000000"/></font>
    <font><b/><sz val="13"/><name val="Calibri"/><color rgb="FF1A2547"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF1A2547"/></font>
    <font><u/><sz val="11"/><name val="Calibri"/><color rgb="FF0044CC"/></font>
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
  <cellXfs count="6">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

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

        $hyperlinksXml = '';
        if (!empty($hyperlinks)) {
            $hyperlinksXml = '<hyperlinks>';
            foreach ($hyperlinks as $h) {
                $hyperlinksXml .= '<hyperlink ref="' . $h['ref'] . '" r:id="' . $h['rId'] . '" display="' . self::sanitizeXml($h['display']) . '"/>';
            }
            $hyperlinksXml .= '</hyperlinks>';

            $sheet1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            foreach ($hyperlinks as $h) {
                $sheet1Rels .= '  <Relationship Id="' . $h['rId'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . self::sanitizeXml($h['url']) . '" TargetMode="External"/>' . "\n";
            }
            $sheet1Rels .= '</Relationships>';
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheet1Rels);
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  ' . $colsXml . '
  <sheetData>' . $sheetData . '</sheetData>
  ' . $hyperlinksXml . '
</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        return $zip->getContents();
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
