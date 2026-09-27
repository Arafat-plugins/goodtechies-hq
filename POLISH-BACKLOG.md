# Polish backlog — things to finish AFTER the phases

> Opened 24 Sep 2026, at the client's instruction: *"after all phases done then need to
> implement it perfectly on here"*.
>
> This file is for work that is **known, named and deliberately deferred** — not for bugs and
> not for ideas. Two rules keep it honest:
>
> 1. Nothing goes in here that has not actually been promised to the client or found by them.
> 2. Nothing leaves here except by being built. An item that turns out to be wrong gets a line
>    saying why, it does not get deleted.
>
> `PROGRESS.md` is still what runs the project; this is its debt column. `docs/decisions.md`
> holds the reasoning behind each numbered item.
>
> **Worked end to end on 26 Sep 2026 as Phase 12's UX polish pass.** §A, §B, §E.1, §E.2, §E.3,
> §E.17 and thirteen of §C.3's rows are closed; three of those thirteen turned out to have been
> closed already and still listed, which is its own lesson (12-63). What is left in here is
> **client decisions, follow-ups opened by the pass itself, and M-11** — nothing that is merely
> waiting for somebody to get to it.

---

## A. Live sync — the big one — ✅ **done 26 Sep 2026** (messaging 24 Sep; the rest in the polish pass)

**What the client reported, 24 Sep 2026:** *"its not auto sync need to reload that page. and not
only that there lots of thinks are need always sync but you applied after reload."*

They are right, and it is broader than messaging. Here is what is actually true today, because
the honest version is the only useful one.

### A.1 What exists

| Piece | State |
| --- | --- |
| Laravel Reverb, self-hosted | Installed, configured, bound to 127.0.0.1 (6-13) |
| `routes/channels.php` + `app/Broadcasting/{Conversation,Notification,Task}Channel.php` | Built. `conversation.{id}` has a channel **and** a policy-backed auth callback |
| `resources/js/echo.ts` | Built. Two modes chosen at build time: `reverb` or `polling` (6-12) |
| The notification bell | The **only** thing wired end to end. Broadcasts `NotificationFeedChanged`, falls back to a 15-second poll, and says out loud when it is polling (6-8, 6-9) |
| Task status | `TaskStatusChanged`, `TaskSubmittedForReview`, `TaskCompleted` broadcast on `task.{id}` |

### A.1b Built 24 Sep 2026 — messaging only

The client asked for this one out of turn after testing the redesign. Gaps 1 and 2 below are
closed **for messaging**: `ConversationActivity` broadcasts on `conversation.{id}`, and
`live.ts`'s `useLiveRefresh()` subscribes on a socket build and polls on theirs. Decisions
M-24…M-36. Measured on their build: thread 10 s worst case, rail 15 s; on a socket build the
thread is 375 ms.

**Gaps 3 and 4 are still open**, and they are what stops this being finished:

- ~~their machine still has no Reverb and no queue worker~~ — **closed the same day** (M-37…M-40):
  both launchers now set the Reverb keys and `QUEUE_CONNECTION=database` in the environment, and
  `start-hq.bat` opens a Reverb window and a queue-worker window. Verified end to end: event →
  `jobs` row → worker → Reverb in 18.57 ms. A failure there is not fatal — the screen falls back
  to its interval and says "Reconnecting";
- every screen in A.3 **other than messaging** still needs a reload. The composable exists now,
  so each of those is three lines rather than a design.

### A.2 What is missing — four separate gaps, in order of what the client sees

1. **Nothing broadcasts a message.** `MessageService::post()` fires `MessagePosted` inside the
   write transaction, and the only subscriber is `NotificationDispatcher`. There is no
   `ShouldBroadcast` event on `new PrivateChannel('conversation.'.$id)` at all. This is the seam
   that was described as "in place" — the seam is in place; **the thing that hangs off it was
   never built.** So even on a socket deployment, a chat thread does not move.
2. **Nothing on the client subscribes to `conversation.{id}`.** `MessageThread` exposes exactly
   one method, `refresh()`, precisely so a socket can call it — and nothing calls it.
