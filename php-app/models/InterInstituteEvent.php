<?php
/**
 * InterInstituteEvent Model & Shared Aggregation Service
 * ATTS-INTER-INSTITUTE-EVENTS-ENHANCEMENT
 *
 * Provides synchronized participation & achievement counting:
 * - Winners and runners automatically count toward participation totals
 * - Separate achievement breakdown (1st Place, 2nd Runner, 3rd Runner, Total Awards)
 * - Event levels including District, State (Within/Outside), National, International
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

function inter_institute_event_levels(): array
{
    return [
        'District'              => 'District',
        'State — Within State'  => 'State — Within State',
        'State — Outside State' => 'State — Outside State',
        'National'              => 'National',
        'International'         => 'International',
    ];
}

function inter_institute_participation_types(): array
{
    return [
        'Participant'          => 'Participant',
        'Winner / First Place' => 'Winner / First Place',
        'Second Runner'        => 'Second Runner',
        'Third Runner'         => 'Third Runner',
        'Presenter'            => 'Presenter',
    ];
}

/**
 * Checks if a participation type or position represents an award/winner outcome.
 */
function inter_institute_is_award(?string $pType, ?string $achType = null, ?string $positionAward = null): bool
{
    $p = trim((string)$pType);
    if (in_array($p, ['Winner / First Place', 'Winner', 'First Place', '1st Place', 'Second Runner', '2nd Runner', 'Runner-up', 'Second Place', 'Third Runner', '3rd Runner', 'Third Place'], true)) {
        return true;
    }
    if (in_array(trim((string)$achType), ['Medal', 'Award / Prize Winner', 'Award', 'Medal / Award'], true)) {
        return true;
    }
    if (!empty($positionAward) && !in_array(strtolower(trim($positionAward)), ['participant', 'participated', 'presented', 'nil', 'none'], true)) {
        return true;
    }
    return false;
}

/**
 * Synchronized statistics calculation across all unified inter-institute event records.
 *
 * @param string|null $dept
 * @param string|null $academicYear
 * @param array|null $window ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']
 * @param string|null $status Null for all non-rejected, or specific status like 'Approved'
 * @return array
 */
function inter_institute_event_stats(?string $dept = null, ?string $academicYear = null, ?array $window = null, ?string $status = 'Approved'): array
{
    $pdo = db();

    $where = [];
    $params = [];

    if ($status !== null) {
        if ($status === 'non_rejected') {
            $where[] = "status != 'Rejected'";
        } else {
            $where[] = "status = ?";
            $params[] = $status;
        }
    }

    if (!empty($dept)) {
        $where[] = "department = ?";
        $params[] = $dept;
    }

    if (!empty($academicYear)) {
        $where[] = "academic_year = ?";
        $params[] = $academicYear;
    }

    if (!empty($window['from'])) {
        $where[] = "created_at >= ?";
        $params[] = $window['from'] . ' 00:00:00';
    }
    if (!empty($window['to'])) {
        $where[] = "created_at <= ?";
        $params[] = $window['to'] . ' 23:59:59';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT * FROM `inst_inter_inst_within` {$whereSql} ORDER BY id ASC");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Counting accumulators
    $totalParticipations = count($records);
    $firstPlaceWinners   = 0;
    $secondRunners       = 0;
    $thirdRunners        = 0;
    $ordinaryParticipants= 0;
    $presenters          = 0;
    $totalAwards         = 0;

    $byLevel = [
        'District'              => 0,
        'State — Within State'  => 0,
        'State — Outside State' => 0,
        'National'              => 0,
        'International'         => 0,
        'Other'                 => 0,
    ];

    $awardsByLevel = [
        'District'              => 0,
        'State — Within State'  => 0,
        'State — Outside State' => 0,
        'National'              => 0,
        'International'         => 0,
        'Other'                 => 0,
    ];

    foreach ($records as $r) {
        $pt = trim((string)($r['participation_type'] ?? ''));
        $lvl = trim((string)($r['event_level'] ?? ''));

        // Level categorization
        if ($lvl === 'District') {
            $byLevel['District']++;
        } elseif ($lvl === 'State — Within State' || $lvl === 'Within State' || $lvl === 'State') {
            $byLevel['State — Within State']++;
        } elseif ($lvl === 'State — Outside State' || $lvl === 'Outside State') {
            $byLevel['State — Outside State']++;
        } elseif ($lvl === 'National') {
            $byLevel['National']++;
        } elseif ($lvl === 'International') {
            $byLevel['International']++;
        } else {
            $byLevel['Other']++;
        }

        // Award & participation type categorization
        $isAwardRecord = false;
        if (in_array($pt, ['Winner / First Place', 'Winner', 'First Place', '1st Place'], true)) {
            $firstPlaceWinners++;
            $isAwardRecord = true;
        } elseif (in_array($pt, ['Second Runner', '2nd Runner', 'Runner-up', 'Second Place'], true)) {
            $secondRunners++;
            $isAwardRecord = true;
        } elseif (in_array($pt, ['Third Runner', '3rd Runner', 'Third Place'], true)) {
            $thirdRunners++;
            $isAwardRecord = true;
        } elseif ($pt === 'Presenter') {
            $presenters++;
        } else {
            $ordinaryParticipants++;
        }

        if (!$isAwardRecord && inter_institute_is_award($pt, $r['achievement_type'] ?? null, $r['position_award'] ?? ($r['award_medal'] ?? null))) {
            $isAwardRecord = true;
        }

        if ($isAwardRecord) {
            $totalAwards++;
            if ($lvl === 'District') {
                $awardsByLevel['District']++;
            } elseif ($lvl === 'State — Within State' || $lvl === 'Within State' || $lvl === 'State') {
                $awardsByLevel['State — Within State']++;
            } elseif ($lvl === 'State — Outside State' || $lvl === 'Outside State') {
                $awardsByLevel['State — Outside State']++;
            } elseif ($lvl === 'National') {
                $awardsByLevel['National']++;
            } elseif ($lvl === 'International') {
                $awardsByLevel['International']++;
            } else {
                $awardsByLevel['Other']++;
            }
        }
    }

    return [
        'total_participations'      => $totalParticipations,
        'ordinary_participants'     => $ordinaryParticipants,
        'first_place_winners'       => $firstPlaceWinners,
        'second_runners'            => $secondRunners,
        'third_runners'             => $thirdRunners,
        'presenters'                => $presenters,
        'total_award_recipients'    => $totalAwards,
        'district_participations'   => $byLevel['District'],
        'state_within_participations'=> $byLevel['State — Within State'],
        'state_outside_participations'=> $byLevel['State — Outside State'],
        'state_total_participations'=> $byLevel['State — Within State'] + $byLevel['State — Outside State'],
        'national_participations'   => $byLevel['National'],
        'international_participations' => $byLevel['International'],
        'level_breakdown'           => $byLevel,
        'awards_by_level'           => $awardsByLevel,
        'records'                   => $records,
    ];
}
