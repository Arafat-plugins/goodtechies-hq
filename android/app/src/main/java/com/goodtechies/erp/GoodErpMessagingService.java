package com.goodtechies.erp;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * Receives goodERP's pushes from Firebase and shows them in the phone's notification shade
 * (1.0.6), whether the app is open, in the background or closed.
 *
 * The server sends data only (title, body, url, tag, category — the same fields as Web Push),
 * so the notification is drawn here and looks the same in every state. Tapping it opens goodERP
 * at that chat or page. One notification per chat: a new message replaces the last one.
 */
public class GoodErpMessagingService extends FirebaseMessagingService {
    static final String CHANNEL_MESSAGES = "messages";
    static final String CHANNEL_ALERTS = "alerts";

    @Override
    public void onNewToken(String token) {
        AppPush.rememberToken(token);
    }

    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {
        show(this, remoteMessage.getData());
    }

    static void show(Context context, Map<String, String> data) {
        if (Build.VERSION.SDK_INT >= 33
                && ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS)
                        != PackageManager.PERMISSION_GRANTED) {
            return;
        }
        // The person is looking at goodERP in the app right now: it already shows it there.
        if (GoodErpApplication.isInForeground()) {
            return;
        }

        String title = value(data, "title", "goodERP");
        String body = value(data, "body", "");
        String tag = value(data, "tag", "goodERP");
        boolean isMessage = "messages".equals(data.get("category"));
        String channel = isMessage ? CHANNEL_MESSAGES : CHANNEL_ALERTS;

        ensureChannels(context);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, channel)
                .setSmallIcon(R.drawable.ic_notification)
                .setColor(ContextCompat.getColor(context, R.color.brand))
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setCategory(isMessage ? NotificationCompat.CATEGORY_MESSAGE : NotificationCompat.CATEGORY_REMINDER)
                .setContentIntent(openIntent(context, data.get("url"), tag));

        NotificationManagerCompat.from(context).notify(tag, 0, builder.build());
    }

    /** Opens goodERP at the pushed page — only ever a path on goodERP's own address. */
    static PendingIntent openIntent(Context context, String url, String tag) {
        Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(siteUrl(url)), context, LauncherActivity.class)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        return PendingIntent.getActivity(context, tag.hashCode(), intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    static String siteUrl(String path) {
        String base = LauncherActivity.SITE_URL;
        if (path == null || !path.startsWith("/") || path.startsWith("//")) {
            return base;
        }
        return base.substring(0, base.length() - 1) + path;
    }

    static void ensureChannels(Context context) {
        if (Build.VERSION.SDK_INT < 26) {
            return;
        }
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null) {
            return;
        }
        manager.createNotificationChannel(new NotificationChannel(
                CHANNEL_MESSAGES, context.getString(R.string.channel_messages), NotificationManager.IMPORTANCE_HIGH));
        manager.createNotificationChannel(new NotificationChannel(
                CHANNEL_ALERTS, context.getString(R.string.channel_alerts), NotificationManager.IMPORTANCE_HIGH));
    }

    private static String value(Map<String, String> data, String key, String fallback) {
        String value = data.get(key);
        return value == null || value.isEmpty() ? fallback : value;
    }
}
