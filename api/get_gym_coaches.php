<?php
header('Content-Type: application/json');
require_once '../db.php';

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$date_str = isset($_GET['date']) ? $_GET['date'] : '';
$day_name = !empty($date_str) ? date('l', strtotime($date_str)) : '';

if ($gym_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Gym ID']);
    exit;
}

try {
    // If a date is provided, filter out coaches who are 'Off' on that day of the week
    $query = "
        SELECT 
            c.coach_id, 
            u.first_name, 
            u.last_name, 
            u.profile_picture as image_url,
            ca.coach_type as specialization,
            COALESCE(c.session_rate, 0) as session_rates
        FROM coaches c
        JOIN users u ON c.user_id = u.user_id
        LEFT JOIN coach_applications ca ON c.coach_application_id = ca.coach_application_id
    ";
    
    if (!empty($day_name)) {
        $query .= " LEFT JOIN coach_schedules cs ON c.coach_id = cs.coach_id AND cs.day_of_week = :day_name ";
    }
    
    $query .= " WHERE c.gym_id = :gym_id AND c.status = 'Active' ";
    
    if (!empty($day_name)) {
        $query .= " AND (cs.availability_status IS NULL OR cs.availability_status != 'Off') ";
    }
    
    $stmt = $pdo->prepare($query);
    $params = [':gym_id' => $gym_id];
    if (!empty($day_name)) $params[':day_name'] = $day_name;
    
    $stmt->execute($params);
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!empty($coaches)) {
        $coach_ids = array_column($coaches, 'coach_id');
        $in = str_repeat('?,', count($coach_ids) - 1) . '?';
        $stmtSched = $pdo->prepare("SELECT coach_id, day_of_week, availability_status, morning_start, morning_end, afternoon_start, afternoon_end FROM coach_schedules WHERE coach_id IN ($in)");
        $stmtSched->execute($coach_ids);
        $schedules = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

        $coach_sched_map = [];
        foreach ($schedules as $s) {
            if ($s['availability_status'] !== 'Off') {
                $coach_sched_map[$s['coach_id']][] = $s;
            }
        }
        
        $dayMap = ['Monday'=>'Mon', 'Tuesday'=>'Tue', 'Wednesday'=>'Wed', 'Thursday'=>'Thu', 'Friday'=>'Fri', 'Saturday'=>'Sat', 'Sunday'=>'Sun'];
        $dayOrder = ['Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6, 'Sunday'=>7];

        foreach ($coaches as &$c) {
            $cid = $c['coach_id'];
            if (isset($coach_sched_map[$cid])) {
                $sc = $coach_sched_map[$cid];
                usort($sc, fn($a, $b) => $dayOrder[$a['day_of_week']] <=> $dayOrder[$b['day_of_week']]);
                
                $daysAbbr = array_map(fn($d) => $dayMap[$d['day_of_week']] ?? substr($d['day_of_week'], 0, 3), $sc);
                
                $daysStr = implode(', ', $daysAbbr);
                if (count($daysAbbr) == 6 && $daysAbbr[0] == 'Mon' && $daysAbbr[5] == 'Sat') {
                    $daysStr = 'Mon - Sat';
                } elseif (count($daysAbbr) == 5 && $daysAbbr[0] == 'Mon' && $daysAbbr[4] == 'Fri') {
                    $daysStr = 'Mon - Fri';
                } elseif (count($daysAbbr) == 7) {
                    $daysStr = 'Mon - Sun';
                }

                $c['weekly_schedule'] = $daysStr;
                
                $first = $sc[0];
                $start = $first['morning_start'] ? date('h:i A', strtotime($first['morning_start'])) : '';
                $end = $first['afternoon_end'] ? date('h:i A', strtotime($first['afternoon_end'])) : '';
                if (!$end && $first['morning_end']) $end = date('h:i A', strtotime($first['morning_end']));
                if (!$start && $first['afternoon_start']) $start = date('h:i A', strtotime($first['afternoon_start']));
                
                if ($start && $end) {
                    $c['availability_hours'] = "$start - $end";
                } else {
                    $c['availability_hours'] = "Flexible";
                }
            } else {
                $c['weekly_schedule'] = "Off-Duty";
                $c['availability_hours'] = "Unavailable";
            }
        }
    }

    echo json_encode([
        'success' => true,
        'coaches' => $coaches
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
