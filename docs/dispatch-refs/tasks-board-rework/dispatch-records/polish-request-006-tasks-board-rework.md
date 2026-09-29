# Polish request 006 — Tasks board rework (toolbar, card, drawer, pan, live updates)

## What was just built
Four accepted dispatches (briefs 001–004, ledger lines 001–004) reworked the Tasks screen on both surfaces:
- **Toolbar.** The toolbar row has the view switcher and a `?scope=` dropdown (All tasks / My Tasks / Due Today / Overdue) on the left. Overdue only, Show archived, Manage tags, Add filter and New task sit on the right. The page title, description and search box are gone, and an sr-only h1 remains. The three sidebar items are gone, and `/…/my-tasks` 302s to the Tasks list.
- **Board card.** No project line, no tags, and avatars only (max 3 + `+N`, dashed circle when unassigned). A one-unit countdown label replaces the date, and a coloured priority flag replaces the arrow and word.
- **Board interactions.** A card click opens the List's detail drawer (`?detail=`). The footer hint is gone, and the left mouse button pans the board from empty background.
- **Live updates.** The "Every 20s" label and timer are gone. Updates are event-driven through `tasks.{user}`, with coalesced silent partial reloads that hold during a pan or drag, plus fallbacks.

Every item was accepted from the diff, with Pest, JS tests and Playwright checks at 375/768/1280. User's reference screenshots: `docs/dispatch-refs/tasks-board-rework/01…07*.png`.

## Files involved
- `resources/js/Components/Tasks/TaskFilterBar.vue`, `resources/js/Components/FilterBar.vue` (`layout="toolbar"`): the toolbar row
- `resources/js/Components/Tasks/TaskBoardCard.vue`, `DueCountdown.vue`, `TaskPriorityFlag.vue`, and the `--priority-*` tokens in `resources/css/app.css` / DESIGN.md: the card
- `resources/js/Components/Tasks/TaskBoard.vue`, `resources/js/lib/dragPan.ts`: the lanes, the pan cursor and the strip height
- `resources/js/Pages/{Admin,Employee}/Tasks/*.vue`: the toolbar slots

## What polish means here
- **Admin toolbar at 1280 px with the sidebar expanded wraps to two rows.** The content box is 976 px; the left group is 512 px and the right is 616 px. The user asked for one row at desktop width. Find a fit the user likes (tighter gaps, a shorter trigger, icon-only secondary buttons with tooltips, or accept two rows below ~1440). Show the options side by side.
- **Avatar stack.** The overlap was cut from 8 px to 4 px late, because initials were clipped, and that was never re-rendered. Look at 2, 3 and 5+ assignees, and at the dashed unassigned circle, in light and dark.
- **Card footer rhythm.** The order is avatars · countdown · checklist count · flag. Check spacing, the overdue red, and the flag's visual weight next to the countdown text at 375 px (single lane width) and 1280 px.
- **Pan affordance.** The `grab`/`grabbing` cursor on empty lane space and lane headers. Check that it doesn't feel like it fights text selection in lane headers.
- **Live-update transition.** A changed or new card may use a ≤150 ms transition that honours `prefers-reduced-motion`. Check it reads as "updated", not as a flash.

## What must not change
- `?scope=` values and their mapping (`TaskService::SCOPES`), the `/my-tasks` redirects, and the labels `All tasks`, `My Tasks`, `Due Today`, `Overdue`
- the countdown rule and its labels (`lib/dueCountdown.ts`, decision 12-68), and the priority token contrast (≥ 3:1 on `--card`)
- the drawer wiring (`?detail=`, no Inertia visit), the pan exclusion list and 4 px threshold, and the click swallowing after a pan
- the live-update flow F1 (`TaskChanged`, `tasks.{user}`, coalescing, holds and fallbacks; decision 12-69), and the events, channels and tests behind it
- other pages that use `FilterBar` (the default `stacked` layout)