3. **The client's own machine is a polling build.** `VITE_REALTIME` is unset in
   `D:\goodtechies-hq\.env`, so `echo.ts` chooses `polling`, and `start-hq.bat` never starts
   Reverb. On that machine even the parts that *are* wired have no socket. Whatever is built
   below has to work on a polling build too, or it does not work on their desk.
4. **There is no general "this screen keeps itself current" pattern.** The bell has one, written
   for the bell. Every other screen is a server-rendered Inertia page and stays exactly as it
   was rendered until something navigates.

### A.3 What "perfect" means, screen by screen — ✅ **every row closed 26 Sep 2026**

This is the acceptance list for the polish phase. Each line is a thing the client will try.

**All of it was verified on the POLLING build, deliberately** — the client's machine is a polling
build, so a fix that only works over a socket is a fix they never see. `useLiveProps()` in
`Realtime/reload.ts` is the one pattern (12-44); every screen below updates with **zero document
loads**. What is still open is written up as 12-46: two people dragging the same board card race,
last write wins — live sync makes the loser's screen correct within a tick, so the symptom is a
card that jumps back rather than data that is wrong.

| Screen | Must update without a reload |
| --- | --- |
| **Messages — open thread** | A new message appears. The composer does not move, the scroll does not jump if you are reading history, and it scrolls if you were at the bottom  ✅ **done** — 10 s poll / 375 ms socket|
| **Messages — rail** | The row's last-message line and its unread pill change; the row re-sorts if the order is by activity  ✅ **done** — 15 s poll (no inbox channel, so poll even on a socket)|
| **Messages — anywhere in the app** | The Messages nav row's unread indicator  ✅ **done** — `AppSidebarNav.vue`, from the shell's shared live state|
| **Announcement banner** | A new announcement appears without a reload  ✅ **done, and app-wide** — `ShellLive.vue` renders it from `HandleInertiaRequests`, which closes **6-18** as well|
| **Task detail / Discussion panel** | A new comment appears; status changes paint  ✅ **done** — comments 24 Sep, status via `LiveTaskStatus.vue` on both task-detail screens|
| **Task board** | A card moved by somebody else moves  ✅ **done**. Two people dragging the *same* card still race, last write wins — 12-46|
| **Dashboards** | Counters and "needs your attention" refresh  ✅ **done** — all three shells|
| **Attendance / Time** | A clock-in from another device lands  ✅ **done** — `Shared/Attendance.vue`, both Time screens|
| **Notification bell** | Already correct. It is the reference implementation |

### A.4 The shape it should take

Not nine bespoke implementations. One transport, one pattern:

- **Server:** a `BroadcastsOnConversation` listener on `MessagePosted` dispatching a thin
  `ShouldBroadcast` event — the id and nothing else. **The frame is a doorbell, not a payload.**
  The screen answers by asking `GET /messages/{conversation}` for the truth, so what it paints is
  what the policy built. This is already the rule the bell follows (6-8) and it is why
  `MessageThread.refresh()` re-reads instead of accepting a handed-in frame.
- **Client:** one composable — `useLiveRefresh(channel, () => refresh())` — that subscribes when
  the socket is up, **and falls back to a poll with a sensible interval when it is not**, exactly
  as `notifications.ts` does. Every screen in A.3 then costs three lines.
- **A polling build must be a first-class citizen**, because it is what the client is running.
  A screen that only works on a socket is a screen that does not work.
- **The launcher should start Reverb**, or the docs should stop implying the client's machine has
  a socket. Right now `start-hq.bat` starts neither and says nothing.

**Estimate:** this is a phase, not a slice. The transport is one dispatch; the nine screens are
another; the launcher and the runbook are a third.

---

## B. The chat does not look like a chat — ✅ **done 24 Sep 2026**

**What the client reported, 24 Sep 2026:** *"also have not colorfull for chating"*, with a
screenshot of a DM with Yaseen.

The screenshot is accurate. In the 24 Sep redesign every message renders the same way whoever
sent it: same left alignment, same background, same text colour, name and clock above each run.
That is the right treatment for a **channel** — the team channel, a project channel, a task
discussion, where "who said this" is the important thing and forty bubbles alternating sides is
noise. It is the wrong treatment for a **DM**, which is a conversation between two people and
which every messaging app on the client's phone draws as two sides.

The redesign applied one treatment to all five conversation types. That was a decision made
without asking, and it is the wrong one.

