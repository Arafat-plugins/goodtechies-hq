# goodERP Android app

The Android app is a **Trusted Web Activity (TWA)**: a small signed APK that opens
https://erp.goodtechies.com full-screen in Chrome's engine, with no address bar.

It contains **no copy of the app**. Every screen, colour, layout, permission and feature comes live
from the Laravel app on the VPS, so:

> **Change the Laravel app → deploy (`bash deploy/deploy.sh` on the VPS) → the Android app shows
> it the next time it opens.** No rebuild, no new APK, nothing to install.

## How it fits together

| Piece | Where | What it does |
| --- | --- | --- |
| APK shell | `android/` | Launcher icon, splash, status-bar colours, opens the site |
| Web app manifest | `public/manifest.json` | Name, icons, colours, `display: standalone` |
| Service worker | `public/sw.js` | Shows `public/offline.html` when there is no internet. Caches no app code, so deploys are never stale |
| Digital Asset Links | `public/.well-known/assetlinks.json` | Proves the APK belongs to this site. **Without it the app shows a browser address bar** |
| Guard test | `tests/Unit/PwaShellTest.php` | Fails if any of the files above disappears |

`assetlinks.json` holds the SHA-256 fingerprint of the signing key in `android/keystore/`. They must
match. If you ever change the key, update the fingerprint and deploy.

## When you DO need a new APK

Only when the shell itself changes:

- the launcher icon or the splash image
- the app name on the home screen
- the status-bar / navigation-bar / splash colours (`app/src/main/res/values/colors.xml`)
- the domain (`app/src/main/res/values/strings.xml` and the `<data android:host>` in
  `AndroidManifest.xml`)

Before building, bump **both** `versionCode` (+1) and `versionName` in `app/build.gradle.kts`, or
Android refuses to install it over the old one.

## Building

Needs JDK 17+ and the Android SDK (platform 36). Easiest on Windows: open the `android/` folder in
Android Studio, then **Build → Generate Signed App Bundle / APK** — or from a terminal:

```
cd android
gradlew.bat assembleRelease
```

The APK is written to `android/app/build/outputs/apk/release/app-release.apk`. You can also ask
Claude to rebuild it.

## The signing key — keep it safe

`android/keystore/` (`goodERP-release.jks` + `keystore.properties` with its password) is
**gitignored and must never be pushed** — the GitHub repo is public. Back the folder up somewhere
private (a password manager or an encrypted drive). If it is lost, a new APK cannot update the
installed app: every phone has to uninstall and install fresh, and `assetlinks.json` must get the
new fingerprint.

## Installing on a phone

Copy the APK to the phone and open it. Android asks once to allow installs from that source
(Files / Chrome / WhatsApp, whichever opened it). Chrome must be installed and up to date.

## If the address bar shows at the top

The site and the APK are not verified as belonging together. Check, in order:

1. https://erp.goodtechies.com/.well-known/assetlinks.json opens and shows the JSON (deployed?).
2. Its fingerprint matches `apksigner verify --print-certs app-release.apk`.
3. Clear Chrome's data for the app or reinstall the APK — Android caches the verification.
