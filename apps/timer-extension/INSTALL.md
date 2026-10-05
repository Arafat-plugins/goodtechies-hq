# Install the goodERP Timer extension (Chrome and Edge on Windows)

The extension is only for people who work remotely and use the goodERP timer. It shows your
timer in the browser toolbar, and the time it shows is the same as the timer on your goodERP
dashboard. If you work in the office, you do not need it.

It connects to `https://erp.goodtechies.com` unless you change the server address.

## 1. Get the extension

You need one of these from your admin:

- `goodtechies-timer-0.1.0.zip` — unzip it (right-click → **Extract All…**), or
- the unpacked folder `goodtechies-timer-0.1.0`.

Keep the folder somewhere it will stay, for example `Documents\goodtechies-timer-0.1.0`.
The browser loads the extension from that folder every time it starts, so do not delete it.

## 2. Add it to your browser

**Chrome**

1. Type `chrome://extensions` in the address bar and press Enter.
2. Turn on **Developer mode** (top right).
3. Click **Load unpacked** and pick the `goodtechies-timer-0.1.0` folder (the one with
   `manifest.json` inside). You can also drag the zip file onto the page.

**Edge**

1. Type `edge://extensions` in the address bar and press Enter.
2. Turn on **Developer mode** (left side).
3. Click **Load unpacked** and pick the `goodtechies-timer-0.1.0` folder.

## 3. The install warning, and what it means

Chrome and Edge warn that this extension can "read and change all your data on all websites". That permission is what lets it notice a playing video or a live call on any page. It reads two yes/no facts from a page and the website's domain name, and nothing else.

What it records while your timer is running:

While your timer is running, this extension records which website domain you are on (for example docs.google.com) and how long, whether you are active, and whether a video or a call is playing. It never records page addresses, page titles, page content, what you type, or screenshots. When the timer is paused or stopped, nothing is recorded.

## 4. Connect it to your account

1. In goodERP, open your **Profile** and choose **Connect timer extension**.
2. Copy the 8-character code. It works once, for 10 minutes.
3. Click the extension icon in the browser toolbar.
4. Paste the code, check the server address and the device name, and click **Connect**.

If you see **Reconnect** later, your connection ended (for example it was disconnected from
goodERP). Get a new code and connect again the same way.

## 5. Pin the icon

Click the puzzle-piece icon in the toolbar and click the pin next to **goodERP Timer**, so the
timer is always one click away.

## 6. What the badge on the icon shows

The small badge on the icon shows your timer at a glance:

- **Green, with hours and minutes** (for example `1:02`) — the timer is running.
- **Amber `II`** — the timer is paused.
- **No badge** — nothing is running, or the extension is not connected. Click the icon to see the full timer, pause, resume or stop it.

## 7. Disconnect

Open chrome://extensions, click **Details** on goodERP Timer, then **Extension options**, and
click **Disconnect**. You can also remove the device from your goodERP Profile. To remove the
extension completely, open `chrome://extensions` (or `edge://extensions`) and click **Remove**
on its card.

## 8. Two things it cannot see

- **A call in a desktop app.** Zoom, Teams or Discord as desktop apps, or a phone call, happen
  outside the browser, so the extension cannot see them. The "Are you still working?" window's
  **I was in a meeting or call (keep the time)** button covers such a call.
- **Any tab playing sound counts as media.** Music playing in a tab, like a video, keeps that
  time from counting as idle.