### What to build

- **A DM is two-sided.** The viewer's own messages sit right, in the brand tint, with the
  foreground that passes contrast against it; the other person's sit left on the surface. Name
  drops out entirely — in a DM there are only two people and the side says which one.
- **Channels stay one-sided**, but gain colour they do not have today: a tinted own-message
  surface, a stronger author name, and the mention highlight actually reading as a highlight.
- **Colour comes from the tokens in `DESIGN.md` and nowhere else** — and every pair goes through
  the contrast table before it ships, light and dark. A chat bubble is the easiest place in an
  application to ship 3:1 text.
- **Never colour alone.** A bubble's side and its author line carry the same fact, so the screen
  still works for somebody who cannot tell the two tints apart (DESIGN.md §5.6).
- Avatars only where they mean something: on the first row of a run, in a channel. Not in a DM.

**Built 24 Sep 2026**, at the client's request, out of turn — they asked for this one now
rather than after the phases. Decisions M-17…M-23. A DM is two-sided with solid brand bubbles on
the viewer's side; channels keep avatars and author lines and gain a `--brand-tint` band on the
viewer's own messages. Every new pair was computed from `app.css` and passes in both modes. Two
designs were changed *because* the measurement refused them (M-20, and the ring in M-21), and two
bugs fell out of it (M-21, M-22).

**Still owed from this slice:** `DESIGN.md` §2 does not carry the new pairs and §5.3 needs a
sentence about the DM (M-23).

---

## C. Said and not done

Everything here was described to the client as coming, or found by the client, and is still open.
This is the list the client meant by *"you have not done something that you told me"*.

### C.1 Promised in a phase and still missing

| # | Item | Where it stands |
| --- | --- | --- |
| — | ~~**Voice messages** (Phase 6)~~ | **Done 24 Sep 2026.** Decisions 6-20…6-32, 37 tests. GATE D is now askable |
| — | ~~**The announcement banner app-wide** (6-18)~~ | **Done 26 Sep 2026.** One shared prop in `HandleInertiaRequests`, rendered by `ShellLive.vue` on every shell, with the prop-shape tests 2-42 asked for |
| — | ~~**Live sync**~~ | **Done 26 Sep 2026.** Section A above. Decisions 12-44…12-47 |

### C.2 Found by the client and deferred

| Item | Where it stands |
| --- | --- |
| ~~**The chat has no colour** (24 Sep)~~ | **Done 24 Sep.** Section B |
| **GATE C polish** | The client said *"need some polish but you don't have to do"* and it was taken at face value. It is still un-itemised, which is its own debt — the next GATE should capture the specifics instead of accepting a wave |

### C.3 Open follow-ups from the decision log

Each of these is written up in full in `docs/decisions.md`; this is the index.

