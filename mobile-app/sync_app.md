---
description: How to automatically build and deploy the Android APK to the Portal
---

# Syncing the Mobile App (One-Click)

This workflow automates the process of building the Android APK from Android Studio and copying it to the local web server. This ensures the QR code on the Portal always points to the latest version of your code.

## Prerequisites
- **PowerShell** must be available on your system.
- **Android Studio** (or Gradle) must be configured correctly.

## Steps

### 1. Run the Synchronization Script
Open a terminal in the `horizon` project folder and run the following command:

```powershell
.\sync_app.ps1
```

### 2. Monitor the Output
The script will perform the following actions:
1.  **Build:** It will run `gradlew assembleDebug` in the `HorizonSystems` project.
2.  **Copy:** It will automatically move the new APK to `horizon/horizon_app.apk`.
3.  **Finish:** It will notify you when the QR code on the portal is ready.

### 3. Verify on the Portal
Open `portal.php` in your browser. Click the **"Download App"** button to show the QR code. Scanning this QR code will now download the exact version you just built.

---

> [!TIP]
> You can also right-click the `sync_app.ps1` file in your File Explorer and select **"Run with PowerShell"** for an even faster update experience.
