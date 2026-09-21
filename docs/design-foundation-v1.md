# Phase 0.5 — Design Foundation

**Status:** ✅ complete — 20 Sep 2026, closed at `8cf9ce2`
**Owner:** the session building GoodTechies HQ
**Runs:** after Phase 0, **before any Phase 1 screen is written**
**Estimated size:** ~2 days
**Version:** v1.1 (20 Sep 2026) — T1/T2/T3 re-derived from `GoodTechies siteicon 1.svg`

---

## 0. Status board — single source of truth

**Update this table as you work. It is the only place that says what is done.** Nothing else
in this file changes as work progresses — this table does.

`Spec version applied: v1.1` ← the session updates this line after reading a new changelog entry.

| Task | What | Status | Commit | Decisions logged |
|---|---|---|---|---|
| T1 | Brand tokens | ✅ done | `004bd04` | 0.5-8…0.5-14, 0.5-21…0.5-27 |
| T2 | Shell colour decision | ✅ done | `b6201de` | 0.5-1 |
| T3 | Logo asset | ✅ done | `2280e72` | 0.5-7 (**still open**), 0.5-15 |
| T4 | Sidebar: groups + collapse | ✅ done | `5e13eb9` | 0.5-3 |
| T5 | Top bar + command palette | ✅ done | `d7afa0f` | — |
| T6a | shadcn primitives installed | ✅ done | `2e652fd` | — |
| T6b | 8 base components | ✅ done | `b7732a4` | 0.5-6, 0.5-16 |
| T7 | Dashboard re-tier | ✅ done | `df00263` | 0.5-5, 0.5-18 |
| T8 | Chart library | ✅ done | `64813d5` | 0.5-4, 0.5-17 |
| M | Migrate every Phase 0/1 screen; delete `PageHeader` + `PlaceholderPanel` | ✅ done | `4849af4` | 0.5-19, 0.5-20 |
| C1 | Close-out: regenerate `DESIGN.md` with measured contrast | ✅ done | `41d9e50` | — |
| C2 | Close-out: run §6's light/dark, keyboard and 360px passes, fix what they found | ✅ done | `8cf9ce2` | 0.5-28…0.5-30 |
| C3 | Close-out: repair the decisions log, refresh `AGENTS.md` / `CLAUDE.md` / `PROGRESS.md` | ✅ done | — | — |

Status values: `⬜ not started` · `🟡 in progress` · `✅ done` · `🔴 blocked — <reason>` ·
`♻️ needs redo — spec vX.Y`

**Decision numbering.** T1 was written before §7's reserved numbers were noticed and reused
`0.5-1` … `0.5-7` for its token choices, so four numbers meant two things at once. Repaired at
close-out: §7's numbers stand (the code cites them — `app.css:75`, `navigation/types.ts:39`,
`app.css:57`, `TimerHeroCard.vue:9`) and T1's seven colliding rows moved to **0.5-21 … 0.5-27**
with their wording intact. `docs/decisions.md` now runs 0.5-1 … 0.5-30 with no duplicate and no
gap. Any older commit message citing a Phase 0.5 number below 8 means whatever §7 says it means.

**Rules for this table:**

1. Set a task to `🟡` when you start it, `✅` only when its acceptance test in §3 passes.
   Not when the code is written — when the test passes.
2. Paste the short commit SHA in the `Commit` column. One task, one commit, so the SHA is
   meaningful. If a task took several commits, paste the last one.
3. `🔴 blocked` must name what is blocking. A blocked task stops the phase — do not skip
   ahead to the next task, because the order in §3 is a dependency order.
4. Commit this file with the change. The table is worthless if it is updated at the end.

**Commit message format** — so `git log` alone answers "what got implemented":

```
phase-0.5(T4): collapse nav groups, move phase-gated rows to Coming soon

Borrowed: Attio (collapsible group sections), Gorgias (collapsed groups for
disabled entries). Ref docs/design-foundation-v1.md T4.
Decisions: 0.5-3.
```

`git log --oneline --grep='phase-0.5'` then prints the whole phase in order, and each line
says which task it closed. Any commit touching `resources/` during this phase that does not
carry a `phase-0.5(T#)` prefix is out of scope and should not exist.

---

## 0.1 Changelog — read this before resuming

If the `Spec version applied` line above is older than the **Version** in the header, someone
changed this document since you last read it. Read every entry below that is newer than your
applied version, do what its **Action** column says, then update the line.

### v1.1 — 20 Sep 2026

**Cause:** the client replaced the logo. `docs/design-refs/ChatGPT Image Sep 20 …png`
(a raster with an orange gradient) was deleted and
`docs/design-refs/GoodTechies siteicon 1.svg` added. v1's colours were sampled from the old
raster and are now wrong.

