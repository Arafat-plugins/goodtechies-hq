# Brief 004 — task-live-update

model: Opus 5.5 (claude-opus-5-5) · effort: high (session default; general-purpose fallback)

## Role
You implement one briefed change. The brief is authoritative.

This template runs on **Opus 5.5** (`claude-opus-5-5`), as every dispatch sub-agent does —
light copy fix or new subsystem alike. The model is fixed; what varies per task is the brief.

The brief ends with `[ task list broken down into phases, each phase as a vertical slice, numbered ]`.
That is your first action: before any edit, write a numbered list of phases, each a slice of
the target behaviour that is checkable on its own when the phase ends. Phases order the
briefed work; they never widen it. Work through them in order; report by them.

## Start here, every time

Read `AGENTS.md` at the repo root. It is the map of this codebase and it replaces exploring.
Then read **only the files the brief names**.

If the brief cites `ARCHITECTURE.md` or `DOMAIN.md` (a section, a `BR-nn`, a Permissions row),
read exactly those parts — `grep -E '^BR-07 ' DOMAIN.md`, the named section — and treat them as
you treat Inputs: **a brief cannot override a cited rule**, only a briefed change to that
document can. A brief that contradicts one, or a change that would break a rule you can see,
is a stop-and-report — do not pick a side.

You will be tempted to look around first. Do not. If the brief named the files, the caller has
already done that work; repeating it wastes the context you need for the actual change.

## Scope

Edit only the files listed under **Inputs**. If you become convinced a file outside that list
must change, **stop and report why** — do not edit it. An unbriefed edit is rejected on sight
even when the change itself is reasonable, because the caller cannot check what they did not
ask for. The same applies to files you would *create*: the caller reviews every new path.

If the brief is ambiguous in a way that changes the work, stop and ask — one question, with
the two readings and which you would pick. You cannot reach the user; the caller answers and
re-dispatches. Do not guess and do not do both.

Honour every line under **Out of scope**. They are there because something specific went wrong
before, or because a boundary exists that is not visible from the file you are editing.

## Conventions

Match the file you are in. Its indentation, naming, error handling, and comment density are the
spec — not your defaults, and not another file's style. `AGENTS.md` lists the conventions that
get a change rejected; read them before your first edit, not after.

Do not refactor, rename, reformat, reorder imports, or "clean up" adjacent code. Every unrelated
line in your diff costs the reviewer time and buries the change that matters.

Do not add a dependency — unless the brief's Task line says it is a dependency brief
(dependencies.md), in which case the manifest and lockfile are the only files you edit, through
the package manager's own command. Otherwise, if one is genuinely required, stop and report
that instead — name the package, the version constraint, dev or runtime, and why. The caller
runs a separate dependency dispatch, then re-dispatches you.

## Flows

If the brief has a **Flow** section, the In / Out contract and the invariants are as binding as
Inputs. Do not change what the previous step hands over or what the next step listens for — if
the change needs that, stop and report. Write or extend the flow test the brief names so it
drives the flow **through your step end to end** (request in, database and events out) and
asserts the invariants — test-first, as below.

## Verbatim text

Anything in the brief's **Verbatim** block — labels, messages, emails, copy, error strings,
names — is final: insert it byte for byte. No rewording, no "fixing" grammar, case,
punctuation or typos, no translating, no shortening. If it cannot be used as given, stop and
report; do not edit it.

## Verify before reporting

**Test first, for new behaviour.** Any brief whose Done means asks for a test that fails at BASE:
write the test before the code, run it and quote the failure, then implement, run it again and
quote the pass. `git stash` and other git state changes are not allowed; test-first is how you
show the failure. The caller cannot re-run BASE and rejects the test without both quotes.

Run the **targeted** tests first — the test files for the code you touched and the flow test(s)
the brief names (`AGENTS.md` → Commands, *Test (targeted)*). Run the full suite only when the
brief says so; the caller runs it once per task. Run lint on the files you changed. Compare
failures against the known-failing baseline — those are pre-existing and not yours. If you
cannot run a check, say so; do not assume it passes.

## Report

**At most 40 lines.** The caller reads the diff; do not quote your edits back. Per phase,
numbered as you planned them:
- what changed and why, file by file
- `verified: <command and result>` or `not verified: <why>`

Then:
- anything in "Done means" you could **not** verify, and why
- anything you noticed but deliberately did not touch

