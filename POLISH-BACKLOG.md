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

---

## A. Live sync — the big one (**messaging done 24 Sep 2026**; the rest open)

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

### A.3 What "perfect" means, screen by screen

This is the acceptance list for the polish phase. Each line is a thing the client will try.

| Screen | Must update without a reload |
| --- | --- |
| **Messages — open thread** | A new message appears. The composer does not move, the scroll does not jump if you are reading history, and it scrolls if you were at the bottom  ✅ **done** — 10 s poll / 375 ms socket|
| **Messages — rail** | The row's last-message line and its unread pill change; the row re-sorts if the order is by activity  ✅ **done** — 15 s poll (no inbox channel, so poll even on a socket)|
| **Messages — anywhere in the app** | The Messages nav row's unread indicator |
| **Announcement banner** | A new announcement appears without a reload (and see B/C: it is still Messages-page-only, 6-18) |
| **Task detail / Discussion panel** | A new comment appears; status changes already broadcast and should paint  ✅ **done for comments** — same composable, same mount. Status still does not paint|
| **Task board** | A card moved by somebody else moves. Today two people dragging the same board overwrite each other silently |
| **Dashboards** | Counters and "needs your attention" refresh on a timer at minimum |
| **Attendance / Time** | The clock widget's live counter already ticks locally; a clock-in from another device should land |
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
| — | **Voice messages** (Phase 6) | Not built. The seams are real: `MessageService::post()` takes a kind and a duration, `MessageResource` prints it, `MessageThread` renders an `<audio>` for `kind === 'voice'`. It needs a recorder in the composer and two arguments at one call site. **GATE D cannot be asked for until this exists** |
| 6-18 | **The announcement banner app-wide** | Still Messages-page-only. Needs one prop in `HandleInertiaRequests` and the prop-shape tests that come with it (2-42's warning) |
| A | **Live sync** | Section A above. Described as "the seam is in place", which was true and was not the same as working |

### C.2 Found by the client and deferred

| Item | Where it stands |
| --- | --- |
| ~~**The chat has no colour** (24 Sep)~~ | **Done 24 Sep.** Section B |
| **GATE C polish** | The client said *"need some polish but you don't have to do"* and it was taken at face value. It is still un-itemised, which is its own debt — the next GATE should capture the specifics instead of accepting a wave |

### C.3 Open follow-ups from the decision log

Each of these is written up in full in `docs/decisions.md`; this is the index.

| # | One line |
| --- | --- |
| 2-30 | The task detail payload ships an `attachments` array no screen reads — two signed-URL mintings per attachment, per render |
| 2-48 | A handled review notification stays unread forever; nothing closes a row when its subject is dealt with |
| 2-49 | The reviewer's reason never reaches the notification, only the activity trail |
| 2-50 | The Admin dashboard's "Needs your attention" panel and status donut have no feed and show empty states under real counts |
| 2-51 | The Files tab is not in the URL on project and client detail, so a reload drops to Overview |
| 3-12 | A recurring template can be switched off but never deleted — **client decision** |
| 3-13 | A template's name still shows its `{period}` placeholder on task detail |
| 4-17 | `SurfaceTest` asserts a hard-coded count of audit-event cases; every phase has to bump it |
| 4-25 | `TimerService::edit()` can undo an Admin's refusal when approval is off — **client decision** |
| 5-17 | A leave day that is also a company holiday still burns Annual leave — **client decision** |
| 5-19 | There is no way to withdraw a leave request; you must ask an Admin to reject it |
| 5-20 | "Menu item opens a confirm dialog" drops focus to `<body>` app-wide; fixed on Holidays only |
| 6-16 | "May this person be sent a DM" is written twice; wants a `ConversationPolicy::dm()` |
| 6-17 | In polling mode `POST /broadcasting/auth` says yes to everyone (Laravel's behaviour, leaks nothing) |
| M-12 | The Messages workspace height is a `calc()` constant that drifts if the top bar changes |
| M-14 | The context panel prints a status as a bare word; the endpoint should send `status_label` + `status_tone` |
| M-15 | Unread state compares second-precision timestamps, so a reply in the same second is already-read |
| M-16 | `inboxFor()` has an ungrouped `orWhere` — a correctness trap for the next person to add a constraint |
| M-11 | Reactions, pinned messages, threaded replies, presence, call/video, rich text, jump-to-search-hit — **none has a table, column or endpoint.** `PROGRESS.md` prices each |

### C.4 Only the client can do these

| Item |
| --- |
| Line 1 of `D:\goodtechies-hq\.env` → `APP_NAME="goodERP"`, and line 5 of `deploy/.env.production.example`. The file bridge refuses any env-shaped filename because it holds the database and seed passwords. The launchers set the name in the environment, which wins, so the app is correctly named — this only makes it permanent |
| GATE A answers: real email addresses, the VPS and backup bucket, **Google Workspace** (Phase 7 needs it), spec §46, the ClickUp export |
| GATE B answers: contacts per client, employee priority visibility, the unarchive target status, the "Internal" label |

---

## E. Found while building, not yet fixed

### E.1 The focus ring is under the 3:1 floor across the whole application — **high**

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

### E.2 `DESIGN.md` is behind the code

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

### E.3 `shadow-xs` at the project Discussion mount

`Pages/Admin/Projects/Show.vue:274` carries a `shadow-xs` that `DESIGN.md` §5.13 forbids. One
class.

---

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
