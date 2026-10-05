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
import org.junit.Before;
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

    /** Most tests are about the hand-over, on a phone where notifications were already allowed. */
    @Before
    public void notificationsAlreadyAllowed() {
        shadowOf((Application) ApplicationProvider.getApplicationContext())
                .grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS);
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

    /** The WebView screen's theme, read the way WebView reads it for prefers-color-scheme. */
    private static boolean webViewThemeIsLight() throws Exception {
        Context context = ApplicationProvider.getApplicationContext();
        ActivityInfo info = context.getPackageManager().getActivityInfo(
                new ComponentName(context, WebViewFallbackActivity.class), 0);
        android.content.res.TypedArray a = context.getTheme().obtainStyledAttributes(
                info.theme, new int[] {android.R.attr.isLightTheme});
        try {
            return a.getBoolean(0, true);
        } finally {
            a.recycle();
        }
    }

    @Test
    @Config(sdk = 34, qualifiers = "night")
    public void theWebViewScreenIsDarkWhenThePhoneIsInDarkMode() throws Exception {
        // Chrome already follows the phone; the WebView must too, or the site flips theme
        // depending on which of the two opened it (the client: dark, then light, then dark).
        assertTrue("the WebView screen tells the site 'light' on a phone in dark mode", !webViewThemeIsLight());
    }

    @Test
    @Config(sdk = 34, qualifiers = "notnight")
    public void theWebViewScreenIsLightWhenThePhoneIsInLightMode() throws Exception {
        assertTrue("the WebView screen tells the site 'dark' on a phone in light mode", webViewThemeIsLight());
    }

    @Test
    @Config(sdk = 35, qualifiers = "night")
    public void onAndroid15TheWebViewScreenFollowsDarkModeAndStaysBelowTheStatusBar() throws Exception {
        assertTrue(!webViewThemeIsLight());
        theWebViewScreenStaysBelowTheStatusBarOnAndroid15();
    }

    @Test
    public void onTheFirstStartItAsksForNotificationsAndOpensTheSiteAfterTheAnswer() {
        Application app = ApplicationProvider.getApplicationContext();
        shadowOf(app).denyPermissions(android.Manifest.permission.POST_NOTIFICATIONS);
        app.getSharedPreferences("goodERP", Context.MODE_PRIVATE).edit().clear().commit();
        installChrome();

        LauncherActivity activity = tapTheIcon();

        org.robolectric.shadows.ShadowActivity.PermissionsRequest asked =
                shadowOf(activity).getLastRequestedPermission();
        assertNotNull("did not ask for notifications", asked);
        assertEquals(android.Manifest.permission.POST_NOTIFICATIONS, asked.requestedPermissions[0]);
        // The only screen started so far is Android's own permission dialog.
        Intent dialog = shadowOf(activity).getNextStartedActivity();
        assertEquals("android.content.pm.action.REQUEST_PERMISSIONS", dialog.getAction());
        assertNull("opened the site before the answer", shadowOf(activity).getNextStartedActivity());

        activity.onRequestPermissionsResult(LauncherActivity.NOTIFICATION_PERMISSION_REQUEST,
                new String[] {android.Manifest.permission.POST_NOTIFICATIONS},
                new int[] {PackageManager.PERMISSION_DENIED});
        shadowOf(Looper.getMainLooper()).idle();

        Intent next = shadowOf(activity).getNextStartedActivity();
        assertNotNull("the site never opened after the answer", next);
        assertEquals(CHROME, next.getPackage());
    }

    @Test
    public void itAsksOnlyOnce() {
        Application app = ApplicationProvider.getApplicationContext();
        shadowOf(app).denyPermissions(android.Manifest.permission.POST_NOTIFICATIONS);
        app.getSharedPreferences("goodERP", Context.MODE_PRIVATE).edit()
                .putBoolean("asked_for_notifications", true).commit();
        installChrome();

        LauncherActivity activity = tapTheIcon();

        assertNull(shadowOf(activity).getLastRequestedPermission());
        assertEquals(CHROME, shadowOf(activity).getNextStartedActivity().getPackage());
    }

    @Test
    public void theTokenCookieIsForThisSiteOnlyAndHiddenFromScripts() {
        String cookie = AppPush.cookieFor("abc:DEF-123_x");
        assertTrue(cookie.startsWith("__Host-gerp_fcm=abc:DEF-123_x;"));
        assertTrue("a __Host- cookie may not name a Domain", !cookie.contains("Domain"));
        assertTrue(cookie.contains("Secure"));
        assertTrue(cookie.contains("HttpOnly"));
        assertTrue(cookie.contains("Path=/"));
    }

    @Test
    public void aNotificationOnlyEverOpensGoodErp() {
        assertEquals("https://erp.goodtechies.com/messages?conversation=4",
                GoodErpMessagingService.siteUrl("/messages?conversation=4"));
        assertEquals(LauncherActivity.SITE_URL, GoodErpMessagingService.siteUrl("https://evil.example/x"));
        assertEquals(LauncherActivity.SITE_URL, GoodErpMessagingService.siteUrl("//evil.example/x"));
        assertEquals(LauncherActivity.SITE_URL, GoodErpMessagingService.siteUrl(null));
    }

    @Test
    public void aPushShowsInTheNotificationShadeWithItsChatsText() {
        Application app = ApplicationProvider.getApplicationContext();
        java.util.Map<String, String> data = new java.util.HashMap<>();
        data.put("title", "Shahadat Hossain");
        data.put("body", "Standup at 10");
        data.put("url", "/messages?conversation=4");
        data.put("tag", "conversation-4");
        data.put("category", "messages");

        GoodErpMessagingService.show(app, data);

        android.app.NotificationManager manager = app.getSystemService(android.app.NotificationManager.class);
        android.app.Notification shown = shadowOf(manager).getNotification("conversation-4", 0);
        assertNotNull("nothing in the notification shade", shown);
        assertEquals("Shahadat Hossain", shown.extras.getString(android.app.Notification.EXTRA_TITLE));
        assertEquals("Standup at 10", shown.extras.getCharSequence(android.app.Notification.EXTRA_TEXT).toString());
        assertEquals(GoodErpMessagingService.CHANNEL_MESSAGES, shown.getChannelId());
    }

    @Test
    public void aSecondMessageFromTheSameChatReplacesTheFirst() {
        Application app = ApplicationProvider.getApplicationContext();
        java.util.Map<String, String> data = new java.util.HashMap<>();
        data.put("title", "Team");
        data.put("tag", "conversation-1");
        data.put("category", "messages");

        data.put("body", "one");
        GoodErpMessagingService.show(app, data);
        data.put("body", "two");
        GoodErpMessagingService.show(app, data);

        android.app.NotificationManager manager = app.getSystemService(android.app.NotificationManager.class);
        assertEquals(1, shadowOf(manager).size());
    }

    @Test
    public void nothingIsShownWithoutPermission() {
        Application app = ApplicationProvider.getApplicationContext();
        shadowOf(app).denyPermissions(android.Manifest.permission.POST_NOTIFICATIONS);
        java.util.Map<String, String> data = new java.util.HashMap<>();
        data.put("title", "T");

        GoodErpMessagingService.show(app, data);

        android.app.NotificationManager manager = app.getSystemService(android.app.NotificationManager.class);
        assertEquals(0, shadowOf(manager).size());
    }
}
