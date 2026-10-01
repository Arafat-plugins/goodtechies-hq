package com.goodtechies.erp;

import android.content.Intent;
import android.net.Uri;
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
    static final long HANDOVER_TIMEOUT_MS = 8000;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable handoverWatchdog = () ->
            openInWebView(new IllegalStateException("Chrome did not open within " + HANDOVER_TIMEOUT_MS + " ms"));
    private boolean fellBack;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        try {
            super.onCreate(savedInstanceState);
        } catch (RuntimeException e) {
            openInWebView(e);
            return;
        }
        if (!isFinishing()) {
            handler.postDelayed(handoverWatchdog, HANDOVER_TIMEOUT_MS);
        }
    }

    @Override
    protected void launchTwa() {
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
