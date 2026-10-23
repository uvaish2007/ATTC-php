<?php
$cookieFile = __DIR__ . '/live_cookies.txt';

$dummyPdf = "%PDF-1.4\n1 0 obj\n<< /Title (Paper Copy Test Document) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
$tempPdfPath = __DIR__ . '/test_paper_copy.pdf';
file_put_contents($tempPdfPath, $dummyPdf);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/php-app/upload.php?type=conference');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$page = curl_exec($ch);

preg_match('/name=["\']csrf["\']\s+value=["\']([^"\']+)["\']/i', $page, $m);
$csrf = $m[1] ?? '';
echo "CSRF: $csrf\n";

$data = [
    'csrf'             => $csrf,
    'record_type'      => 'conference',
    'main_author_id'   => '60021',
    'department'       => 'Agri',
    'author_type'      => 'First Author',
    'paper_title'      => 'DEEP LEARNING IN ATTS OPTIMIZATION 2060',
    'conference_name'  => 'INTERNATIONAL CONFERENCE ON INNOVATIVE TECH 2060',
    'conference_type'  => 'International',
    'isbn'             => '978-0-12345-678-9',
    'venue'            => 'Chennai Trade Centre, India',
    'conference_date'  => '2026-11-25',
    'publication_year' => '2060',
    'doi'              => '10.1109/ICIT.2060.999',
    'proceedings_link' => 'https://ieee-conference.org/proceedings/2060',
    'co_authors'       => 'Dr. Test CoAuthor, Prof. Sample Colleague',
    'proof'            => new CURLFile($tempPdfPath, 'application/pdf', 'paper_copy.pdf')
];

curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/php-app/upload.php?type=conference');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HEADER, true);

$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Code: $code\n";
echo "Headers/Body excerpt:\n" . substr($res, 0, 1000) . "\n";

curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/php-app/upload.php');
curl_setopt($ch, CURLOPT_POST, false);
$after = curl_exec($ch);
if (preg_match('/<div class="alert alert-([^"]+)">([^<]+)<\/div>/i', $after, $fl)) {
    echo "Flash Alert [{$fl[1]}]: {$fl[2]}\n";
} else {
    echo "No alert div found. Looking for flash or error messages:\n";
    if (preg_match_all('/(?:alert|error|danger|success)[^>]*>([^<]+)/i', $after, $am)) {
        print_r($am[1]);
    }
}