| # | One line |
| --- | --- |
| 2-30 | ~~The task detail payload ships an `attachments` array no screen reads~~ — **done 26 Sep** (12-60). Dropping the key was only half; `files.uploader` stayed in both controllers' `DETAIL_RELATIONS`, so the query still ran |
| 2-48 | ~~A handled review notification stays unread forever~~ — **was already done** when this index was written (decisions 2-52…2-55). 12-63 |
| 2-49 | ~~The reviewer's reason never reaches the notification~~ — **was already done**. 12-63 |
| 2-50 | ~~The Admin dashboard's attention panel and status donut have no feed~~ — **was already done** by Phase 10's reports slice; the `:data="[]"` this row describes now survives only in comments. 12-63 |
| 2-51 | ~~The Files tab is not in the URL on project and client detail~~ — **done 26 Sep** (12-56), `lib/tabState.ts` |
| 3-12 | A recurring template can be switched off but never deleted — **client decision** |
| 3-13 | ~~A template's name still shows its `{period}` placeholder on task detail~~ — **done 26 Sep** (12-57) |
| 4-17 | `SurfaceTest` asserts a hard-coded count of audit-event cases; every phase has to bump it |
| 4-25 | `TimerService::edit()` can undo an Admin's refusal when approval is off — **client decision** |
| 5-17 | A leave day that is also a company holiday still burns Annual leave — **client decision** |
| 5-19 | ~~There is no way to withdraw a leave request~~ — **done 26 Sep** (12-58). **It was filed here as a client decision and it was a bug**: the applicant had to be *rejected* to escape their own mistake |
| 5-20 | ~~"Menu item opens a confirm dialog" drops focus to `<body>` app-wide~~ — **done 26 Sep** (12-52), `lib/menuFocus.ts`, ten call sites, no exemption list |
| 6-16 | ~~"May this person be sent a DM" is written twice~~ — **done 26 Sep** (12-61), `ConversationPolicy::dm()` |
| 6-17 | ~~In polling mode `POST /broadcasting/auth` says yes to everyone~~ — **not a bug, agreed and closed** (12-62). The 200 carries an empty body and no channel grant |
| M-12 | The Messages workspace height is a `calc()` constant that drifts if the top bar changes |
| M-14 | ~~The context panel prints a status as a bare word~~ — **done 26 Sep** (12-55). Follow-up: `toneForProjectStatus()` still serves eight project screens |
| M-15 | ~~Unread state compares second-precision timestamps~~ — **done 26 Sep** (12-53). Three things dropped the precision, not one; `timestamp(0)` *rounds*, so it was unfixable above the column |
| M-16 | ~~`inboxFor()` has an ungrouped `orWhere`~~ — **done 26 Sep** (12-54) |
| 12-46 | Two people dragging the **same** board card race, last write wins — needs optimistic concurrency on `tasks` (a version column, a 409, and a decision about what the UI does with one) |
| 12-55 | `toneForProjectStatus()` in `StatusPill.vue` still serves eight project screens beside the new server-side `ProjectStatus::tone()` — converging them is a payload change across all eight |
| 12-59 | A withdrawal writes no notification and does not resolve the approvers' existing unread row — same shape as 2-55: it needs a rule about the object's state, not the actor |
| M-11 | Reactions, pinned messages, threaded replies, presence, call/video, rich text, jump-to-search-hit — **none has a table, column or endpoint.** `PROGRESS.md` prices each |

### C.4 Only the client can do these

| Item |
| --- |
| Line 1 of `D:\goodtechies-hq\.env` → `APP_NAME="goodERP"`, and line 5 of `deploy/.env.production.example`. The file bridge refuses any env-shaped filename because it holds the database and seed passwords. The launchers set the name in the environment, which wins, so the app is correctly named — this only makes it permanent |
| GATE A answers: real email addresses, the VPS and backup bucket, **Google Workspace** (Phase 7 needs it), spec §46, the ClickUp export |
| GATE B answers: contacts per client, employee priority visibility, the unarchive target status, the "Internal" label |

---

## E. Found while building, not yet fixed

### E.1 ~~The focus ring is under the 3:1 floor across the whole application~~ — ✅ **done 26 Sep 2026**

*Decisions 12-48, 12-49, 12-50. Opaque at the ~84 call sites rather than by redefining `--ring`;
every swept surface measured ≥3:1 afterwards, with `--primary` the one documented exception the
DM bubble already handled. Two defects were found doing it that nobody had raised: `--elevation-flat:
none` was deleting the ring outright on every control carrying `shadow-flat`, and menu, command and
select items had no ring at all. `tests/Unit/{FocusRingContrastTest,DesignVocabularyTest}.php`
stop all three coming back. The original write-up follows.*

`resources/css/app.css` sets `outline-ring/50`, and every focusable control in this repo writes
`focus-visible:ring-ring/50`. `DESIGN.md` §2.2 records the ring at **3.45:1** against the
background and **3.61:1** against a card and marks both ✅ — but those are the ring measured
**opaque**, and the application renders it at 50 %.

Composited, the real figures are:

| Ring at 50 % over | Light | Dark |
| --- | --- | --- |
| `--background` | **1.90:1** ❌ | **2.44:1** ❌ |
| `--card` | **1.93:1** ❌ | **2.42:1** ❌ |
| `--muted` | **1.87:1** ❌ | **2.31:1** ❌ |

WCAG 2.2 §1.4.11 wants 3:1 for a focus indicator. Computed twice, independently, from the oklch
values in `app.css` — once by the agent that built §B and once by the main session.