Never report success for something you did not verify. "Not verified" is the correct answer
when you could not check, and the caller needs it to do their own acceptance pass.

## Never

- commit, push, or otherwise change git state
- edit generated or vendored directories (`AGENTS.md` names them)
- leave `TODO`, stubs, or commented-out code behind
- widen the task because the fix "was small anyway"

## Task
Make the Tasks screens update **only when something happened, and silently** (user point **4**). Remove the "Every 20s" label and the 20-second timer. Broadcast one ids-only event per task change, after commit and to the others only, and only to the people who may see that task. Apply each burst of events on the Board as one coalesced partial reload that preserves state, with no flash, skeleton or scroll jump. Add silent fallbacks for reconnect, a long-hidden tab and a build with no socket.

## User's words

```text
1. in this image here i am in tasks tab and list view . only list view show site popup .but i want to use this popup system in board view also 
2. in  the second image i have marked the. my task due today over due . these will be in the tasks tab and will add as dropdown filter system
3. in the third image here. is card . from that card have to remove top project name like in here buffalo modular seo wroted here , alseo the employe name will be shorter i mean only inside the name logo not out side. in the date here five days lift i mean the date not appear only will how much /hours/minute/days/weeks/monthe left that will show . will show one thing from all of them. and the priority will be flaged as colorful depand on its values.  SEO development those tags will remove from the card
4. 4th image .here showing every 20 second its refresh. this text have to remove from here. and after every 20 second later its relload like flashing so its like odd . client want it will refresh like only when anything happend . like someone message .or some one asigned to task to him/her. and the refresh will not seen like flash .
5. 5th image . here in the bottom line here showing drag inside a lane to set it order . here no need this instructions remove this .
6. in the 6th image here you can see that i have marked with border . client want to scroll horizontal via mouse by presing mouse left button and moved left right . its like clickup system . so client no need to tuch the scrollbar all that time.
7. in the seven image here . the user dont want serchbar here. also the title of the page and descriptions should be remove . and others . overdue, show archived , mnage tags aadd filter those will be in the right side.
```

(All seven points are quoted for context. This brief implements only the points named in **Task**.)


## Verbatim — use exactly
No product text supplied. List every string you invent under "Invented strings".

## Flow
**F1 task-live-update**: new. Add it to `AGENTS.md` → a new `## Flows` section inside the dispatch markers, 8 lines at most, in this shape:
```
### F1 task-live-update
Trigger: any task mutation in TaskService / task controllers (assign, unassign, create, delete, archive, unarchive, status, priority, due date, title, tags, checklist) and a task comment/message
Steps: mutation commits → <YourEvent> (ShouldBroadcast, after commit, toOthers) → private user channel(s) of everyone who can see the task (before or after the change) → Tasks screen listener → coalesced silent partial reload
Payload: { task_id, kind } — ids and the kind of change only, never task text
Invariants: one broadcast per change; never before commit; never to a user who cannot see the task; never echoed to the acting tab; zero periodic requests while the socket is connected
Test: php vendor/bin/pest <your flow test file>
```
In: a committed task mutation. Out: one broadcast per change, and the Board's props patched in place.
Do NOT change what the existing notification pipeline (`NotificationDispatcher`, the bell, `ShellLive.vue`) sends or shows.

## Inputs
Files you may edit or create. These were located by `git grep`; confirm each, and name every other file you touch and why:
- **Backend.**
  - `app/Services/TaskService.php` (every mutation point) and the task controllers `app/Http/Controllers/{Admin,Employee}/TaskController.php`, plus `TaskDiscussionController`, `TaskFileController` if an upload counts as a change.
  - `app/Events/*`: existing `TaskAssigned`, `TaskReassigned`, `TaskStatusChanged`, `TaskCompleted`, `TaskSubmittedForReview`, `TaskCommented`, `TaskDeleted`, and `app/Events/Concerns/BroadcastsTaskStatus.php`, which already broadcasts status on `task.{id}`.
  - `routes/channels.php` (today: `conversation.{id}`, `notifications.{user}`, `task.{task}`) and `app/Broadcasting/*Channel.php`. `TaskChannel::join` is `Gate::allows('view', $task)`.
  - `config/queue.php`, only for the after-commit fix. POLISH-BACKLOG **E.4** says `after_commit => false` everywhere. Fix it for **these** events per event (`ShouldDispatchAfterCommit` / `$afterCommit`), not globally, unless you prove the global change is safe; say which you did.
