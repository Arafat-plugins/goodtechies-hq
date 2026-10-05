package com.goodtechies.erp;

import android.content.Context;
import android.util.Log;
import android.webkit.CookieManager;

import com.google.firebase.FirebaseApp;
import com.google.firebase.messaging.FirebaseMessaging;

/**
 * The app's own notifications (1.0.6): its Firebase token, handed to goodERP.
 *
 * Web Push only reaches the app while it runs through Chrome. In the app's own WebView (used when
 * Chrome cannot open it) there is no Web Push at all, so nothing reached the notification shade.
 * The app now has a Firebase token of its own, and the server sends to it.
 *
 * The token reaches the server as the {@code __Host-gerp_fcm} cookie, written into the app's
 * WebView cookie store for goodERP's own host. No web page can set that cookie for somebody else
 * (the {@code __Host-} prefix shuts out sibling sites too), so the server can file the token under
 * whoever is signed in on THIS phone ({@code RememberAppPushToken}).
 */
final class AppPush {
    private static final String TAG = "goodERP";
    static final String COOKIE = "__Host-gerp_fcm";

    private AppPush() {}

    /** Firebase is set up in this build (`app/google-services.json` was present). */
    static boolean isAvailable(Context context) {
        try {
            return !FirebaseApp.getApps(context).isEmpty() || FirebaseApp.initializeApp(context) != null;
        } catch (RuntimeException e) {
            return false;
        }
    }

    /** At every app start: ask Firebase for this install's token and hand it over. */
    static void start(Context context) {
        if (!isAvailable(context)) {
            return;
        }
        try {
            FirebaseMessaging.getInstance().getToken()
                    .addOnSuccessListener(AppPush::rememberToken)
                    .addOnFailureListener(e -> Log.w(TAG, "No Firebase token yet", e));
        } catch (RuntimeException e) {
            Log.w(TAG, "Firebase is not available", e);
        }
    }

    /** Writes the token where the server will find it on the next page the app loads. */
    static void rememberToken(String token) {
        if (token == null || token.isEmpty()) {
            return;
        }
        try {
            CookieManager cookies = CookieManager.getInstance();
            cookies.setCookie(LauncherActivity.SITE_URL, cookieFor(token));
            cookies.flush();
        } catch (RuntimeException e) {
            // No WebView on this phone (or it is updating); the next start tries again.
            Log.w(TAG, "Could not store the notification token", e);
        }
    }

    /** One year, this host only (no Domain, as `__Host-` requires), HTTPS only, hidden from scripts. */
    static String cookieFor(String token) {
        return COOKIE + "=" + token + "; Max-Age=31536000; Path=/; Secure; HttpOnly; SameSite=Lax";
    }
}