This is not a messaging bug. It is every button, link, input, menu item and row in goodERP, and
it quietly invalidates the "visible focus ring at every tab stop" line in the accessibility floor
this repo has been holding itself to since Phase 0.5: the rings were *present*, and presence was
what got measured. The two measurement hazards in `AGENTS.md` are about reading a ring that is
there; this is about a ring that is there and too faint.

**The fix** is one decision and then a sweep: either `--ring` goes opaque at the call sites
(`focus-visible:ring-ring`), or it gets its own darker token, or the ring gains an offset so it
sits against a surface it contrasts with. Then `DESIGN.md` §2.2 is corrected to measure what is
rendered rather than the raw token, and the sweep is mechanical across dozens of files. It is a
slice of its own and it should come **before** the accessibility claims are made again at a gate.

One place already ships the fix locally: a DM's own bubble uses an opaque `ring-primary-foreground`,
because over `--primary` the `/50` measured **1.19:1** — invisible rather than merely weak.

### E.2 ~~`DESIGN.md` is behind the code~~ — ✅ **done 26 Sep 2026** (§2.2's ring rows now measure what is rendered; §5.13 confirmed correct and the code swept to it — E.17)

- §2 has none of the pairs the chat-colour slice ships (M-23).
- §5.3 says brand "appears once per screen", which a DM full of brand bubbles is not. The rule's
  intent still holds everywhere else; the DM is the one screen where the brand *is* the content.
- §2.2's ring rows measure the raw token, not the rendered `/50` — see E.1.

### E.4 `config/queue.php` has `after_commit => false` on every connection — **medium**

Any `ShouldBroadcast` event dispatched from inside a transaction pushes its job immediately, so a
rollback has already broadcast. `ConversationActivity` opts out individually with
`ShouldDispatchAfterCommit` (M-26); nothing else does, and `NotificationFeedChanged` has the same
latent shape — harmless only because its frame is the whole feed, re-read by the worker.
Flipping the config fixes the class in one place and changes the timing of every existing queued
job, so it is a decision rather than a quiet fix.

### E.5 Two Messages-page defects found while wiring the refresh — **low**

- `Pages/Admin/Projects/Show.vue` builds `:thread="emptyThread(...)"` in the template, so every
  re-render of that page hands `MessageThread` a fresh empty object and blanks a thread somebody
  is reading (M-35). One line: hoist it into a `computed`. Does not fire today.
- `MessageController@index` builds `active` on every partial reload, because Inertia evaluates a
  prop before `only:` filters it out — so each 15-second rail poll runs the whole thread query
  and mints a signed URL per attachment (M-36).
- The active row's unread pill flickers for one cycle: `unreadCounts()` is taken *before*
  `markRead()` in the same request, deliberately, so opening a thread still shows you where you
  were. Pre-existing, cosmetic, more visible now the rail refreshes.

### E.6 A sent voice note cannot be scrubbed — **medium**

`FileService::download()` returns a `StreamedResponse` and serves **no byte ranges**:
`Range: bytes=0-99` comes back 200 with the whole file and no `Accept-Ranges`. Measured — the
recorder's own preview seeks perfectly (`blob:`), the same clip once sent reports
`seekable [[0, 0]]` and swallows every `currentTime` write (6-28).

The player degrades honestly rather than lying: it probes whether a seek landed, and when it did
not it disables the slider and renames it *"(this one cannot be moved through)"*. It re-enables
itself the day ranges are served, with **no client change** — so this is a pure server fix.

Underneath it, a `MediaRecorder` WebM carries no duration or cues in its header (6-29), which is
also why `audio.duration` is `Infinity`. Ranges alone improve it; a server-side remux
(`ffmpeg -c copy`) fixes it properly.

### E.7 Smaller things from the voice slice

- `MessageService::MAX_VOICE_SECONDS` and `voice.ts`'s `VOICE_MAX_SECONDS` are two copies of
  `300` with nothing enforcing agreement (6-30).
- `GET /messages/{conversation}` renders raw JSON to a browser navigation **and marks the thread
  read while doing it** (6-31). Not a privacy hole; a seam.
- A voice note is served `Content-Disposition: attachment`, because `File::INLINE_TYPES` has no
  audio. Harmless — a browser ignores it on a subresource, so `<audio src>` plays — but adding
  audio to `INLINE_TYPES` would make every uploaded media file render inside our own origin,
  which is a security change across all three file panels and was not what Phase 6 asked for.