- **Frontend.**
  - `resources/js/Components/Tasks/TaskBoard.vue`: `useLiveProps(['board'], …)` at about line 528, and the "Every 20s" label via `Components/Realtime/LiveIndicator.vue`.
  - `resources/js/Components/Realtime/reload.ts` (`useLiveProps`), `resources/js/Components/Realtime/live.ts` (`useLiveRefresh`, `LIVE_SAFETY_MS`).
  - `resources/js/echo.ts`, and `resources/js/lib/useNavigationPending.ts`, which must never fire for a background reload.
  - the four Tasks pages' components (`TaskList.vue`, `TaskCalendar.vue`, `Gantt/TaskGantt.vue`), only if they hold a timer. The main session found `useLiveProps` only in `TaskBoard.vue`, but confirm this.
  - `TaskDetailDrawer.vue` / `TaskDiscussionPanel.vue`: only as needed for the drawer rule below.
- **Board hold flags.** From the previous dispatch: `TaskBoard.vue` exposes `isPanning` (from `lib/dragPan.ts`) and `isDraggingCard`. Use them to hold an update.
- **Tests.**
  - The flow test (new, e.g. `tests/Feature/Realtime/TaskLiveUpdateFlowTest.php`).
  - Extend `tests/Feature/Realtime/{TaskBroadcastTest,BroadcastAuthTest,PollingFallbackTest}.php` as needed.
  - If you add a channel, add its `routes/channels.php` authorisation test.
- **Docs.**
  - `AGENTS.md` (the Flows block above).
  - `docs/decisions.md`: two new rows in its table format, numbered after the last row, and dated 2026-09-29:
    - **(a)** the one-unit countdown rule: one unit, floored, week = 7 days, month = 30 days, the deadline is 00:00 the day after the due date in `config('app.timezone')`, and it agrees with `Task::isOverdue`. Implemented in `lib/dueCountdown.ts`.
    - **(b)** the silent event-driven refresh rule with its three fallbacks.
  - `POLISH-BACKLOG.md` E.4: note what is now fixed and what is still open.
  - `docs/runbooks/realtime.md`: only if a new channel or event must be documented.

What the code does today (measured by the main session):
- The Board polls every 20 s through `useLiveProps(['board'])` and shows "Every 20s" (image 04). Each poll re-renders visibly, which the user calls "flashing".
- `VITE_REALTIME` (baked in at build) chooses between `reverb` and polling.
  - The user's machine runs Reverb and a queue worker (`start-hq.bat` sets `BROADCAST_CONNECTION=reverb`, `VITE_REALTIME=reverb`).
  - `.env.example` defaults to `polling`.
  - Tests run with `BROADCAST_CONNECTION=null`.
- Status changes already broadcast on `task.{id}` through `BroadcastsTaskStatus`. Other kinds of change do not broadcast.

Target behaviour. These are the user's approved defaults; do not re-decide them.
1. **Remove** the "Every 20s" label and the 20 s timer from the Tasks views. `useLiveProps` / `useLiveRefresh` also drive Messages, the bell and dashboards, and their behaviour must not change. **Add an option to the hook and leave its default alone.**
2. **Which events reach an open Tasks screen:**
   - a task assigned to or unassigned from someone;
   - created, deleted, archived or restored;
   - status, priority, due date, title, tags or checklist changed by another person;
   - a comment or message added on a task.

   While the socket is connected, there are **zero periodic requests** from the Tasks screens.
3. **Channel and recipients.**
   - A new board can't know about a task newly assigned to its viewer, so per-task channels alone cannot deliver "assigned to me". Use a **private per-user channel** (reuse an existing user channel only if that cannot change what the bell does). Its authorisation lets **only that user** join.
   - The server sends each change **only** to users who can see the task, by the same rule the page uses (`Task::visibleTo` / `TaskPolicy::view`), evaluated **before and after** the change, so the person just unassigned also gets the removal.
   - A person who cannot see a task never receives its id.
   - Keep the fan-out query cheap. It is a small team, but give the number of queries per mutation in the report, and keep `tests/Feature/Performance` green.
