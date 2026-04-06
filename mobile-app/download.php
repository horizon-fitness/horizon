<?php
/**
 * Horizon Systems - Direct APK Download Proxy
 * Serves the latest APK directly from the Android Studio build directory.
 */

$apk_path = 'C:\Users\Julienne Flores\AndroidStudioProjects\HorizonSystems\app\build\outputs\apk\debug\app-debug.apk';

if (!file_exists($apk_path)) {
    header("HTTP/1.1 404 Not Found");
    die("Error: The latest APK build was not found in the Android Studio project folder. Please rebuild your app first.");
}

// Clear any previous output buffers
if (ob_get_level()) {
    ob_end_clean();
}

// Set Headers for APK download
header('Content-Description: File Transfer');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="HorizonSystems.apk"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($apk_path));

// Stream the file
readfile($apk_path);
exit;