- Dev login throttling cost an agent three test runs. `redis-cli flushall` between browser runs.

### E.3 ~~`shadow-xs` at the project Discussion mount~~ — ✅ **done 26 Sep 2026** (swept with E.17; `DesignVocabularyTest` now fails on any off-scale shadow anywhere)

`Pages/Admin/Projects/Show.vue:274` carries a `shadow-xs` that `DESIGN.md` §5.13 forbids. One
class.

---

### E.8 A newly built sidebar group is invisible until somebody clicks it — **medium**

*Found 25 Sep 2026, while verifying the reports slice in a browser.*

`AppSidebarNav`'s `ADMIN_DEFAULT_OPEN` is `my work / company / work`. Every other Admin group —
**WORKFORCE, REPORTS, FINANCE, ADMIN** — is collapsed for a viewer who has never touched it, and
the open/closed choice then persists per viewer. So the day a phase lands, the feature it built
is behind a disclosure nobody has a reason to open, and the honest reading of the sidebar is
*"that was not built"*.

That list carries its own comment: *"WORK stays open by default while most rows are still
phase-gated … Revisit once Phases 2-12 have landed."* Nine of the thirteen have.

Not fixed in the reports slice, deliberately: adding `reports` to the list would be
special-pleading for the newest thing while three equally built groups stay shut. The real
question is whether the default should be **open, with the rail for people who want it narrow**,
now that almost every row is live — which is a decision about the whole shell, and Part E puts
the UX polish pass in **Phase 12**.

Until then, the client is told where Reports is in the slice summary, which is a workaround and
should not have to be one. (Decision 10-35.)

### E.9 Two gaps in the report contract, raised and not guessed at — **medium**

*Found 25 Sep 2026, by both halves of the reports slice independently.*

1. **A report cell cannot be a link.** `ReportColumn` has no href and a row is scalars, so *"every
   count that is openable is a link"* — the rule every other screen in this app follows — does
   not hold on `/admin/reports/*`. A reader sees *"Overdue 7"* and has nowhere to click.
2. **A chart has no format.** `ReportChart` carries labels and numbers, so a money or minutes
   chart prints bare figures in its axis, its tooltip and its `sr-only` table. Today the builders
   put the unit in the chart's title, which works and is not the same thing.

Both want deciding **before** the second slice builds eight more reports on top of them —
retrofitting a link column across sixteen reports is a different job from designing it into the
contract now.

### E.10 The demo data has four more holes — **high**

*Found 26 Sep 2026, by building reports that read the tables. Decision 10-51; same family as
10-19 (no messages, no files) and 10-27 (no attendance, no tracked time).*

Every report opens with real rows, so Part E's *"done when"* holds. But four **dimensions** have
nothing behind them, which means four report shapes and three screens have never been seen
filled in by anyone:

| Gap | What is invisible because of it |
| --- | --- |
| No seeder creates a **leave request** | The Leave report shows balances and zero days taken. Leave approval, the calendar and the payroll leave-impact path have no demo state. |
| No seeder records a **meeting note or action item** | Meeting's two *"what came out of it"* columns are empty, and the action-item → task conversion is unseen. |
| `RecurringTaskSeeder` **generates no instances** (deliberate, per its docblock) | Maintenance and SEO show only *Ad hoc* periods — and the **period is the whole shape of those two reports**. |
| All seven seeded messages are **task discussions** | The team channel, announcements and DMs are empty, so AC6's *"task/project vs team/DM"* comparison has nothing on its right-hand side. |

This keeps being where the real defects come from: `WorkSeeder` was written because three
reports opened empty, and writing it exposed `Task::assignees()` having no `ORDER BY` (10-29),
two different words for one status (10-30), and a chart drawing Late in green (10-32). None of
those were findable by reading code.

The fix is one more seeder of `WorkSeeder`'s shape, with the same discipline: predicates from
the services that own them, idempotence keyed on identity rather than on a sentence, and the
expectation that existing tests assuming an empty table will need their assumptions corrected.

### E.11 Two dashboard cards read oddly on a non-working day — **low**

*Found 26 Sep 2026, on a Saturday.*

