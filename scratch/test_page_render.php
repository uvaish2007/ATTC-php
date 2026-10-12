<?php
/**
 * Test rendering of present-executive-meeting.php and executive-meeting-report.php
 * for all 6 roles without needing a web server.
 */

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';

$rolesToTest = [
    'Admin' => ['id' => 1, 'username' => 'admin', 'role' => 'Admin', 'department' => 'Administration'],
    'Dean' => ['id' => 2, 'username' => 'dean', 'role' => 'Dean', 'department' => 'Administration'],
    'Principal' => ['id' => 3, 'username' => 'principal', 'role' => 'Principal', 'department' => 'Administration'],
    'HoD' => ['id' => 4, 'username' => 'hod_cse', 'role' => 'HoD', 'department' => 'CSE'],
    'Coordinator' => ['id' => 5, 'username' => 'coord_mech', 'role' => 'Coordinator', 'department' => 'Mechanical Engineering'],
    'Faculty' => ['id' => 6, 'username' => 'faculty_ece', 'role' => 'Faculty', 'department' => 'ECE'],
];

echo "=========================================================\n";
echo "PAGE RENDER HTML & SCRIPT VERIFICATION\n";
echo "=========================================================\n";

foreach ($rolesToTest as $roleName => $mockUser) {
    echo "--- Testing HTML Render for {$roleName} ---\n";
    
    // Simulate user session
    $_SESSION['user'] = $mockUser;
    $_SESSION['user_id'] = $mockUser['id'];
    $_SESSION['role'] = $mockUser['role'];
    $_SESSION['department'] = $mockUser['department'];
    $_GET = [
        'ay' => '2024-2025',
        'department' => ($roleName === 'HoD' || $roleName === 'Coordinator') ? $mockUser['department'] : 'CSE',
        'view' => 'slides'
    ];

    ob_start();
    try {
        include __DIR__ . '/../php-app/present-executive-meeting.php';
        $html = ob_get_clean();
        
        $len = strlen($html);
        echo "  [PASS] present-executive-meeting.php rendered ({$len} bytes)\n";
        
        // Assert key requirements in HTML/JS:
        // 1. JSON payload exists
        if (strpos($html, 'const slides =') !== false && strpos($html, 'const metrics56 =') !== false) {
            echo "  [PASS] slides & metrics56 JS payloads embedded\n";
        } else {
            echo "  [FAIL] Missing JS payloads\n";
        }

        // 2. Analytical mode toggle button exists
        if (strpos($html, 'Analytical Mode (56)') !== false) {
            echo "  [PASS] Analytical Mode switch button present\n";
        } else {
            echo "  [FAIL] Analytical Mode switch button missing\n";
        }

        // 3. Validation button present
        if (strpos($html, 'openValidationModal') !== false) {
            echo "  [PASS] 10-Point Audit Modal trigger present\n";
        } else {
            echo "  [FAIL] 10-Point Audit Modal trigger missing\n";
        }

        // 4. Slide 2 check for Admin/Dean/Principal
        if (in_array($roleName, ['Admin', 'Dean', 'Principal'])) {
            if (strpos($html, 'summary_top3') !== false) {
                echo "  [PASS] summary_top3 slide handler embedded for {$roleName}\n";
            } else {
                echo "  [FAIL] summary_top3 slide handler missing for {$roleName}\n";
            }
        }

        // 5. Faculty student restriction check
        if ($roleName === 'Faculty') {
            // Check that no student achievement dataset exists in JS
            if (strpos($html, 'studentAchievements') !== false) {
                // If it exists, verify it is empty
                preg_match('/studentAchievements\s*:\s*\[(.*?)\]/s', $html, $m);
                if (!empty($m) && trim($m[1]) === '') {
                    echo "  [PASS] studentAchievements array is empty in Faculty JS payload\n";
                }
            } else {
                echo "  [PASS] No studentAchievements exposed to Faculty\n";
            }
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        echo "  [ERROR] Render failed: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo "=========================================================\n";
echo "RENDER TESTS FINISHED\n";
echo "=========================================================\n";