4. **Payload:** `{ task_id, kind }` (for example `kind: 'assigned' | 'unassigned' | 'created' | 'deleted' | 'archived' | 'restored' | 'updated' | 'status' | 'commented'`). No title, no names, no text.
5. **One broadcast per change,** after commit (`ShouldDispatchAfterCommit` or `$afterCommit`), and `->toOthers()` / `dontBroadcastToCurrentUser()` so the acting tab is not echoed.
   - Confirm the browser actually sends `X-Socket-ID` on **Inertia** visits and on the drawer's fetches; Echo only adds it to axios by default. If it is not sent, fix it in one place (e.g. an Inertia request header hook in `echo.ts` / `app.ts`).
   - Reuse existing events where they fit, and add one broadcast-only event where they don't. Do not make `NotificationDispatcher` send anything new.
6. **Frontend apply.**
   - On events, coalesce a burst into **one** partial reload: trailing 300–500 ms, `only: ['board']` (or each view's own prop), `preserveScroll`, `preserveState`, no progress bar, no skeleton.
   - The `useNavigationPending` skeleton must never fire for a background reload.
   - Lanes and cards keep stable keys (`task.id`), so only changed cards patch: no lane re-mount, no fade, no layout jump on unchanged cards. A changed or new card may use a short transition (≤150 ms) that honours `prefers-reduced-motion`.
   - **Hold** an update while `isPanning` or `isDraggingCard` is true, and apply it right after the drop or pan ends.
   - Keep decision **12-46** behaviour: two people dragging the same card still ends with last write wins, and the loser's screen corrects silently.
   - Must survive a live update: horizontal and vertical scroll, the open drawer and any text typed in it (the "Write a summary" box, the discussion composer), an open `⋯` menu or dropdown, and keyboard focus.
   - If an event names the task open in the drawer, refresh the drawer's data **without remounting its inputs**. If that can't be done safely, refresh only the parts that are not being edited, and say so.
   - List, Calendar and Gantt listen the same way, using their own props, if they are cheap to wire. If not, report what they do now: they have no timer, so they are simply static.
7. **Fallbacks, all silent:**
   - after the socket reconnects, resync once;
   - when the tab becomes visible after being hidden for ≥ 30 s, resync once;
   - when there is no socket (`VITE_REALTIME` is not `reverb`), poll every **60 s**, only while the tab is visible, using the same silent patch.

Page URL(s): `http://127.0.0.1:8004/admin/tasks/board`, `/employee/tasks/board`, `/admin/tasks`

Reference image(s): read these yourself with the Read tool:
- `docs/dispatch-refs/tasks-board-rework/04-every-20s-label.png` (the label to remove);
- `06-board-empty-space-pan-zones.png` (the scrolled board whose scroll must survive).

They are screenshots of the old UI, for reference only, with no fidelity target.

## Audience
Everyone who has a Tasks screen open while colleagues work. Reverb subscribers: the Board and the other Tasks views. Other consumers of `useLiveProps` / `useLiveRefresh` (Messages, the bell, dashboards, attendance, time) must keep their current behaviour. The notification bell and `ShellLive.vue` are out of scope.

## Format
- `AGENTS.md` conventions:
  - Authorization only in Policies and channel classes, never in Vue.
  - PSR-12 + `vendor/bin/pint` on the PHP you touch.
  - Vue `<script setup lang="ts">`.
  - Every task status move goes through `TaskService::transition()`; never write `status` directly.
- Events follow the existing `app/Events` style, including the `BroadcastsTaskStatus` concern.
- Tests are Pest, and a file's constants/functions are prefixed, because they are global.

## Out of scope — do NOT
- do NOT change Messages, the notification bell, `ShellLive.vue`, `NotificationDispatcher`, or what any notification says or who gets it
- do NOT change the card layout, toolbar, drawer layout, pan or drag code beyond reading their flags
- do NOT change permissions, policies, `visibleTo`, or the schema. If a version column or migration looks needed, **stop and report**.
- do NOT add a dependency; do NOT survey the repo; do NOT commit or change git state; do NOT edit `PROGRESS.md` or `docs/master-prompt-v1.md`

## Knowledge
Read `AGENTS.md` first (it is the repo map), then `docs/runbooks/realtime.md`, then only the files above. Environment: `/home/claude/goodtechies-hq`, with PostgreSQL and Redis running and the dev DB seeded. No other agent is running.

**Tests.** `php vendor/bin/pest <paths>`. Run `tests/Feature/Realtime`, `tests/Feature/Tasks`, `tests/Feature/Performance`, `tests/Permissions` and whatever else you touch. For JS, `npm run test:js`.

**Browser proof with a real socket.**
- `.env` is dev-only and git-ignored. It already has `BROADCAST_CONNECTION=reverb` and `REVERB_*` filled in. Set `VITE_REALTIME=reverb` and the `VITE_REVERB_*` values in it for the proof, then `npm run build`.
- Start `php artisan reverb:start` (port 8080) and `php artisan serve --host=127.0.0.1 --port=8004` in the background. Kill both when done.
- `QUEUE_CONNECTION` is `sync` in this `.env`; say whether that hides any after-commit ordering, and test after-commit in Pest regardless.
- At the end, **rebuild once with `VITE_REALTIME=polling`** to prove the 60 s fallback. Leave `.env`'s `VITE_REALTIME=reverb`.
- Playwright login helpers to copy are in `/tmp/claude-0/-home-claude/1bcaf59a-d11a-5392-b281-872a22dab3a0/scratchpad/d3/`. Put your scripts in `…/scratchpad/d4/`, never in the repo. Use two browser contexts for two people, for example the admin in context A and a second seeded admin or employee in context B.

## Done means
**Flow tests.** Write these first, and quote the run where each new assertion fails at BASE, then passes:
- [ ] Assigning a task broadcasts **once**, **after commit** (nothing when the transaction rolls back), to the right user channel(s), with an **ids-only** payload.
- [ ] Unassigning reaches the unassigned person.
- [ ] A user who cannot see the task is **not** among the recipients, and **cannot join** another user's channel (403 from `/broadcasting/auth`).
- [ ] The acting tab is **not echoed**: an `X-Socket-ID` on the request lands on the event's `socket`.
- [ ] One row per other change kind in item 2 (priority, due date, title, tags, checklist, create, delete, archive, restore, comment) asserting exactly one broadcast.

**No-flash proof in a real browser with Reverb.**
- [ ] Set a marker attribute on one **unchanged** card. Scroll the board horizontally and vertically. Open the drawer on another task and type text into its summary/composer. Then, from context B, change a **different** task's priority and assign a task to A.
- [ ] Show that:
  - the marker survives (same DOM node);
  - the changed card updated and the assigned task appeared;
  - no skeleton rendered (a MutationObserver on the skeleton selector counts 0);
  - `scrollLeft`/`scrollY` are unchanged;
  - the drawer is still open with its text intact;
  - exactly one `board` partial reload happened per burst.
- [ ] Record network requests for **60 seconds** with the socket connected and nobody changing anything: **zero** requests to Tasks routes. List any non-Tasks periodic requests you saw, e.g. the shell's, which are out of scope.
- [ ] **Hold during a pan and a drag:** start a pan (mouse down, move), fire an event from B, check that no reload happens until mouse up, and that one happens after it.
- [ ] **Fallbacks:**
  - kill and restart Reverb → one resync;
  - hide the tab for more than 30 s (emulate `visibilitychange`) → one resync;
  - a `VITE_REALTIME=polling` build → one silent `board` reload per 60 s while visible and none while hidden, measured over at least 130 s.
- [ ] The "Every 20s" label is gone from the Tasks views, and other `useLiveProps` users are unchanged (show the diff of the hook's default path is zero, or explain).

**Checks and docs.**
- [ ] `vendor/bin/pint --test`, `npx vue-tsc --noEmit`, `npm run build` and `npm run test:js` pass. Pest passes on `tests/Feature/Realtime tests/Feature/Tasks tests/Feature/Performance tests/Permissions` (give counts).
- [ ] AGENTS.md Flows block, the two decisions and the E.4 note are written.
- [ ] Report per phase: what changed, verified / not verified.

## Budget
L: about 120 tool calls. At the budget, stop and report what is done and what is not.

## Report
At most 40 lines. Include:
- the files changed;
- the channel name and its auth rule;
- the event class(es) and every dispatch point;
- queries per mutation for the fan-out;
- how `X-Socket-ID` reaches the server;
- how the hold, coalescing and skeleton suppression work;
- the proof numbers;
- any invented strings;
- anything in the code that contradicted this brief (especially decision 12-46 and E.4).

[ task list broken down into phases, each phase as a vertical slice, numbered ]