*Present today* reads **0 — "Nobody has clocked in yet"** and *Remote time today* reads **Tapu
0m / 5h** on a day that is an off day on every seeded schedule. Both figures are true; the words
around them are not, because *"yet"* promises a day that is still coming and a target of 5h is a
target for a day nobody is working.

`AttendanceService` already knows whether a date is a working day for a given schedule
(`isWorkingDay()`), so the cards can say *"Off day"* rather than a zero with an excuse. It is
Phase 4's card rather than Phase 10's, and it is cosmetic — but the client sees it every weekend.

### E.12 `WorkloadService` wants an `openCountsFor()` — **low**

*Found 26 Sep 2026, while wiring the Admin dashboard's Tasks-by-employee chart.*

The chart calls `WorkloadService::forViewer()` rather than writing its own `group by`, so the
bars **are** `/admin/workload`'s rows — which is the right call and is asserted employee for
employee. The cost is two `TaskService::count()` per employee. One `openCountsFor()` on the
service would make it one query, and both callers would get it.

### E.13 ~~The Back-button protection on a shown password degrades silently over plain HTTP~~ — ✅ **closed 26 Sep 2026**

*Found 26 Sep 2026, building the first-sign-in panel. Decision 12-5.*

The generated password is kept out of `history.state` by `Inertia::encryptHistory()` plus
`clearHistory()`. `encryptData` uses `crypto.subtle`, which browsers expose **only on a secure
origin** — and when it is missing it falls back to **plaintext with a console warning** rather
than failing. So the day the VPS is reached over plain `http://`, Back can restore the password
from history and nothing announces it.

**Closed by Phase 12's security pass** (12-36 … 12-38). Three things together: HSTS via a
`map $scheme` in `nginx.conf` (empty on http, so the port-80 block does not send a header a UA
must ignore), `fastcgi_param HTTPS $https if_not_empty` so `isSecure()` does not rest on a distro
default, and the `SESSION_SECURE_COOKIE=true` that `.env.production.example` already carried —
which turns out to be the strongest part of it, because no session can exist on a plain-HTTP
origin in the first place.

### E.14 `FilterBar`'s "Clear all" throws away query keys the page owns — **low**

*Found 26 Sep 2026, on `/admin/employees?view=access`.*

Chip-mode *Clear all* calls `resetQuery()` with no `keep` list, so it takes `?view=access` with
it and silently moves the reader from **Users & roles** to **Employees** — a different question,
answered without being asked. The Employees page re-applies it in its own `@clear` handler,
which works and is a workaround.

The fix is a `keep` list on `FilterBar`, so a page can say which of its query keys are not
filters. Every screen that grows a non-filter query parameter will need it.

### E.15 `WorkloadService` wants an `openCountsFor()` — see E.12 — **low** (now measured: +20 queries per 10 employees)

Recorded at E.12 during the dashboards slice; repeated here only because the Employees list is
now a second caller that would benefit.

### E.16 The Audit Log names a record by id, not by name — **low**

*Found 26 Sep 2026, looking at the built screen.*

The **Record** column reads `Employee #4` and `User #1`. The **Who** column already names the
actor, so this is the one place on the screen where a human being is a number.

It is defensible as built: an audit row points at a `target_type` + `target_id` that may no
longer exist — that is the nature of a permanent log — and resolving a name means a lookup that
can fail. But *"Employee #4"* on a compliance screen is a row somebody has to go and decode, and
`audit.view` is Admin-only so there is no scope question behind it.

The fix is to resolve the name where the row still exists and fall back to the id where it does
not, with the two visibly different — a name for a record that is still there, `#4` for one that
is gone. Which is also a more honest screen than one that always shows an id.

### E.17 ~~`shadow-xs` is on every card and `DESIGN.md` §5.13 allows none of it~~ — ✅ **done 26 Sep 2026**

**Settled: `DESIGN.md` was right and the code was stale.** `app.css` never defined `shadow-xs` at
all — it compiled quietly for eleven phases because `app.css` does not clear Tailwind's own
`--shadow-*` namespace, so the class *worked* and nothing said it was off-vocabulary. Swept, and
`tests/Unit/DesignVocabularyTest.php` now greps the source the way a reviewer would. Decision 12-51.
The original write-up follows.

*Flagged independently by two agents, in two different slices — three by the time it was resolved.*

