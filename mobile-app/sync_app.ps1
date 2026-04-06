# Horizon Systems APK Sync Script
# This script builds the Android app and updates the Portal download link.

$androidPath = "C:\Users\Julienne Flores\AndroidStudioProjects\HorizonSystems"
$webRoot = "C:\Users\Julienne Flores\Downloads\Horizon Systems\horizon"
$apkOutput = "$androidPath\app\build\outputs\apk\debug\app-debug.apk"
$webApk = "$webRoot\horizon_app.apk"

# Check if Gradle wrapper exists
if (!(Test-Path "$androidPath\gradlew.bat")) {
    Write-Host "Error: gradlew.bat not found in $androidPath" -ForegroundColor Red
    exit 1
}

Write-Host "--- [1/3] Starting Android Build (Debug) ---" -ForegroundColor Cyan
Set-Location $androidPath
.\gradlew.bat assembleDebug

if ($LASTEXITCODE -ne 0) {
    Write-Host "Error: Android build failed!" -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host "--- [2/2] Finalizing ---" -ForegroundColor Cyan
if (Test-Path $apkOutput) {
    Write-Host "Success: Android build completed successfully at $apkOutput." -ForegroundColor Green
    Write-Host "The portal (download_app.php) is now serving this latest build directly." -ForegroundColor Green
} else {
    Write-Host "Error: Output APK not found at $apkOutput" -ForegroundColor Red
    exit 1
}

Write-Host "Done." -ForegroundColor Gray

Set-Location $webRoot
