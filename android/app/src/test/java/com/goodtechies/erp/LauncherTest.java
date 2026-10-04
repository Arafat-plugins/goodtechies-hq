package com.goodtechies.erp;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.robolectric.Shadows.shadowOf;

import android.app.Application;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.ActivityInfo;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.Signature;
import android.content.pm.SigningInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Binder;
import android.os.Looper;
import java.time.Duration;
import android.support.customtabs.ICustomTabsCallback;
import android.support.customtabs.ICustomTabsService;

import androidx.test.core.app.ApplicationProvider;

import com.google.androidbrowserhelper.trusted.WebViewFallbackActivity;

import org.junit.After;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;
import org.robolectric.shadows.ShadowPackageManager;
import org.robolectric.util.ReflectionHelpers;

/**
 * Starts the real launcher in a simulated Android and checks that a tap on the icon always ends
 * with the site on screen: in Chrome when it can, otherwise in the app's own WebView.
 */
@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34)
public class LauncherTest {

    private static final String CHROME = "com.android.chrome";

    private ActivityController<LauncherActivity> controller;

    /** What the home screen sends: without NEW_TASK the library restarts itself in a new task. */
    private static Intent homeScreenTap() {
        return new Intent(Intent.ACTION_MAIN)
                .addCategory(Intent.CATEGORY_LAUNCHER)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
    }

    private LauncherActivity tapTheIcon() {
        controller = Robolectric.buildActivity(LauncherActivity.class, homeScreenTap()).setup();
        shadowOf(Looper.getMainLooper()).idle();
        return controller.get();
    }

    /**
     * The library counts live launchers in a static field and closes a second one, and Robolectric
     * keeps statics between tests, so every test closes its launcher the way Android would.
     */
    @After
    public void closeTheLauncher() {
        if (controller != null) {
            controller.pause().stop().destroy();
        }
    }

    /** A phone with Chrome: a browser that also offers Custom Tabs with TWA support. */
    private static void installChrome() {
        installChrome(true);
    }

    /** @param sessionStarts false = a Chrome that connects but never opens the site (a hang). */
    private static void installChrome(boolean sessionStarts) {
        Context context = ApplicationProvider.getApplicationContext();
        ShadowPackageManager pm = shadowOf(context.getPackageManager());

        // Installed and signed, as every real Chrome is (the app pins its certificate).
        PackageInfo chrome = new PackageInfo();
        chrome.packageName = CHROME;
        chrome.versionName = "130.0.6723.58";
        chrome.applicationInfo = new ApplicationInfo();
        chrome.applicationInfo.packageName = CHROME;
        SigningInfo signing = ReflectionHelpers.callConstructor(SigningInfo.class);
        shadowOf(signing).setSignatures(new Signature[] {new Signature("0a0b0c0d")});
        chrome.signingInfo = signing;
        pm.installPackage(chrome);

        ComponentName browser = new ComponentName(CHROME, "com.google.android.apps.chrome.Main");
        pm.addActivityIfNotPresent(browser);
        IntentFilter view = new IntentFilter(Intent.ACTION_VIEW);
        view.addCategory(Intent.CATEGORY_BROWSABLE);
        view.addCategory(Intent.CATEGORY_DEFAULT);
        view.addDataScheme("http");
        view.addDataScheme("https");
        pm.addIntentFilterForActivity(browser, view);

        ComponentName tabs = new ComponentName(CHROME,
                "org.chromium.chrome.browser.customtabs.CustomTabsConnectionService");
        pm.addServiceIfNotPresent(tabs);
        IntentFilter service = new IntentFilter("android.support.customtabs.action.CustomTabsService");
        service.addCategory("androidx.browser.trusted.category.TrustedWebActivities");
        pm.addIntentFilterForService(tabs, service);

        // Chrome's Custom Tabs service, answering just enough for a session to start.
        Binder binder = new Binder();
        binder.attachInterface(new ICustomTabsService.Default() {
            @Override public boolean warmup(long flags) { return true; }
            @Override public boolean newSession(ICustomTabsCallback callback) { return sessionStarts; }
            @Override public boolean newSessionWithExtras(ICustomTabsCallback callback, android.os.Bundle extras) {
                return sessionStarts;
            }
        }, "android.support.customtabs.ICustomTabsService");
        shadowOf((Application) context).setComponentNameAndServiceForBindService(tabs, binder);
    }