| Changed | From (v1) | To (v1.1) | Action if you already did this task |
|---|---|---|---|
| **T1** brand hue | `44` (sampled `#FF6A00`) | **`34.43`** (exact, `#F04E27`) | ♻️ Redo T1. Every orange in the app is the wrong hue. |
| **T1** `--primary` | `oklch(0.58 0.18 44)` `#c84f00` | **`oklch(0.56 0.205 34.4)`** `#CF3100` | ♻️ Redo |
| **T1** new token | — | **`--brand-ink`** `#0A0D12` — the logo's tile, now a real token | ♻️ Add |
| **T1** neutral hue | `250` | **`260.6`** — the logo ink's hue, so greys are family with the mark | ♻️ Redo the neutral ramp and the `todo` status pair |
| **T1** dark canvas | `oklch(0.2 0.02 237)` | **`#0A0D12`** exactly | ♻️ Redo |
| **T2** Option B | "not at `L 0.393`" | Rewritten — the logo *is* a dark-shell design, so B is now a real contender; values re-derived from the logo | Re-read before deciding. Does not invalidate a chosen Option A. |
| **T3** | assumed a wordmark + a mark existed | **There is no wordmark.** Task now builds the lockup from the mark + Inter Semibold, adds `mark-bare.svg`, and pins the 4.6% corner radius | ♻️ Redo |
| **T8** chart palette | "`--chart-1` becomes `--brand`" | Same, plus: no other series within 20° of hue 34.4 | Check existing palette |
| **§4** prohibited | — | Added: no recolouring/re-rounding the logo; no more than one orange per screen | Read |
| **§7** decisions | `0.5-2` open | `0.5-2` **closed** (values read from the SVG). New `0.5-7`: is the T3 lockup permanent? | Log 0.5-2 as closed |

**Net effect:** if you have not started, nothing to do. If T1 or T3 are `✅`, set them to
`♻️ needs redo — spec v1.1` and redo them before T2/T4/T5 — everything downstream inherits
the hue.

### v1 — 20 Sep 2026

Initial spec.

---

## 0.2 How to use this document

Read this file top to bottom before touching code. Then work the tasks in the order
given in §3 — they are dependency-ordered, not priority-ordered. Each task states the
files it owns, the exact change, the reference to look at, and an acceptance test.
Do not start a task until the one above it passes its acceptance test.

**When you finish a task**, append its decision rows to `docs/decisions.md` under a new
`## Phase 0.5` heading, using the existing table format, and mirror them into
`PROGRESS.md` exactly as Phase 0 did.

### This document's relationship to the other two

| Source | Owns | Authority |
|---|---|---|
| `docs/master-prompt-v1.md` + `PROGRESS.md` | What gets built, in what phase, with what data | Unchanged. This file adds nothing to scope. |
| **HQ Screen References** artifact (99 Mobbin screens, phases 00–12) | *Per-screen* patterns: what a Leave screen or a Payroll approval modal should contain | Authoritative for screen content, from Phase 1 onward. |
| **This file** | The *foundation* under all of them: colour tokens, the app shell, and the ~8 base components every screen will import | Authoritative for tokens, shell and base components. |

They agree everywhere except **one point**, resolved in Task 2 below: the screen-reference
artifact treats the navy shell as settled ("five named people share one navy shell"). This
file re-opens that as an explicit decision, because it was never decided — it was inherited
from a hand-written token pass in Phase 0 (`decisions.md` 0-8) when the shadcn-tokens MCP
was unreachable, and it is the single largest driver of how the product reads.

---

## 1. Why this phase exists

Phase 0 shipped a working shell, a token file and four pages. Twelve phases of screens are
about to be built on top of it. A review of the current state separates two kinds of finding:

**Will fix itself as phases land — ignore:**
empty stat cards showing `—`; no charts yet; `Table` primitive unused; thin dashboards;
`P2`…`P12` disabled rows becoming enabled.

**Will not fix itself, and six of them get *worse* as phases land — this phase:**

| # | Finding | At 100% complete |
|---|---|---|
| 1 | Brand mismatch: logo is black + orange, `--primary` is teal-navy, zero orange in the app | Unchanged — token-level |
| 2 | `AppWordmark.vue` is a square-in-square placeholder, not the logo | Unchanged |
| 3 | Shell colour was never actually decided (see §1 above) | Unchanged |
| 4 | Top bar holds a title and an avatar; no search, notifications, create, breadcrumb | Unchanged |
| 5 | 31 flat nav rows on Admin, no group collapse, no rail collapse, no pinning | **Worse** — all 31 enabled, all identical weight |
| 6 | Admin dashboard is 14 identical `StatCard`s in one grid | **Worse** — 14 live numbers, no hierarchy, nowhere for the eye to land |
| 7 | One elevation (`shadow-xs`) on every surface | Unchanged |
| 8 | Full `.dark` token set exists, no toggle anywhere | Unchanged — dead investment |
| 9 | No `DataTable` pattern; `ui/table` used on zero screens | **Worse** — ~20 list screens, ~20 hand-rolled tables |
| 10 | No `EmptyState`, `Skeleton` or toast system | **Worse** — re-invented per page |
| 11 | Missing primitives: Select, Combobox, Tabs, Popover, Calendar, Command, Sonner, Skeleton, Progress, Switch, Textarea, RadioGroup, Breadcrumb, Pagination, Collapsible | **Blocker** for Phase 1 |
| 12 | No chart library chosen; `--chart-1…5` consumed by nothing | **Worse** — risk of two libraries landing in different phases |

**Keep, do not touch:** the OKLCH token architecture, the `--status-*` scale, `--radius: 0.75rem`,
Inter, `tabular-nums` on metrics, the ARIA discipline in `AppSidebarNav.vue` and `Login.vue`,
TypeScript-first Vue. These are above average and the whole phase builds on them.

---

## 2. Non-goals

- No new routes, controllers, models or migrations. This phase is `resources/`, `app.css` and
  `public/` only, plus the shadcn-vue CLI.
- No Phase 1+ screens. Building the `DataTable` is in scope; using it on a Clients page is not.
- No redesign of Login/2FA flows beyond the layout change in Task 6. Their logic is done and tested.
- No change to `navigation/*.ts` *data*. Task 4 changes how it renders, not what it contains.
- Tests must keep passing. `tests/Feature/Surfaces` asserts on the shells — update assertions
  where markup legitimately changes, never by weakening them.

