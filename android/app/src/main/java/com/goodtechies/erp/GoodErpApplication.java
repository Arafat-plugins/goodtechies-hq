package com.goodtechies.erp;

import android.app.Activity;
import android.app.Application;
import android.os.Bundle;
import android.webkit.CookieManager;

/**
 * Keeps the person signed in after the app is closed.
 *
 * When the site runs in the app's own WebView (no Chrome to hand over to), its cookies — the
 * login among them — live in WebView's cookie store, which writes to disk only now and then. An
 * app swiped away before that write loses the login, and the next start asks for it again. So
 * every time any screen of the app goes to the background, the cookies are written out at once.
 */
public class GoodErpApplication extends Application {

    @Override
    public void onCreate() {
        super.onCreate();
        registerActivityLifecycleCallbacks(new ActivityLifecycleCallbacks() {
            @Override public void onActivityPaused(Activity activity) { flushCookies(); }
            @Override public void onActivityStopped(Activity activity) { flushCookies(); }
            @Override public void onActivityCreated(Activity activity, Bundle state) { }
            @Override public void onActivityStarted(Activity activity) { }
            @Override public void onActivityResumed(Activity activity) { }
            @Override public void onActivitySaveInstanceState(Activity activity, Bundle state) { }
            @Override public void onActivityDestroyed(Activity activity) { }
        });
    }

    static void flushCookies() {
        try {
            CookieManager.getInstance().flush();
        } catch (RuntimeException e) {
            // No WebView on this phone (or it is updating): nothing was stored there to lose.
        }
    }
}
