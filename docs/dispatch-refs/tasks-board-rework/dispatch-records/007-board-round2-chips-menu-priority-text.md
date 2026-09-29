# Brief 007 — board-round2-chips-menu-priority-text

model: Opus 5.5 (claude-opus-5-5) · effort: high (session default; general-purpose fallback)
Role: dispatch-frontend (Vue work, judged by looking). Size S.

## Task
The user marked three more changes on the Board screenshot:
1. Remove the row of status count chips above the lanes (Backlog 4 · To do 4 · In progress 7 · …).
2. Remove the `⋯` menu from every board card.
3. Show the priority **word** next to the coloured flag on the card.

## User's words
```text
i have marrked what have to do do it in quick
```
Marks on the screenshot, verbatim:
- the chip row: "remove this";
- the `⋯` button: "here is the three dot option remove i[t]";
- the flag: "text also".

## Verbatim — use exactly
No product text was supplied. The priority words are the existing ones: `Urgent`, `High`, `Medium`, `Low`. List any other string you invent.

## Inputs
Files you may edit:
- `resources/js/Components/Tasks/TaskBoard.vue`: the status chip row (see the comment near line 425, "the counts come to the reader instead: the chips"). The `N tasks · N overdue` line on the left stays. Only the chip row goes, and its space goes with it.
- `resources/js/Components/Tasks/TaskBoardCard.vue`: the `⋯` DropdownMenu (`data-card-menu`, around line 189-200) and everything that only it used. That includes its emits such as `move-up` / `move-down` / moves, unless `TaskBoard.vue` still needs them for drag-and-drop. Check this before removing anything.
- `resources/js/Components/Tasks/TaskPriorityFlag.vue`: flag plus word. Choose a small text size and a token colour; the flag keeps its colour. The word also carries the meaning, so the `aria-label` must not say it twice.
- `TaskBoard.vue` line ~289 focuses `[data-card-menu]` after a move. Change it to focus the card itself (`[data-task-id="…"]`, which is `tabindex=0` now).
- Remove only the menu-related code and imports that become unused.

Current wrong behaviour: see `docs/dispatch-refs/tasks-board-rework/08-board-marked-round2.png`. **Open it with the Read tool**; the red marks carry the meaning. It is reference only, with no fidelity target.
Page URL: `http://127.0.0.1:8006/admin/tasks/board` and `/employee/tasks/board`.

## Audience
Board users. Card drag-and-drop between lanes and within a lane, card click to open the drawer, the pan, and the live update must all keep working. Status changes without drag are still possible from the drawer's status control. Say in the report what the removed menu offered that is now only reachable some other way, e.g. keyboard reordering inside a lane.

## Format
`AGENTS.md` conventions. Token classes only (DESIGN.md), `<script setup lang="ts">`, 4 spaces. Update the DESIGN.md §4.7 card row and the §4.8 line about the chips/menu if they describe what you removed, in DESIGN.md's own format.

## Out of scope — do NOT
- do NOT touch the List, Calendar or Gantt chip rows (Board only), the toolbar, the drawer, live-update code or the pan
- do NOT change the backend; do NOT add a dependency; do NOT commit; do NOT edit `.claude/`

## Knowledge
Read `AGENTS.md` first, then only the files above. Env: `/home/claude/goodtechies-hq`. Services run, and a dev server may already be on 8004, so use **8006**. Reverb may be on 8080; leave it. Playwright login helper: `/tmp/claude-0/-home-claude/1bcaf59a-d11a-5392-b281-872a22dab3a0/scratchpad/d4/proof.mjs` (the `login()` function). Scratch goes in `…/scratchpad/d7/`.

## Done means
- [ ] No status chip row on the Board, admin and employee (DOM check); the `N tasks · N overdue` line is still there
- [ ] No `⋯` button in any card (`[data-card-menu]` count 0)
- [ ] The flag shows the word, e.g. "High", with the same colour rules as before; no priority means no flag and no word
- [ ] Card drag to another lane still changes status (scripted, then reverted); card click still opens the drawer
- [ ] 375 / 768 / 1280 px: no overflow; screenshot paths listed
- [ ] `npx vue-tsc --noEmit`, `npm run build`, `npm run test:js` pass
- [ ] Report per phase

## Budget
S: about 25 tool calls.

## Report
At most 25 lines.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