---

## 3. Work order

```
T1 Brand tokens ──┬── T2 Shell colour decision ──┬── T4 Sidebar
                  │                              └── T5 Top bar
                  └── T3 Logo asset
T6 Primitives + base components ── (independent, can run in parallel with T1–T5)
T7 Dashboard re-tier ── requires T6
T8 Chart library ── requires T1
```

---

### T1 — Brand tokens

**Why:** the app's primary colour has no relationship to the company's logo. Everything
downstream inherits this, so it goes first.

**Files:** `resources/css/app.css` only.

**Source of truth:** `docs/design-refs/GoodTechies siteicon 1.svg`. Values below are read
out of that file, not sampled from a raster. If the SVG changes, this table is regenerated —
nothing here may be eyeballed.

The mark contains **exactly three colours**, and no gradient:

| In the logo | Value | Role in the mark |
|---|---|---|
| `#0A0D12` | `oklch(0.1582 0.0118 260.6)` | The rounded tile the mark sits on |
| `#FFFFFF` | — | The `G` and `T` glyphs |
| `#F04E27` | `oklch(0.6455 0.2048 34.43)` | One dot. Nothing else. |

**Two things follow from this, and both are decisions, not observations.**

1. **`#0A0D12` is a brand token, not "black".** The brand ships its own near-black at hue
   260.6. Every neutral in the app is pulled to that hue so the greys are family with the
   logo instead of drifting blue. The old neutral hue `250` is replaced by `260.6`
   everywhere. The dark-mode canvas becomes `#0A0D12` exactly — not an approximation.

2. **The accent is one dot out of an entire mark.** That is roughly 2% of the logo's area.
   It is the brand's own statement of accent discipline, and it is the rule for the app:
   `--brand` marks the *one* thing that matters on a screen — the active nav row, the primary
   action, the series being read. If a screen has orange in three places, two of them are wrong.

**The contrast split.** `#F04E27` on white is **3.61:1** — under the 4.5:1 AA floor for body
text. So the brand splits into two tokens. **This split must not be "simplified" later:**

| Token | Value | Hex | Use | Contrast |
|---|---|---|---|---|
| `--brand` | `oklch(0.6455 0.2048 34.43)` | `#F04E27` | The logo colour, verbatim. **Graphics only:** active nav rail, the mark, chart series 1, focus ring, selection outline. Never a fill behind text. | n/a |
| `--brand-ink` | `oklch(0.1582 0.0118 260.6)` | `#0A0D12` | The logo tile. Dark-mode canvas, the mark's own background at every size, print. | 17.03:1 vs white ✅ |
| `--primary` | `oklch(0.56 0.205 34.4)` | `#CF3100` | Button fills, links, anything carrying text | **5.13:1** vs white ✅ AA |
| `--primary-hover` | `oklch(0.50 0.205 34.4)` | `#B22900` | Button hover / pressed | 6.54:1 ✅ |
| `--primary-foreground` | `oklch(0.99 0 0)` | `#FFF` | Text on `--primary` | — |
| `--brand-tint` | `oklch(0.97 0.022 34.4)` | `#FFF2EE` | Active nav row fill, selected row fill | n/a |
| `--brand-tint-strong` | `oklch(0.94 0.045 34.4)` | `#FFE4DE` | Hover on an already-selected row | n/a |

`--primary` sits two steps darker than `--brand` on the same hue. `oklch(0.58 …)` = `#D83503`
is the closest-to-logo value that still passes (4.72:1), but it leaves no margin at 13px, so
`0.56` is the shipped value. Do not raise it back toward the logo colour to "match better" —
that is what `--brand` is for.

**Dark mode.** Canvas is `--brand-ink` = `#0A0D12`:

| Token | Value | Hex | Contrast |
|---|---|---|---|
| `--background` (dark) | `oklch(0.1582 0.0118 260.6)` | `#0A0D12` | — |
| `--primary` (dark) | `oklch(0.72 0.174 34.4)` | `#FE7555` | 6.40:1 on canvas ✅ |
| `--primary-foreground` (dark) | `oklch(0.1582 0.0118 260.6)` | `#0A0D12` | 7.89:1 on the fill ✅ |
| `--brand` (dark) | `oklch(0.70 0.19 34.4)` | `#F76F4F` | 5.95:1 on canvas ✅ |
| `--brand-tint` (dark) | `oklch(0.26 0.045 34.4)` | — | active row fill |

**Neutral ramp** — all at hue `260.6`, chroma `0.006`:

```
0.985 #f8fafe   0.97 #f3f5f9   0.922 #e3e5e9   0.70 #9c9ea2   0.52 #67696c   0.30 #2c2e31
```

**Also in this task:**

1. **Elevation scale.** Add three tokens and use them everywhere instead of ad-hoc
   `shadow-xs` / `shadow-sm`:
   ```
   --elevation-flat:    none;                                  /* table rows, list items, nav */
   --elevation-raised:  0 1px 2px 0 oklch(0 0 0 / 0.05),
                        0 1px 3px 0 oklch(0 0 0 / 0.06);       /* cards, panels */
   --elevation-overlay: 0 10px 15px -3px oklch(0 0 0 / 0.10),
                        0 4px  6px -4px oklch(0 0 0 / 0.10);   /* dialog, popover, drawer, command */
   ```
   Expose as `--shadow-flat` / `--shadow-raised` / `--shadow-overlay` in `@theme inline`.
   Rule: one surface may not sit on another at the same elevation.

