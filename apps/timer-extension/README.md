# goodERP Timer — Chrome/Edge extension (developer notes)

The remote-timer extension for GoodTechies HQ. It is built to `docs/extension-api.md`
(the contract with the Laravel backend). These are developer notes only; the user-facing
install guide is [`INSTALL.md`](INSTALL.md).

Plain ES modules: no framework, no build step, no dependency.

## Run the tests

Node 22, nothing to install:

```sh
cd apps/timer-extension
npm test
```

`npm test` runs `node --test`, which finds `tests/*.test.js` by itself.

## Load it in Chrome or Edge (development)

1. Open `chrome://extensions` (or `edge://extensions`).
2. Turn on **Developer mode**.
3. **Load unpacked** → choose `apps/timer-extension/src` (the folder with `manifest.json`).
4. After changing a file, press the reload icon on the extension's card. The service
   worker's console is under **Inspect views: service worker**.

## Icons and packaging

```sh
npm run icons     # src/icons/icon-{16,32,48,128}.png from public/brand/icon-512.png (Python 3 + Pillow)
npm run package   # dist/goodtechies-timer-0.1.0/ (unpacked) and dist/goodtechies-timer-0.1.0.zip
npm run test:ui   # Playwright: popup, prompt and options fit their sizes with no overflow
```

`dist/` is a build output and is not committed.

`npm run test:e2e` (`tests/e2e/run.mjs`) loads `src/` into a real headless Chromium and drives it against the dev server: dashboard/popup timer parity, call/video/idle classification with fake media, the idle prompt and its four buttons, and the server auto-pause. It needs the dev database `goodtechies_hq` seeded (it signs in as Tapu with `SEED_PASSWORD` from `.env` and writes to it through the app's own routes), read access as `hq_ro` via `psql`, built assets in `public/build`, port 8000 free (it starts and stops `php artisan serve` itself) and `PLAYWRIGHT_BROWSERS_PATH` set: `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers npm run test:e2e`. It prints an `ok|FAIL` table and exits 1 on any FAIL; it takes a few minutes, because each classification check waits for a fresh minute.

The server address defaults to `https://erp.goodtechies.com`. `http://127.0.0.1:8000` and
`http://localhost:8000` work as they are; any other address asks for permission at runtime.

## File map

| File | What it does |
| --- | --- |
| `src/manifest.json` | Manifest V3: permissions, content scripts, worker |
| `src/background/worker.js` | Service worker: alarms, idle, tabs, the minute tick, heartbeats, the idle prompt, commands from the pages |
| `src/content/stream-hook.js` | Page (MAIN) world: notices a live mic/camera stream from `getUserMedia` |
| `src/content/media-detector.js` | Every frame: sends only two booleans — a video is playing, a stream is live |
| `src/lib/classifier.js` | One minute → `active` / `media` / `call` / `idle` |
| `src/lib/idleClock.js` | When the "are you still working?" prompt is due |
| `src/lib/sites.js` | Tab address → domain only; per-minute seconds per domain |
| `src/lib/formula.js` | The one timer formula shared with the web timer; time formatting |
| `src/lib/queue.js` | Offline heartbeat queue |
| `src/lib/messages.js` | Validation of every message the worker receives |
| `src/lib/api.js` | HTTP client and the route list |
| `src/lib/storage.js` | `chrome.storage.local` wrappers and the stored shape's defaults |
| `src/lib/badge.js` | Toolbar badge text and colour |
| `src/popup/popup.{html,css,js}` | Toolbar popup: pairing, task picker, the timer, "I'm in a meeting" |
| `src/prompt/prompt.{html,css,js}` | The idle prompt window (440 × 400) and its auto-paused form |
| `src/options/options.{html,css,js}` | Options page: server, what is recorded, limits, disconnect |
| `src/ui/client.js` | `send()` to the worker, `every()` timer, the formula re-exported as `fmt` |
| `src/ui/text.js` | Every string the pages show |
| `src/ui/shared.css` | Shared tokens (light and dark), buttons, focus rings |
| `src/icons/icon-*.png` | Toolbar icons, made by `scripts/make-icons.py` |
| `scripts/make-icons.py` | Resizes the project mark to 16, 32, 48 and 128 px |
| `scripts/package.sh` | Builds the unpacked folder and the zip in `dist/` |
| `tests/ui/popup-size.mjs` | Playwright size check of the three pages with a fake `chrome` |
| `INSTALL.md` | The user-facing install guide |
| `tests/*.test.js` | `node:test` unit tests for the `lib/` modules |

The service worker keeps nothing in memory between events: Chrome stops it after about
30 seconds without work, so every value lives in `chrome.storage.local` and time is driven
by `chrome.alarms`.

## Known limits

- **A call in a desktop app cannot be seen.** Zoom, Teams or Discord as desktop apps, or a
  phone call, happen outside the browser. During one, the person presses "I'm in a meeting"
  in the extension, or picks the meeting button on the idle prompt.
- **Any tab playing sound counts as media.** Music playing in a tab keeps the minute from
  counting as idle, just like a video does.
