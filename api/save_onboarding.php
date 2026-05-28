<?php
header('Content-Type: application/json; charset=UTF-8');
ob_start();

try {
    require_once '../db.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    
    if ($user_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Valid User ID required.']);
        exit;
    }
    
    $experience_level = trim($input['experience_level'] ?? '');
    $weekly_commitment = trim($input['weekly_commitment'] ?? '');
    $target_weight = isset($input['target_weight']) && is_numeric($input['target_weight']) ? (float)$input['target_weight'] : null;
    $equipment_availability = trim($input['equipment_availability'] ?? '');
    $injuries_limitations = trim($input['injuries_limitations'] ?? '');
    $current_weight = isset($input['current_weight']) && is_numeric($input['current_weight']) ? (float)$input['current_weight'] : null;
    $height_cm = isset($input['height_cm']) && is_numeric($input['height_cm']) ? (float)$input['height_cm'] : null;
    $target_muscles_str = trim($input['target_muscles'] ?? '');
    $fitness_goal = trim($input['fitness_goal'] ?? ''); // Wait, fitness_goal is not in user_fitness_profiles in register.php but let's save it if it exists. Actually register.php didn't save fitness_goal? Wait. Let's check user_fitness_profiles columns.
    
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();

    // 1. Find Member ID
    $stmtGetMember = $pdo->prepare("SELECT member_id FROM members WHERE user_id = ? LIMIT 1");
    $stmtGetMember->execute([$user_id]);
    $member_id = $stmtGetMember->fetchColumn();

    // 2. Save Fitness Profile
    $stmtFitnessCheck = $pdo->prepare("SELECT user_id FROM user_fitness_profiles WHERE user_id = ?");
    $stmtFitnessCheck->execute([$user_id]);
    if ($stmtFitnessCheck->fetch()) {
        $stmtUpdFit = $pdo->prepare("UPDATE user_fitness_profiles SET experience_level = ?, weekly_commitment = ?, target_weight = ?, equipment_availability = ?, injuries_limitations = ?, updated_at = ? WHERE user_id = ?");
        $stmtUpdFit->execute([$experience_level, $weekly_commitment, $target_weight, $equipment_availability, $injuries_limitations, $now, $user_id]);
    } else {
        $stmtInsFit = $pdo->prepare("INSERT INTO user_fitness_profiles (user_id, experience_level, weekly_commitment, target_weight, equipment_availability, injuries_limitations, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtInsFit->execute([$user_id, $experience_level, $weekly_commitment, $target_weight, $equipment_availability, $injuries_limitations, $now, $now]);
    }

    // 3. Save Target Muscles
    if (!empty($target_muscles_str)) {
        // Clear old ones just in case
        $pdo->prepare("DELETE FROM user_target_muscles WHERE user_id = ?")->execute([$user_id]);

        $muscle_array = explode(',', $target_muscles_str);
        $stmtGetMuscle = $pdo->prepare("SELECT target_muscle_id FROM target_muscles WHERE muscle_name = ? LIMIT 1");
        $stmtInsertTarget = $pdo->prepare("INSERT INTO user_target_muscles (user_id, target_muscle_id, priority_level, created_at) VALUES (?, ?, 'Primary', ?)");
        foreach ($muscle_array as $m_name) {
            $m_name = trim($m_name);
            if (!empty($m_name)) {
                $stmtGetMuscle->execute([$m_name]);
                if ($m_row = $stmtGetMuscle->fetch()) {
                    $stmtInsertTarget->execute([$user_id, $m_row['target_muscle_id'], $now]);
                }
            }
        }
    }

    // 4. Save Initial Health Metrics
    if ($member_id && $current_weight !== null && $height_cm !== null) {
        $stmtHealth = $pdo->prepare("INSERT INTO member_health_metrics (member_id, weight_kg, height_cm, recorded_at) VALUES (?, ?, ?, ?)");
        $stmtHealth->execute([$member_id, $current_weight, $height_cm, $now]);
    }

    $pdo->commit();

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Onboarding completed successfully.']);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