2. **Status triplets.** The `--status-*` scale is good but each badge currently needs three
   ad-hoc classes. Expand each of the six into `-fg` / `-bg` / `-border` so `StatusBadge`
   (T6) is a single lookup. Foreground values, all ≥5.8:1 on white:
   ```
   todo      fg oklch(0.48 0.006 260.6) #555f69  bg oklch(0.97 0.004 260.6)
   progress  fg oklch(0.45 0.120 240)  #005b88   bg oklch(0.96 0.025 240)
   review    fg oklch(0.48 0.130 75)   #7e5400   bg oklch(0.97 0.030 80)
   done      fg oklch(0.45 0.130 150)  #00672d   bg oklch(0.96 0.030 150)
   waiting   fg oklch(0.48 0.140 50)   #944300   bg oklch(0.97 0.030 50)
   cancelled fg oklch(0.50 0.200 27)   #bb0916   bg oklch(0.96 0.030 27)
   ```
   Borders: same hue, `L 0.88`, `C 0.04`. Mirror for `.dark` by swapping fg/bg lightness.

**Acceptance:**
- No `oklch(0.393 0.069 237)` remains as a fill anywhere in `app.css`.
- No neutral remains at hue `223` / `237` / `250`; every neutral is at `260.6`.
- `--brand` equals the SVG's `#F04E27` to the digit. If it does not, the wrong value was used.
- Every `--*-foreground` / `--*` pair used for text passes 4.5:1 (body) or 3:1 (≥18.66px bold).
  Check each pair once and record the ratios in `docs/decisions.md`.
- `npm run build` clean; no visual regression in Login (it will change colour — that is expected).

