package com.goodtechies.erp;

import android.Manifest;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.util.Log;
import android.widget.Toast;

import com.google.androidbrowserhelper.trusted.LauncherActivityMetadata;
import com.google.androidbrowserhelper.trusted.WebViewFallbackActivity;

/**
 * The app's entry point: androidbrowserhelper's LauncherActivity (opens the live site in Chrome
 * as a Trusted Web Activity, full screen) plus two safety nets, so a tap on the icon always ends
 * with the site on screen:
 *
 * 1. If the Chrome launch throws, the site opens in the app's own WebView.
 * 2. If Chrome has not taken over the screen within HANDOVER_TIMEOUT_MS (a phone that blocks or
 *    silently drops the hand-over), the site opens in the app's own WebView.
 */
public class LauncherActivity extends com.google.androidbrowserhelper.trusted.LauncherActivity {
    private static final String TAG = "goodERP";

    /** Keep in step with DEFAULT_URL in AndroidManifest.xml. */
    static final String SITE_URL = "https://erp.goodtechies.com/";

    /** Long enough for Chrome's cold start on a slow phone, short enough not to feel broken. */
    static final long HANDOVER_TIMEOUT_MS = 12000;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable handoverWatchdog = () ->
            openInWebView(new IllegalStateException("Chrome did not open within " + HANDOVER_TIMEOUT_MS + " ms"));
    private boolean fellBack;

    /** 1.0.6: the one-time "Allow goodERP to send you notifications?" question (Android 13+). */
    static final int NOTIFICATION_PERMISSION_REQUEST = 7301;
    private static final String PREFS = "goodERP";
    private static final String ASKED_FOR_NOTIFICATIONS = "asked_for_notifications";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        try {
            super.onCreate(savedInstanceState);
        } catch (RuntimeException e) {
            openInWebView(e);
        }
    }

    /**
     * On the first start on Android 13+, ask for notifications before opening the site: the app's
     * own notifications (Firebase) need it in Chrome mode and in WebView mode alike. Asked once;
     * the site opens straight after the answer, whatever it is.
     */
    @Override
    protected boolean shouldLaunchImmediately() {
        if (!needsToAskForNotifications()) {
            return true;
        }
        prefs().edit().putBoolean(ASKED_FOR_NOTIFICATIONS, true).apply();
        requestPermissions(new String[] {Manifest.permission.POST_NOTIFICATIONS}, NOTIFICATION_PERMISSION_REQUEST);
        return false;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == NOTIFICATION_PERMISSION_REQUEST && !isFinishing()) {
            launchTwa();
        }
    }

    boolean needsToAskForNotifications() {
        return Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
                && !prefs().getBoolean(ASKED_FOR_NOTIFICATIONS, false);
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(PREFS, MODE_PRIVATE);
    }

    @Override
    protected void launchTwa() {
        if (isFinishing()) {
            return;
        }
        // The hand-over clock starts when the hand-over does (not while the question is open).
        handler.removeCallbacks(handoverWatchdog);
        handler.postDelayed(handoverWatchdog, HANDOVER_TIMEOUT_MS);
        try {
            super.launchTwa();
        } catch (RuntimeException e) {
            openInWebView(e);
        }
    }

    /** Chrome (or the WebView) now covers this screen: the hand-over worked. */
    @Override
    protected void onStop() {
        handler.removeCallbacks(handoverWatchdog);
        super.onStop();
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacks(handoverWatchdog);
        super.onDestroy();
    }

    private void openInWebView(RuntimeException cause) {
        handler.removeCallbacks(handoverWatchdog);
        if (fellBack) {
            return;
        }
        fellBack = true;
        Log.e(TAG, "Trusted Web Activity did not open; opening the WebView fallback", cause);
        try {
            Intent intent = WebViewFallbackActivity.createLaunchIntent(
                    this, Uri.parse(SITE_URL), LauncherActivityMetadata.parse(this));
            startActivity(intent);
        } catch (RuntimeException e) {
            Log.e(TAG, "WebView fallback failed too", e);
            Toast.makeText(this, "goodERP could not start: " + e.getMessage(), Toast.LENGTH_LONG).show();
        }
        finish();
    }
}
