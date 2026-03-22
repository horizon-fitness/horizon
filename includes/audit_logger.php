<?php
/**
 * Log an audit event to the database.
 * 
 * @param PDO $pdo The database connection
 * @param int|null $user_id The user ID performing the action
 * @param int|null $gym_id The gym (tenant) ID associated with the action
 * @param string $action_type The type of action (e.g., 'Login', 'Logout', 'Create', 'Update', 'Delete', 'Tenant')
 * @param string $table_name The table being affected
 * @param int $record_id The ID of the record being affected
 * @param array $old_values Prior state of the record
 * @param array $new_values New state of the record
 * @return bool Success or failure
 */
function log_audit_event($pdo, $user_id, $gym_id, $action_type, $table_name, $record_id, $old_values = [], $new_values = []) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, gym_id, action_type, table_name, record_id, old_values, new_values, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([
            $user_id, 
            $gym_id, 
            $action_type, 
            $table_name, 
            $record_id, 
            json_encode($old_values), 
            json_encode($new_values)
        ]);
    } catch (PDOException $e) {
        error_log("Audit Log Error: " . $e->getMessage());
        return false;
    }
}
?>