**Reference:** [Revolut Business](https://mobbin.com/screens/) — cited in your screen-reference
artifact for accent discipline; the principle is the one that matters here: *one* accent carries
every state, the rest is neutral.

---

### T2 — Shell colour: decide it, don't inherit it

**Why:** `--sidebar: oklch(0.393 0.069 237)` — a mid-lightness desaturated navy filling a
256px column, full height — was never chosen. It came from the hand-derived token pass
(`decisions.md` 0-8). It is the first thing anyone sees and it is currently doing the most
work to make the product read as a stock admin template.

There are two defensible answers, not one. **Pick one, write it into `decisions.md`, and
never mix them.**

#### Option A — Light neutral shell *(recommended)*

The chrome disappears; content and status colour carry everything.

```
--sidebar:                     oklch(0.985 0.006 260.6)  /* #f8fafe */
--sidebar-foreground:          oklch(0.30  0.006 260.6)  /* #2c2e31  — 12.9:1 ✅ */
--sidebar-foreground-muted:    oklch(0.52  0.006 260.6)  /* #67696c  — 5.2:1 ✅ group labels */
--sidebar-border:              oklch(0.922 0.006 260.6)  /* #e3e5e9  hairline */
--sidebar-accent:              var(--brand-tint)         /* #FFF2EE  active row fill */
--sidebar-accent-foreground:   oklch(0.30  0.006 260.6)
--sidebar-rail:                var(--brand)              /* #F04E27  3px left bar, active row only */
```

Active row = tint fill + 3px `--brand` left rail + `font-medium`. Nothing else.
Page canvas stays `--background`; cards stay white; the border between shell and content
is a hairline, not a colour change.

*Why recommended:* every product in your own category that reads as premium ships this —
[Remote](https://mobbin.com/screens/6ceeceaf-4579-484c-9885-3c4f71a83a0e),
[Deel](https://mobbin.com/screens/08d3947f-501e-446b-8545-970c7817876b),
[Attio](https://mobbin.com/screens/73c69573-7aa5-468b-accb-96e66de4ce24),
[Cloudflare](https://mobbin.com/screens/fe252f2b-62f5-4d73-9599-bea611c5463e),
[Plain](https://mobbin.com/screens/c56a33ee-ddd0-4856-be0f-f07d5659bb98),
[Twenty](https://mobbin.com/screens/b403408e-b983-4cd2-898c-708a2e962ad2),
[Bonsai](https://mobbin.com/screens/b18ba349-7ca1-4c8b-a4e5-fb97812aae37),
[Oyster](https://mobbin.com/screens/2acf12d5-ee94-46f2-8dfe-91e133f28618). It also costs
nothing later: a light shell never fights a chart, a status badge or a photo.

#### Option B — Dark shell, done properly

**The new logo makes this materially stronger than it was.** The mark *is* a near-black tile
with a white glyph and one orange dot — that is already a dark-shell design, shipped by the
brand. Option B is now "the app looks like its own logo", which is a real argument.

But it still is not the current navy. `L 0.393` desaturated blue is not `#0A0D12`. If you
take Option B, the values are the logo's:

```
--background:         oklch(0.1582 0.0118 260.6)  /* #0A0D12 — the logo tile, exactly */
--sidebar:            oklch(0.20   0.012  260.6)  /* rail one step LIGHTER than canvas */
--card:               oklch(0.22   0.012  260.6)
--sidebar-foreground: oklch(0.96   0.004  260.6)
--sidebar-accent:     oklch(1 0 0 / 8%)
--sidebar-rail:       var(--brand)                /* #F04E27 */
```

Two rules if you choose it: the whole app goes dark — a dark rail against a white canvas is
not a design, it is an unfinished one — and the mark drops its own tile on dark surfaces
(white glyph + orange dot directly on the canvas), otherwise you get a black square on a
black background.

**Still recommended: A.** The logo works on white — that is what a site icon is for — and an
internal tool spends its day next to spreadsheets, invoices, PDFs and photos of people, all
of which are light. Option B is defensible; Option A is lower-risk.

*Reference:* [Toggl Track](https://mobbin.com/screens/49e9fd09-eefc-4b2f-95aa-ca4fc97b8746)
is the one coloured shell in the sweep that works, and note what it does: near-black canvas,
one accent, everything else neutral.

**Acceptance:** one option chosen and recorded; `AppSidebar.vue` and `MobileNavSheet.vue`
both render it; light and dark both pass contrast; no screen mixes the two models.

---

### T3 — Real logo asset

**Files:** `public/brand/` (new), `resources/js/Components/AppWordmark.vue`.

**What exists:** `docs/design-refs/GoodTechies siteicon 1.svg` — a 1080×1080 site icon:
`#0A0D12` rounded tile, white `G` + `T`, one `#F04E27` dot. **There is no wordmark.** So this
task produces the mark from the file and *builds* the lockup; it does not invent a new mark.

1. Copy the SVG to `public/brand/mark.svg` unchanged. Normalise only the filename — the
   current one has a double space and a trailing `1`, which will break something eventually.
2. Produce `mark-bare.svg`: the same glyphs with the tile path removed, for dark surfaces
   where a black square on a black background would be visible as a seam.
3. **The lockup is the mark + "GoodTechies HQ" set in Inter Semibold**, at `--sidebar-foreground`,
   optical size matched so the cap-height of the text equals the cap-height of the `G` in the
   mark. Gap = 0.5× the mark's width. This *is* the wordmark until the client supplies one.
4. `AppWordmark.vue` takes `variant: 'lockup' | 'mark'` and `surface: 'light' | 'dark'`, and
   inlines the SVG (no `<img>` — the text must inherit `currentColor`). Delete the
   square-in-square placeholder markup and the `inverted` prop entirely.
5. **Do not recolour the mark to match the theme.** It keeps `#0A0D12` / white / `#F04E27` on
   light surfaces. On dark surfaces it switches to `mark-bare.svg`. A logo that changes colour
   per theme is not a logo.
6. **Scale the corner radius proportionally** — the tile is `r=50` on `1080`, i.e. **4.6%**.
   At a 28px mark that is ~1.3px. Do not substitute `rounded-lg`; it will be four times too round.
7. Favicon: `mark.svg` + 32px and 180px PNG fallbacks, replacing the Laravel default.

**Acceptance:** no placeholder geometry remains; the mark is legible at 20px in the collapsed
rail (check the dot is still visible — it is the smallest element and the first to disappear);
the lockup's text baseline aligns with the mark at 24px and 32px; favicon shows in a fresh tab;
`mark-bare.svg` has no black fill anywhere.

---

### T4 — Sidebar: groups, collapse, and hiding unbuilt work

**Why:** Admin has 31 rows in 7 static groups, 26 of them disabled with `P2`…`P12` badges.
Today that is hard to scan. When the phases land and all 31 are live with identical weight,
it becomes unusable.

**Files:** `resources/js/Components/Shell/AppSidebarNav.vue`, `AppSidebar.vue`,
`MobileNavSheet.vue`, `resources/js/navigation/types.ts` (types only — the `*.ts` nav data
does not change).

1. **Collapsible groups.** Each `NavGroup` label becomes a disclosure trigger with a chevron.
   Persist open/closed per group per role in `localStorage` (`hq.nav.<role>.<group>`).
   Default: the group containing the active route is open; on Admin, `My work` and `Company`
   open, the rest closed.
2. **Collapse-to-rail.** A toggle at the bottom of the sidebar switches between `w-64` and
   `w-14`. In rail mode: icons only, group labels become a separator, every row gets a
   `Tooltip` on the right. Persist in `localStorage`. Respect `prefers-reduced-motion`.
3. **Stop advertising unbuilt work.** Rows where `item.phase` is set are removed from their
   group and collected into one collapsed `Coming soon` disclosure pinned to the bottom of
   the sidebar, closed by default. Keep the existing `aria-disabled`, `sr-only` phase text and
   `title` — the accessibility work in the current component is good and must survive the move.
   *(If the client wants the roadmap visible, that is a Settings page, not the main rail.)*
4. **`My work` gets visual separation** on Admin: rendered above the first group label, with
   a separator under it, not as just another group.
5. Active row styling comes from T2 only. No second active treatment.

**References:**
[Attio](https://mobbin.com/screens/73c69573-7aa5-468b-accb-96e66de4ce24) — the closest single
model: light rail, `⌘K` at the top, collapsible `Favorites` / `Records` / `Lists`.
[Cloudflare](https://mobbin.com/screens/fe252f2b-62f5-4d73-9599-bea611c5463e) — grouped nav at
your item count.
[Remote](https://mobbin.com/screens/68c88a4c-0241-4007-928d-41f2a85f311f) — expanded group with
an active sub-item and a count badge.
Your screen-reference artifact's Phase 00 block also names Gorgias for the collapsed-group
pattern and Supabase for the icon-rail-plus-column split — both apply here.

**Acceptance:** Admin sidebar shows ≤ 12 rows on first load; every group opens and closes and
survives a reload; rail mode is keyboard-reachable and each icon has an accessible name;
`tests/Feature/Surfaces` still asserts the correct nav per role.

---

### T5 — Top bar

**Why:** `AppTopBar.vue` is 1.1 KB — a section title and a user menu. Every reference product
puts four things here, and two of them (search, notifications) are the primary way people
navigate an app this wide.

**Files:** `resources/js/Components/Shell/AppTopBar.vue`, new
`Components/Shell/GlobalSearch.vue`, `Components/Shell/QuickCreate.vue`,
`Components/Shell/NotificationBell.vue`, `Components/ui/breadcrumb/`.

Layout, left to right:

```
[mobile nav] [breadcrumb: Section / Sub]   ……   [⌘K search] [+ create] [🔔 n] [avatar ▾]
```

1. **Breadcrumb** replaces the flat section title. Derive from the nav tree + Inertia page props.
2. **Global search** — a `⌘K` / `Ctrl+K` button that opens the command palette. In this phase
   the palette is **navigation-only**: every enabled nav row across the user's role, fuzzy
   matched, arrow keys + Enter, Esc to close. Entity search (employees, projects, tasks) is
   wired in as each phase lands — leave the group headings in place and empty.
3. **Quick create** — a `+` dropdown. In this phase it renders only actions that exist; it is
   hidden entirely when that list is empty rather than showing a disabled menu.
4. **Notification bell** — bell + unread count. Phase 0.5 ships the component and an empty
   popover with an `EmptyState`; Phase 2 fills it.
5. **Theme toggle** lives in the `UserMenu`, not the top bar. Three states: Light / Dark / System,
   persisted, `System` default, applied before first paint (inline script in the Blade layout,
   otherwise it flashes).

**References:**
[Attio](https://mobbin.com/screens/73c69573-7aa5-468b-accb-96e66de4ce24) (`Quick actions ⌘K` placement),
[Vapi](https://mobbin.com/screens/593d7acd-2e16-4365-bcd6-02ce52f48f3b) — the palette to copy:
grouped `Actions` / `Recent` / `All Pages`, per-row shortcut hints, and a footer legend
(`↑↓ navigate · ↵ select · ⌘O …`). Your screen-reference artifact already picks this same
screen for Phase 10 global search — build it once, here.
[Mintlify](https://mobbin.com/screens/7c7ad31f-9dfe-4be7-83d7-6002fe31d4d0) — minimal grouped variant.
[Fey](https://mobbin.com/screens/ff52ac90-4d18-4765-98da-df1e362a5ee1) — teaching the shortcut to users.

**Acceptance:** `⌘K` opens from any page and any focus position except inside a text input;
palette is fully keyboard-operable and returns focus on close; bell and create degrade to
nothing rather than to disabled controls; theme survives reload with no flash.

---

### T6 — Primitives and the eight base components

**Why:** this is the highest-leverage task in the phase. These eight get imported by roughly
every screen in Phases 1–12. Built once here, they are consistent by construction; built
ad hoc per phase, nothing will ever match.

**T6a — install the missing shadcn-vue primitives**

```
select · combobox · tabs · popover · calendar · range-calendar · command
sonner · skeleton · progress · switch · textarea · radio-group
breadcrumb · pagination · collapsible · hover-card · toggle-group
```

Generate with the shadcn-vue CLI so import style matches the existing 16. Verify each lands
under `resources/js/Components/ui/<name>/` with an `index.ts`, and that `@lucide/vue` is used
(not `lucide-vue-next` — see `decisions.md` 0-9).

**T6b — the eight base components**

Build in this order; each is used by the next.

| # | Component | Contract | Reference |
|---|---|---|---|
| 1 | `StatusBadge.vue` | `status: StatusKey`, `size?: 'sm'\|'md'`. Reads the T1 triplets. No colour prop — the status *is* the colour. | [Deel](https://mobbin.com/screens/08d3947f-501e-446b-8545-970c7817876b) |
| 2 | `EmptyState.vue` | `icon`, `title`, `description?`, `variant: 'empty'\|'filtered'\|'error'`, `action?` slot. `filtered` must offer *Clear filters*. Replaces `PlaceholderPanel.vue`. | [Attio](https://mobbin.com/screens/73c69573-7aa5-468b-accb-96e66de4ce24), [Steep](https://mobbin.com/screens/b026462f-39da-445e-b451-36da6d487c49), [Canny](https://mobbin.com/screens/258b4dc5-b41f-4613-b778-c4fa4ab912b4) |
| 3 | `Skeleton` presets | `SkeletonTable`, `SkeletonCardGrid`, `SkeletonDetail`. Wire to Inertia's router events so navigation shows them. | — |
| 4 | `PageShell.vue` | `title`, `description?`, `breadcrumb?`, slots `actions` / `tabs` / `default`. Absorbs `PageHeader.vue` (keep its greeting + date logic verbatim — it is good). | [Remote](https://mobbin.com/screens/68c88a4c-0241-4007-928d-41f2a85f311f) |
| 5 | **`DataTable.vue`** | See below. The single most important component in the codebase. | [Deel](https://mobbin.com/screens/08d3947f-501e-446b-8545-970c7817876b), [Twenty](https://mobbin.com/screens/b403408e-b983-4cd2-898c-708a2e962ad2), [Navattic](https://mobbin.com/screens/ec4931ac-c3ca-46cd-8d07-39ffd02e22a9), [Aboard](https://mobbin.com/screens/f7141cbd-6d99-460e-9251-357489f6b500) |
| 6 | `FilterBar.vue` | Removable filter chips + `+ Add filter` + `Clear`. Serialises to the query string so a filtered view is a shareable URL. | [Navattic](https://mobbin.com/screens/ec4931ac-c3ca-46cd-8d07-39ffd02e22a9), [Aboard](https://mobbin.com/screens/f7141cbd-6d99-460e-9251-357489f6b500) |
| 7 | `DetailDrawer.vue` | Right slide-over on `Sheet`. `title`, `subtitle?`, slots `header-actions` / `default` / `footer`. Deep-linkable (`?detail=<id>`), Esc closes, focus trapped and restored. | [Remote](https://mobbin.com/screens/68c88a4c-0241-4007-928d-41f2a85f311f), [Deputy](https://mobbin.com/screens/b621c1c1-e6cb-4e4a-aed8-2905ac16425c) |
| 8 | `toast()` via Sonner | Success / error / loading→settled. `FlashMessage.vue` keeps handling server-side flash on load; `toast()` handles client actions. Do not duplicate one message in both. | — |

**`DataTable` required features** — all of them, now, not incrementally:

- Column defs: `key`, `header`, `cell` type (`text` `badge` `avatar` `date` `currency`
  `number` `actions`), `sortable`, `align`, `width`, `hideable`, `defaultHidden`
- Sortable headers (server-driven, sort state in the URL)
- Column visibility menu, persisted per table id in `localStorage`
- Row selection + a bulk-action bar that appears only on selection
- Sticky header, horizontal scroll, `tabular-nums` on every number and date column
- Server pagination with a rows-per-page select
- Per-row `⋯` `DropdownMenu`
- `loading` → `SkeletonTable`; `empty` → `EmptyState` (`empty` vs `filtered` chosen automatically)
- Row click → emits `row-click` (the page decides: drawer or navigate)
- Density toggle: comfortable / compact

**Acceptance:** a throwaway `/dev/datatable` demo page (deleted before merge, or gated behind
`APP_DEBUG`) exercising every feature above against seeded users; `vue-tsc` clean; keyboard
navigable; every base component works in both themes; `PlaceholderPanel.vue` and
`PageHeader.vue` are deleted, with their callers migrated.

---

### T7 — Re-tier the dashboards

**Why:** the Admin dashboard is 14 identical `StatCard`s in one 4-column grid. That is not a
layout — it is a list of variables rendered as boxes. With 13 of them blank it reads as
unfinished; with all 14 live it reads as noise. Your own design refs (Flowza, Moru) are both
tiered; the build flattened them.

**Files:** the three `Pages/*/Dashboard.vue`, `Components/StatCard.vue`.

**`StatCard` gains:** a `delta` slot (`+3 vs last week`, with direction colour from
`--status-done` / `--status-cancelled`), an optional `sparkline` slot, and an optional `href`
that makes the whole card a link. Keep `tabular-nums` and the `—` placeholder behaviour.

**Admin — four tiers, in this order:**

1. **Hero row:** 4 KPIs only, each with a delta. Active employees · Present today · Overdue tasks · Tasks due today.
2. **Needs your attention:** an action list — pending approvals, overdue items, leave awaiting
   decision. Each row is a link to the thing. *This is the most useful block on the page and
   it does not exist today.*
3. **Two charts, side by side:** tasks by status (donut) + 14-day attendance trend (area).
4. **This month:** the four finance figures, each with a sparkline.

Everything currently in the 14-tile grid that is not in tier 1 moves into the section it
belongs to, or is dropped. A number that nobody acts on does not earn a card.

*Reference:* [Remote](https://mobbin.com/screens/6ceeceaf-4579-484c-9885-3c4f71a83a0e) —
"Things to do" over a stat wall, with Quick actions beside it. This is the pattern for tier 2.

**Employee — one hero action, not a grid.** A single wide card at the top: the timer / clock-in
for `remote_timer` and `office_attendance` respectively, or today's focus task. Task counts
below it, at a smaller size. The current 5-across `StatCard` row is demoted.
*Reference:* [Toggl Track](https://mobbin.com/screens/49e9fd09-eefc-4b2f-95aa-ca4fc97b8746),
[Sweatpals](https://mobbin.com/screens/8b40935f-31fa-466a-a1b6-d19dabd42f07).
Note: your screen-reference artifact flags *where the timer lives in the employee layout* as a
Phase-1 blocking decision — resolve it here, in T7, and record it.

**Accountant — money first.** MTD income / expenses / payroll / operating result with sparklines,
then outstanding items. Not a generic tile grid.

**Rule for all three:** any card still showing `—` keeps the `Arrives in Phase N` sub-line, but
placeholder cards never occupy tier 1. Tier 1 is real numbers only, even if that means two cards.

**Acceptance:** no dashboard renders more than 6 cards at one visual weight; every tier-1 card
has a delta or an explicit reason it cannot; each dashboard has exactly one primary action.

---

### T8 — Chart library

**Why:** `--chart-1…5` are defined and consumed by nothing, and there is no charting dependency
installed. If this is not decided now, two libraries will land in different phases.

1. Pick one and record it. Recommendation: **unovis** (`@unovis/vue`) or **Recharts via a Vue
   wrapper** for the dashboard set; **ECharts** only if Phase 10's Gantt is going to be a chart
   rather than a custom component — decide that now, because it is the only thing that justifies
   the weight.
2. Build `Components/Charts/` wrappers — `AreaTrend`, `DonutBreakdown`, `BarCompare`, `Sparkline` —
   that read `--chart-1…5` from CSS custom properties so themes apply for free.
3. Reassign the chart palette: `--chart-1` becomes `--brand` (`#F04E27`) — the series being
   read. The rest stay categorical, distinguishable in both themes and under the common
   colour-vision deficiencies, and **none of them may sit within 20° of hue 34.4**, or the
   accent stops meaning anything.
4. Every chart needs: an accessible text summary or data table fallback, a no-data state using
   `EmptyState`, and a loading state.

**Acceptance:** one chart dependency in `package.json`; both T7 Admin charts render from real or
clearly-seeded data in both themes; `--chart-*` consumed by nothing except the wrappers.

---

## 4. Prohibited

Applies to this phase and to every phase after it.

- ❌ Gradient promo / upsell / marketing cards (the Flowza "People on time. Business on track."
      block). This is an internal tool; nobody in it needs to be sold to.
- ❌ Emoji in page headings — remove `Good morning! 👋` if it appears; the greeting text stays.
- ❌ Floating rounded dark sidebar (the Moru reference).
- ❌ Glassmorphism, neumorphism, decorative blurs, gradient text.
- ❌ More than one accent hue. `--brand` + neutrals + the six `--status-*`. That is the entire palette.
- ❌ Recolouring, re-rounding, stretching or re-drawing the logo. It has three colours and a
      4.6% corner radius; all three and the radius are fixed.
- ❌ Orange in more than one place on a screen. The logo spends it on one dot — so does the app.
- ❌ Animated number count-ups on KPIs.
- ❌ A disabled control where a hidden one would do.
- ❌ A second way to do anything this file already specifies — no bespoke table, no bespoke
      empty state, no bespoke badge.

---

## 5. Reference index

**Per-phase screen patterns (Phases 1–12):** the *HQ Screen References* artifact — 99 Mobbin
screens, one pattern each, with six products marked for a full tour (ClickUp, Bonsai, Deputy,
Deel, Midday, 1Password). That artifact is authoritative from Phase 1 onward. It is not
superseded by this file except on the shell-colour question resolved in T2.

**Foundation references (this phase)** — if the Mobbin MCP is available, these queries reproduce
the sweep; otherwise use the links inline above.

| Task | Query (platform: `web`) |
|---|---|
| T2 | `workforce admin dashboard with dense grouped left sidebar navigation and KPI stat cards` |
| T4 | `collapsible grouped sidebar navigation with icon rail and section labels` |
| T5 | `command palette overlay with search input and keyboard shortcut list` |
| T6 | `data table list view with filter chips, column sorting, row avatars and status badges` |
| T6 | `empty state with icon, headline and call to action button inside dashboard panel` |
| T7 | `leave request approval page with pending requests list and balance summary` |
| T7 | `employee attendance and time tracking screen with daily clock-in records table` |

Read each screen's *image*, not its metadata. Borrow one pattern per screen, named explicitly
in the commit message. Do not clone a whole screen.

---

## 6. Definition of done

- [x] T1–T8 acceptance tests all pass
- [x] `docs/decisions.md` has a `## Phase 0.5` table; `PROGRESS.md` mirrors it — the table was
      repaired at close-out: ten rows had been appended outside it and four numbers meant two
      things each. Now 0.5-1 … 0.5-30, no duplicates, no gaps
- [x] `DESIGN.md` regenerated from the new `app.css`, with every contrast ratio recorded — 52
      pairs, light and dark, measured by a script that parses `app.css` itself. **Note:** the file
      is at the repo root, not `docs/`, because `AGENTS.md` and `CLAUDE.md` point there
- [x] `npm run build`, `vue-tsc`, `pint` and the full Pest suite green (343 passed, 2252 assertions)
- [x] `PlaceholderPanel.vue` and `PageHeader.vue` deleted, callers migrated
- [x] Light and dark both verified on: Login, 2FA challenge, all three dashboards, Profile,
      Settings — plus a per-text-run contrast audit against the real composited backdrop: zero
      runs below AA on any of them
- [~] Keyboard-only pass: sidebar → rail toggle → `⌘K` → DataTable → DetailDrawer → Esc.
      Run, and it found the PIN-input focus trap on the 2FA screen (0.5-29) and the missing skip
      link (0.5-30); both fixed, 26 stops now walk clean. **`DetailDrawer` → Esc could not be
      exercised: the component has no caller yet** — no list passes `rowClickable`. The
      underlying `ui/sheet` primitive was tested through `MobileNavSheet` instead (opens, traps,
      Esc, returns focus), which is evidence about the primitive, not about `DetailDrawer` in place
- [x] 360px-wide pass on all three shells — one real defect, `/profile`'s Login history (0.5-28), fixed
- [x] No route, controller, model or migration changed by this phase

---

## 7. Open decisions this phase must close

Record each in `docs/decisions.md` with a one-line reason, matching the Phase 0 format.

| # | Decision | Needed by |
|---|---|---|
| 0.5-1 | Shell model: light neutral (A) or full dark (B) | T2 — blocks T4, T5 |
| 0.5-2 | ~~`--brand` exact value~~ **Closed.** `#F04E27` / `#0A0D12` read directly from `GoodTechies siteicon 1.svg`; neutrals moved to hue 260.6 to match the logo's ink | — |
| 0.5-7 | Whether the client will supply a real wordmark, or the Inter Semibold lockup from T3 is permanent | T3 |
| 0.5-3 | Where unbuilt nav items live: `Coming soon` disclosure, Settings page, or removed | T4 |
| 0.5-4 | Chart library, and whether Phase 10's Gantt is a chart or a custom component | T8 |
| 0.5-5 | Timer placement in the Employee layout: dashboard hero, persistent header, or both | T7 — flagged as Phase-1 blocking by the screen-reference artifact |
| 0.5-6 | Default list view for Tasks: List or Board | T6 — determines whether `DataTable` or Kanban is Phase 2's first build |
