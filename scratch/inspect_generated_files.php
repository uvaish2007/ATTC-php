<?php
echo "========================================================\n";
echo "INSPECTING GENERATED REAL EXPORT FILES\n";
echo "========================================================\n\n";

// 1. Inspect real_journal_report.xlsx
echo "--- 1. REAL JOURNAL REPORT EXCEL (.xlsx) ---\n";
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/real_journal_report.xlsx') === true) {
    echo "Files inside XLSX package:\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        echo " - " . $zip->getNameIndex($i) . "\n";
    }

    $rels = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
    echo "\nHyperlink relationships in sheet1.xml.rels (sample):\n";
    preg_match_all('/<Relationship[^>]+>/', $rels, $matches);
    foreach (array_slice($matches[0], 0, 5) as $relTag) {
        echo "   $relTag\n";
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    echo "\nHyperlinks in sheet1.xml (sample):\n";
    preg_match_all('/<hyperlink[^>]+>/', $sheet, $hlMatches);
    foreach (array_slice($hlMatches[0], 0, 5) as $hlTag) {
        echo "   $hlTag\n";
    }
    $zip->close();
} else {
    echo "Failed to open XLSX\n";
}

// 2. Inspect real_journal_report.doc
echo "\n--- 2. REAL JOURNAL REPORT WORD (.doc) ---\n";
$docContent = file_get_contents(__DIR__ . '/real_journal_report.doc');
echo "Size: " . strlen($docContent) . " bytes\n";
echo "Contains WordSection1: " . (strpos($docContent, 'div.WordSection1') !== false ? 'YES' : 'NO') . "\n";
echo "Contains centered banner table: " . (strpos($docContent, 'rpt-banner-wrap') !== false ? 'YES' : 'NO') . "\n";
echo "Contains Proof Attachment column header: " . (strpos($docContent, 'Proof Attachment') !== false ? 'YES' : 'NO') . "\n";
preg_match_all('/<a href="[^"]*proof\.php[^"]*"[^>]*>[^<]*<\/a>/i', $docContent, $docLinks);
echo "Count of clickable proof links in Word doc: " . count($docLinks[0]) . "\n";
if (!empty($docLinks[0])) {
    echo "Sample link in Word doc: " . htmlspecialchars($docLinks[0][0]) . "\n";
}

// 3. Inspect real_journal_report.pdf.html
echo "\n--- 3. REAL JOURNAL REPORT PDF PRINT VIEW ---\n";
$pdfHtml = file_get_contents(__DIR__ . '/real_journal_report.pdf.html');
echo "Size: " . strlen($pdfHtml) . " bytes\n";
echo "Contains window.print() button: " . (strpos($pdfHtml, 'window.print()') !== false ? 'YES' : 'NO') . "\n";
echo "Contains PDF bar: " . (strpos($pdfHtml, 'pdf-bar') !== false ? 'YES' : 'NO') . "\n";
echo "Contains Proof Attachment column: " . (strpos($pdfHtml, 'Proof Attachment') !== false ? 'YES' : 'NO') . "\n";
preg_match_all('/<a href="[^"]*proof\.php[^"]*"[^>]*>[^<]*<\/a>/i', $pdfHtml, $pdfLinks);
echo "Count of clickable proof links in PDF view: " . count($pdfLinks[0]) . "\n";

// 4. Inspect real_consolidated_report.xlsx
echo "\n--- 4. REAL CONSOLIDATED REPORT EXCEL (.xlsx) ---\n";
if ($zip->open(__DIR__ . '/real_consolidated_report.xlsx') === true) {
    $cRels = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
    preg_match_all('/<Relationship[^>]+>/', $cRels, $cMatches);
    echo "Hyperlink relationships count in consolidated report: " . count($cMatches[0]) . "\n";
    if (!empty($cMatches[0])) {
        echo "Sample relationship: " . $cMatches[0][0] . "\n";
    }
    $zip->close();
}

echo "\nVerification of generated physical files complete.\n";
