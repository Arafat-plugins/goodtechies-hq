# Timer extension API — the contract (Phase 11, decision 11-01)

The backend (D2) and the extension (D3/D4) are built to this file, side by side. Where code and
this file disagree, this file wins until a decision changes it. Dates are ISO 8601 with offset;
`minute` values are the minute's start, seconds zeroed (`2026-10-05T09:41:00+06:00`).

## 1. Who, and with what

- **Only remote timer users.** Every route below runs `Gate::allows('track', TimeEntry::class)`
  (`TimeEntryPolicy::track`: `timer.use` **and** `employee.tracking_mode = remote_timer`, active user).
  Anyone else gets `403 role_not_allowed` — pairing included, so an office employee never gets a token.
- **Token.** A Sanctum personal access token named `timer-extension`, abilities exactly
  `timer:read-tasks`, `timer:track`, `timer:heartbeat`, `expires_at` null. One `devices` row per
  pairing (`kind = extension`). Revoked from Profile, by Admin (employee page), and by employee
  deactivation → the token is deleted and `devices.revoked_at` set → the next call is `401 token_invalid`.
- **Inactive users.** `EnsureRemoteTimerUser` also refuses a deactivated user (`403 account_inactive`); the API group carries no session, so the web `active` middleware is not used there.
- **The token opens nothing else.** Web routes use the session guard, so a bearer token is a
  stranger there (redirect to login / 401, never 200). No other `/api/*` route exists; a request to
  `/api/user` or any unlisted path is 404 (test).
- **Server address** is a setting in the extension (default `https://erp.goodtechies.com`;
  `http://127.0.0.1:8000` and `http://localhost:8000` allowed as-is; any other origin asks
  `chrome.permissions.request` at runtime). Requests carry `Authorization: Bearer <token>`,
  `Accept: application/json`, `X-Timer-Client: extension/0.1.0`.

## 2. Routes

| Method, path | Ability | Body | 2xx |
| --- | --- | --- | --- |
| `POST /api/extension/exchange` | none (throttle `5,1` per IP) | `{code, device_name, install_uuid}` | `201 {token, device:{id,name}, user:{name}, server_time, state}` |
| `POST /api/extension/disconnect` | any of the three | — | `204` (token deleted, device revoked) |
| `GET /api/timer/state` | `timer:track` | — | `200 State` |
| `GET /api/timer/tasks` | `timer:read-tasks` | — | `200 {tasks:[{id,title,project}]}` (`BuildsTimerState::timeableTasks`) |
| `POST /api/timer/start` | `timer:track` | `{task_id, client_uuid, started_at?}` | `200 State` (`TimerService::start`, `activity_source = extension`; a running entry on another task is switched, as the web does) |
| `POST /api/timer/pause` | `timer:track` | `{time_entry_id}` | `200 State` |
| `POST /api/timer/resume` | `timer:track` | `{time_entry_id}` | `200 State` |
| `POST /api/timer/stop` | `timer:track` | `{time_entry_id}` | `200 State` |
| `POST /api/timer/heartbeat` | `timer:heartbeat` | `{time_entry_id, client_uuid, samples:[Sample]}` (1–120 samples) | `200 State + {accepted, repeated, rejected:[{minute, reason}]}` |
| `POST /api/timer/idle-decision` | `timer:track` | `{time_entry_id, idle_from, idle_to, decision}` | `200 State + {decision:{idle_from, decision, discarded_seconds, repeated}}` |

`pause`/`resume`/`stop`/`heartbeat`/`idle-decision` on an entry that is not this user's → `404 entry_not_found`.
On an entry whose state does not allow the action (heartbeat or pause on a stopped entry, resume on a running one) →
`409 entry_not_running` / `409 entry_not_paused`, **with the full `State` in the body**, so the client re-syncs instead of
retrying. `exchange` with an unknown, used or expired code → `422 code_invalid`; with a code whose user may not track →
`403 role_not_allowed` (the code is burned either way).

Pairing code: Profile → "Connect timer extension" (remote users only) → `POST /profile/extension/code` mints an 8-character
code (`A-Z2-9`, no 0/O/1/I), cache key `extension_pair:<code>` → user id, TTL 10 min, deleted on use. `DELETE
/profile/extension/devices/{device}` revokes. Admin: `DELETE /admin/employees/{employee}/extension-devices/{device}`.

