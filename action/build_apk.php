<?php
session_start();
// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

require_once '../db.php';
header('Content-Type: application/json');

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'tenant' && $role !== 'admin' && $role !== 'staff')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or Session Expired.']);
    exit;
}

$gym_id = $_SESSION['gym_id'];
set_time_limit(600);
ignore_user_abort(true);

$android_project_path = 'c:\\Users\\Julienne Flores\\AndroidStudioProjects\\HorizonSystems';
$web_project_path = dirname(__DIR__); 
$original_filename = 'app-debug.apk';
$apk_dest = $web_project_path . DIRECTORY_SEPARATOR . $original_filename;

try {
    if (!is_dir($android_project_path)) {
        throw new Exception("Android project not found at: " . $android_project_path);
    }

    // Command to build the APK - keeping it simple
    $cmd = "cmd /c \"cd /d \"$android_project_path\" && .\\gradlew.bat assembleDebug\"";

    $output = [];
    $return_var = 0;
    exec($cmd . " 2>&1", $output, $return_var);

    if ($return_var === 0) {
        $apk_source = $android_project_path . '\\app\\build\\outputs\\apk\\debug\\' . $original_filename;
        
        if (file_exists($apk_source)) {
            if (copy($apk_source, $apk_dest)) {
                // Update database to the actual original filename
                $stmtUpdate = $pdo->prepare("UPDATE tenant_pages SET app_download_link = ? WHERE gym_id = ?");
                $stmtUpdate->execute([$original_filename, $gym_id]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'APK Built successfully! You can now download "' . $original_filename . '"',
                    'apk_url' => $original_filename
                ]);
            } else {
                throw new Exception("Failed to copy APK to web root.");
            }
        } else {
            throw new Exception("APK file not found after build.");
        }
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Build failed. See logs for details.',
            'last_line' => end($output),
            'full_output' => $output
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