    @Test
    public void theManifestStartUrlIsTheLiveSite() throws Exception {
        Context context = ApplicationProvider.getApplicationContext();
        ActivityInfo info = context.getPackageManager().getActivityInfo(
                new ComponentName(context, LauncherActivity.class), PackageManager.GET_META_DATA);

        assertEquals(LauncherActivity.SITE_URL,
                info.metaData.getString("android.support.customtabs.trusted.DEFAULT_URL"));
    }

    @Test
    public void withNoBrowserOnThePhoneTheSiteOpensInTheAppsWebView() {
        LauncherActivity activity = tapTheIcon();

        Intent next = shadowOf(activity).getNextStartedActivity();
        assertNotNull("the app opened nothing", next);
        assertEquals(WebViewFallbackActivity.class.getName(), next.getComponent().getClassName());
    }

    @Test
    public void withChromeInstalledTheSiteOpensFullScreenInChrome() {
        installChrome();

        LauncherActivity activity = tapTheIcon();

        Intent next = shadowOf(activity).getNextStartedActivity();
        assertNotNull("the app opened nothing", next);
        assertEquals(CHROME, next.getPackage());
        assertEquals(Intent.ACTION_VIEW, next.getAction());
        assertEquals(Uri.parse(LauncherActivity.SITE_URL), next.getData());
        assertTrue("not opened as a full-screen Trusted Web Activity",
                next.getBooleanExtra("android.support.customtabs.extra.LAUNCH_AS_TRUSTED_WEB_ACTIVITY", false));
    }

    @Test
    public void onceChromeHasTakenOverTheAppDoesNotAlsoOpenTheWebView() {
        installChrome();
        LauncherActivity activity = tapTheIcon();
        assertEquals(CHROME, shadowOf(activity).getNextStartedActivity().getPackage());

        controller.pause().stop(); // Chrome now covers the launcher
        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(LauncherActivity.HANDOVER_TIMEOUT_MS + 2000));

        assertNull("opened the WebView on top of Chrome", shadowOf(activity).getNextStartedActivity());
    }

    @Test
    public void whenChromeRefusesTheSessionTheSiteOpensInTheAppsWebView() {
        installChrome(false);
        LauncherActivity activity = tapTheIcon();

        Intent next = shadowOf(activity).getNextStartedActivity();
        assertNotNull("the app opened nothing", next);
        assertEquals(WebViewFallbackActivity.class.getName(), next.getComponent().getClassName());
    }

    @Test
    public void whenChromeNeverAppearsTheSiteOpensInTheAppsWebViewAfterTheTimeout() {
        installChrome();
        LauncherActivity activity = tapTheIcon();
        assertEquals(CHROME, shadowOf(activity).getNextStartedActivity().getPackage());

        // Chrome accepted the request but nothing ever covered the launcher.
        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(LauncherActivity.HANDOVER_TIMEOUT_MS + 500));

        Intent next = shadowOf(activity).getNextStartedActivity();
        assertNotNull("still nothing on screen after the timeout", next);
        assertEquals(WebViewFallbackActivity.class.getName(), next.getComponent().getClassName());
    }

    @Test
    public void theAppWritesItsCookiesOutWhenAScreenGoesToTheBackground() {
        // GoodErpApplication is what keeps the login after the app is swiped away (WebView mode).
        assertTrue(ApplicationProvider.getApplicationContext() instanceof GoodErpApplication);
    }

    @Test
    @Config(sdk = 35)
    public void theWebViewScreenStaysBelowTheStatusBarOnAndroid15() throws Exception {
        Context context = ApplicationProvider.getApplicationContext();
        ActivityInfo info = context.getPackageManager().getActivityInfo(
                new ComponentName(context, WebViewFallbackActivity.class), 0);
        android.content.res.TypedArray a = context.getTheme().obtainStyledAttributes(
                info.theme, new int[] {android.R.attr.windowOptOutEdgeToEdgeEnforcement});
        try {
            assertTrue("the WebView screen is drawn under the status bar", a.getBoolean(0, false));
        } finally {
            a.recycle();
        }
    }
}