`DESIGN.md` §5.13 names `shadow-flat` / `shadow-raised` / `shadow-overlay` as the whole
vocabulary. Every admin card in the repo uses `shadow-xs`, which is shadcn's generated base.
Both agents matched their neighbours rather than making one screen the odd one out, which was
the right call in the moment and leaves the contradiction standing.

**One of the two is stale and somebody has to say which.** Either `DESIGN.md` is describing a
token set the repo never adopted, or the repo has drifted from it — and since `DESIGN.md` is
generated from `app.css`, checking which of them `app.css` supports settles it. Cheap to
resolve, and it belongs with the UX polish pass; leaving it means the next agent flags it a
third time.

### E.20 The Employee dashboard's Notifications card reads as an empty state while the bell says 4 — **low**

*Found 26 Sep 2026 in the browser pass, on the screen the client's own bug report was about.*

`Pages/Employee/Dashboard.vue` renders an `EmptyState` titled *"In the bell, and in the Center"*
with an **Open the Notification Center** button. The reasoning is sound and written into the
file — the bell already lists the newest ten from one polled endpoint, and a second reader of it
here would be the duplicate `DESIGN.md` §5.8 forbids — but `EmptyState` is the component this
application uses to say *there is nothing*, and it is sitting under a bell badge reading **4**.
A person reads the screen, not the reasoning.

It is a signpost wearing an empty state's clothes. The fix is a treatment that is not
`EmptyState` — the count and the button, or a plain panel — and it is one component swap, not a
second feed. Deliberately **not** built in the polish pass: it was found by looking, it is
cosmetic, and inventing a third notification treatment at the end of a slice is how the first
two stopped agreeing.

### E.18 `User::hasPermission()` re-resolves a role's key list per User instance — **medium**

*Measured 26 Sep 2026 by Phase 12's performance pass. Decision 12-41.*

`$permissionKeys` is memoised on the **instance**, so any loop over users costs two queries each
(`$this->employee`, then the permissions join). Two loops on `/messages` —
`ConversationService::mentionableIn()` and `MessageController::messageable()` — make that
**+3.7 queries per employee in the agency**: 36 queries at today's five people, roughly 90 at
twenty.

A static role→keys map fixes this and `assignableEmployees()` / `reviewersFor()` at the same
time. **It was deliberately not done in slice 3**, and the reason is the whole difficulty:
`tests/Permissions/MatrixTest.php` revokes a permission mid-test and expects the next request to
see it, so a naive static cache breaks the one suite that proves this application's access rules.

The fix therefore needs an invalidation story, not just a cache — flush on `role_permissions`
write, or a request-scoped binding. Worth doing properly; not worth doing in a hurry.

### E.19 The `tsvector` byte saving from 10-18 is still outstanding — **low**

*Corrected 26 Sep 2026. Decision 12-39.*

10-18 recorded the fix as *"a `$hidden` entry on each affected model"*. That was **wrong**, and
the attribute has now been added anyway because it closes a real leak: `$hidden` is a
**serialisation** filter, so it stops a `search_vector` reaching a payload — but it does nothing
about `SELECT *`, and the ~282 bytes a row (confirmed exactly with `pg_column_size`) still travel
to PHP.

The byte saving needs explicit column lists on `TaskService::query()` and the report builders.
Which is a bigger change than it sounds: every `select()` added is a place a future column can be
forgotten, and a missing column is a null that looks like data.

## D. How this gets done

Not now. The client's instruction is explicit: **finish the phases first.** Phase 6 still owes
voice and GATE D; Phases 7–13 are unbuilt.

When the phases are done, this becomes its own numbered phase with its own gate, in this order:

1. **The focus ring** (E.1) — before any gate makes an accessibility claim again, because
   every claim already made rests on it.
2. **Live sync** (A) — the transport, then the nine screens, then the launcher and the runbook.
   It is the one the client actually feels.
3. ~~**Chat colour** (B)~~ — **done 24 Sep**, out of turn, at the client's request.
4. **C.1 and C.2** — the promises, oldest first.
5. **C.3 and E.2/E.3** — the follow-ups, grouped by file so they are one dispatch and not twenty.
6. **C.4** goes to the client as a single list, once, rather than a line in every report.

Anything discovered between now and then is appended here on the day it is found, with the date.
