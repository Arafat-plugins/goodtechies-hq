package com.goodtechies.erp;

import android.app.Activity;
import android.app.Application;
import android.os.Bundle;
import android.webkit.CookieManager;

/**
 * Keeps the person signed in after the app is closed, and starts the app's own notifications.
 *
 * When the site runs in the app's own WebView (no Chrome to hand over to), its cookies — the
 * login among them — live in WebView's cookie store, which writes to disk only now and then. An
 * app swiped away before that write loses the login, and the next start asks for it again. So
 * every time any screen of the app goes to the background, the cookies are written out at once.
 *
 * 1.0.6: at every start the app's Firebase token is handed to goodERP ({@link AppPush}), and the
 * app counts its visible screens so a push that arrives while the person is looking at goodERP
 * in the app is not also put in the notification shade.
 */
public class GoodErpApplication extends Application {

    private static int startedScreens;

    @Override
    public void onCreate() {
        super.onCreate();
        registerActivityLifecycleCallbacks(new ActivityLifecycleCallbacks() {
            @Override public void onActivityPaused(Activity activity) { flushCookies(); }
            @Override public void onActivityStopped(Activity activity) {
                startedScreens = Math.max(0, startedScreens - 1);
                flushCookies();
            }
            @Override public void onActivityCreated(Activity activity, Bundle state) { }
            @Override public void onActivityStarted(Activity activity) { startedScreens++; }
            @Override public void onActivityResumed(Activity activity) { }
            @Override public void onActivitySaveInstanceState(Activity activity, Bundle state) { }
            @Override public void onActivityDestroyed(Activity activity) { }
        });
        AppPush.start(this);
    }

    /** A goodERP screen of this app (its WebView, not Chrome) is on screen now. */
    static boolean isInForeground() {
        return startedScreens > 0;
    }

    static void flushCookies() {
        try {
            CookieManager.getInstance().flush();
        } catch (RuntimeException e) {
            // No WebView on this phone (or it is updating): nothing was stored there to lose.
        }
    }
}