## 3. Error envelope

```json
{ "error": "entry_not_running", "message": "This timer is not running any more.", "state": { … } }
```

Codes: `401 token_invalid` · `403 role_not_allowed` · `403 account_inactive` · `403 ability_missing` · `404 entry_not_found` ·
`409 entry_not_running` · `409 entry_not_paused` · `422 validation_failed` (`errors` map, Laravel's) ·
`422 code_invalid` · `429 too_many_requests`. `state` is present on 409 only.

## 4. State — what both clients show

The web timer's `TimerState` (`BuildsTimerState`, `TimeEntryResource`) **plus** `activity` and `device`:

```jsonc
{
  "server_time": "2026-10-05T09:41:12+06:00",
  "running": { /* TimeEntryResource: id, client_uuid, task{id,name}, project, started_at, paused_at,
                 paused_seconds, elapsed_seconds, state: running|paused|stopped, … */ } | null,
  "today": { "date": "2026-10-05", "counted_seconds": 9120, "pending_seconds": 0, "target_seconds": 18000 },
  "heartbeat_seconds": 60,
  "heartbeat_timeout_minutes": 5,
  "manual_time_requires_approval": true,
  "activity": {
    "idle_prompt_seconds": 120,          // settings.idle_prompt_seconds
    "idle_pause_minutes": 5,             // settings.idle_pause_minutes — unanswered prompt → server pause at idle start
    "meeting_default_minutes": 60,       // the "I'm in a meeting" button
    "pending_idle": null | { "idle_from": "…", "auto_paused_at": "…" | null },
    "last_sample_minute": "…" | null,
    "last_sample_source": "extension" | "web" | null
  },
  "device": { "id": 3, "name": "Chrome on DESKTOP-FSIN407", "paired_at": "…" }
}
```

**The one formula**, both clients, recomputed every second on screen and reset by every response:

```
offset   = Date.parse(server_time) − Date.now()                 // at each response
elapsed  = running.elapsed_seconds + (running.state === 'running' ? (Date.now() + offset − Date.parse(server_time)) / 1000 : 0)
left     = max(0, today.target_seconds − (today.counted_seconds + elapsed))      // "left today"
```

No client increments a counter of its own. `elapsed_seconds` is the server's, measured at `server_time`.

## 5. Sample — one minute of activity

```jsonc
{
  "minute": "2026-10-05T09:41:00+06:00",
  "state": "active" | "media" | "call" | "idle",
  "call_source": "detected" | "manual" | null,      // required when state = call
  "sites": [ { "kind": "site", "host": "docs.google.com", "seconds": 48 },
             { "kind": "other_app", "host": "", "seconds": 12 } ]
}
```

Rules the server enforces (`HeartbeatRequest`): `minute` is a whole minute, not in the future (app zone,
`notInTheFuture`), not before `started_at`; ≤ 120 samples; ≤ 20 sites per sample; `seconds` 0–60 each and
**sum ≤ 60**; `kind ∈ {site, other_app, browser_internal, private}`; `host` required and non-empty only for
`site`, otherwise `""`. **Host** is lower-case, `www.` stripped by the client, and must match
`^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::\d{1,5})?$`
(punycode `xn--` labels pass; non-ASCII is rejected — the client converts). A port is accepted only after
`localhost` or an IPv4 host. Anything with `/`, `?`, `#`, `@`, a space or a scheme is `422 validation_failed`.
A payload containing a key named `url`, `title` or `hostname` anywhere is rejected (test: it never appears
in requests, tables or resources).

Idempotency: `activity_samples` unique on `(time_entry_id, minute_at)`; `activity_sites` unique on
`(time_entry_id, minute_at, kind, host)`. A repeated sample is counted in `repeated`, never stored twice.
**Ownership (decision 11-04):** a sample with `source = extension` overwrites a `web` one for the same minute;
a `web` sample never overwrites an `extension` one. The web timer's own heartbeat (`/employee/time/heartbeat`)
stores `{state: active, source: web}` for its minute only when the minute has no sample yet; it carries no sites.
Nothing is stored for a paused or stopped entry (`409 entry_not_running`).

## 6. Idle — who does what

- **Client (extension)** owns the prompt. Pure idle clock: `idleSince = max(lastInputAt, lastExemptAt)` where
  `lastExemptAt` is the last moment a media/call/manual-meeting exemption was true; `promptDue` when the screen is
  not locked and `now − idleSince ≥ idle_prompt_seconds`; while locked, no prompt, shown on unlock; a stretch that
  ends while the person is still away restarts the clock from its end. Buttons post `idle-decision`.
- **Server** owns the auto-pause. `IdleRule::apply(entry, now)` runs on every heartbeat store and from the
  sweep: if the entry is running, the latest `idle_pause_minutes` consecutive stored minutes are `idle`, and no
  decision exists for that stretch → `TimerService::pause(entry, at: idleStart)` and
  `time_entries.idle_pending_from = idleStart`, `idle_auto_paused_at = now`; the state's `activity.pending_idle`
  tells the prompt to become "Timer paused at HH:MM" with Resume / Stop. A missing-heartbeat stretch (sleep,
  browser closed) is the existing watchdog's: `heartbeat_timeout_minutes` → stop at the last heartbeat, unchanged.
- **`idle-decision`** body: `idle_from` (a timestamp ≥ `started_at` — the moment the inactivity began, to the second), `idle_to` (≤ now, ≤ 24 h after `idle_from`),
  `decision ∈ {keep, discard, meeting, stop}`. Idempotent on `(time_entry_id, idle_from)` — table `idle_decisions`;
  a repeat returns the stored row with `repeated: true` and changes nothing. Effects: `keep` → nothing but the
  record; `discard` → `discarded_seconds = idle_to − idle_from` added to `paused_seconds` **and** stored on the row
  and the entry; `meeting` → the samples in `[idle_from, idle_to)` become `call` / `manual`; `stop` →
  `TimerService::stop`. Every decision: `AuditLogger` event `timer.idle_decision`. If the server had already
  auto-paused, the prompt shows only Resume / Stop: `TimerService::resume()` and `stop()` clear `pending_idle`; a `keep`/`discard`/`meeting`
  decision whose `idle_from` equals the auto-pause row's overwrites that row and clears `pending_idle` too.
- **"I'm in a meeting"** (popup button, default 60 min, countdown, cancellable) is client-side: the minutes are
  sent as `state: call, call_source: manual`. The server records them; no extra route.

## 7. Offline

Heartbeats queue in `chrome.storage.local` (`queue: Sample[]` per entry, oldest first, cap 24 h) and replay in
one `heartbeat` call per entry on reconnect; the server's idempotency and ownership rules make a replay safe.
A `409 entry_not_running` drops that entry's queue and the client adopts the returned `state`. The web
timer's existing `/employee/time/replay` is untouched.

## 8. Retention and rollups

On every `pause` and `stop`, `ActivityRollup::for(entry)` writes `active_minutes`, `media_minutes`,
`call_minutes`, `idle_minutes` from `activity_samples`. `hq:prune-activity` (daily 03:30) deletes
`activity_samples` and `activity_sites` older than `settings.activity_retention_days` (default 90); the rollup
columns stay. `hq:reconcile-activity` from the Phase 11 plan is **not** built: rollups run synchronously.

## 9. Extension state (chrome.storage) — D3 builds to this

```jsonc
// chrome.storage.local
{ "server": "https://erp.goodtechies.com", "token": "…", "device": {…}, "install_uuid": "…",
  "state": State | null, "state_at": 1759640472000,         // last response, for the formula
  "lastInputAt": 1759640400000, "lastExemptAt": 1759640460000, "lockedAt": null,
  "manualMeetingUntil": null | 1759644000000,
  "sites": { "open": { "kind": "site", "host": "docs.google.com", "since": 1759640400000 } | null,
             "closed": [ { "kind": "site", "host": "…", "from": …, "to": … } ] },
  "frames": { "<tabId>": { "<frameId>": { "videoPlaying": false, "streamLive": true } } },
  "queue": { "<time_entry_id>": [ Sample ] },
  "prompt": null | { "idle_from": …, "windowId": 12 | null, "notificationId": "…" | null } }
```

Content scripts post **only** `{type: "gt-media", videoPlaying: boolean, streamLive: boolean}`; the worker
validates the shape (`typeof === 'boolean'`, no other keys) and drops anything else.
