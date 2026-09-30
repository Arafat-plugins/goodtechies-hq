# DESIGN.md — the design source for UI work

Read this instead of reading `resources/css/app.css` and thirty component files: it lists every
token with its light and dark value and what it is for, every base component with its real
signature, and the things that are never allowed. `app.css` is still the source of truth — if a
value here and a value there ever disagree, `app.css` wins and this file is the bug.

Regenerated 2026-09-20 against `resources/css/app.css` at Phase 0.5 (T1 brand tokens + T8 chart
tokens). Every ratio below was measured from `app.css` by script, not estimated. The spec behind
it is `docs/design-foundation-v1.md`; the reasons are `docs/decisions.md` → Phase 0.5.

**§1.3, §1.5, §2 and §5 brought up to date in Phase 12's polish pass (2026-09-26)**, closing
POLISH-BACKLOG §E.1, §E.2, §E.3 and §E.17. Two rules in this file were true of the document and
false of the repo, both because nothing recomputed them: §2.2 measured `--ring` **opaque** while
the application rendered it at 50 % and 1.90:1, and §5.13's three-step elevation vocabulary had 85
`shadow-xs` call sites standing against it. The ring is now opaque everywhere, the shadows are the
three tokens, and **§2.2's ring rows and §2.4 are computed by
`tests/Unit/FocusRingContrastTest.php`** — it parses the oklch values out of `app.css`, composites,
and fails under 3:1. `tests/Unit/DesignVocabularyTest.php` greps the source for the classes §5.13
and §2.2 forbid. A ratio in a table nobody recomputes is exactly how this was wrong for eleven
phases.

**§4 brought up to date at the Phase 2 close-out (2026-09-23)** — §4.1's bell row, and the new
§4.8 for `FilePanel`, `TagManagerDialog`, `TaskDiscussionPanel`, `MyTasks`, `NotificationRow`,
the notification client and the Notification Center. **§1 and §2 were not re-measured and did
not need to be:** Phase 2 only *added* to `app.css` — the `backlog` (196) and `changes` (344)
token families — and moved no existing value, so every ratio in §2 still describes the token it
names. The two new families are already in §1.4, §2.1 and §2.2, measured when they landed.

---

## 1. Tokens

Every colour in this app is one of these variables. `app.css` exposes each of them to Tailwind
through `@theme inline`, so `--color-muted-foreground` is the class `text-muted-foreground`,
`--color-status-done-bg` is `bg-status-done-bg`, and so on. **Write the class, never the value.**

Hexes are the sRGB render of the oklch value (verified token by token — decision 0.5-13).
Shared conventions: the whole neutral ramp is hue `260.6` at chroma `0.006` — the logo ink's hue
(decision 0.5-23); the brand family is hue `34.4`.

### 1.1 Brand — hue 34.4, read out of the logo SVG

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--brand` | `oklch(0.6455 0.2048 34.43)` `#F04E27` | `oklch(0.70 0.19 34.4)` `#FE6845` | **Graphics only**: the active nav rail, the mark, chart series 1, the focus ring, a selection outline. Never a fill behind text (decision 0.5-22). |
| `--brand-ink` | `oklch(0.1582 0.0118 260.6)` `#0A0D12` | same | The logo's own tile — the dark canvas, the mark's background at every size, print. |
| `--brand-tint` | `oklch(0.97 0.022 34.4)` `#FFF0EB` | `oklch(0.26 0.045 34.4)` `#371B15` | The fill of an active or selected row. |
| `--brand-tint-strong` | `oklch(0.94 0.045 34.4)` `#FFE1D8` | `oklch(0.32 0.070 34.4)` `#512419` | Hover on a row that is *already* selected. |

The mark spends orange on one dot out of a whole logo. So does the app: `--brand` marks the one
thing that matters on a screen. Three oranges on one screen means two of them are wrong.

### 1.2 Neutral ramp — hue 260.6, chroma 0.006

Light ramp steps: `0.985 / 0.97 / 0.922 / 0.70 / 0.52 / 0.30`. Dark mirrors it. The canvas sits
above the ramp at `0.993 / 0.002` since 2026-09-29 (the client asked for a whiter page); the
sidebar keeps step `0.985`, which is what makes it the "one shade off the canvas" §1.7 describes.

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--background` | `oklch(0.993 0.002 260.6)` `#FCFDFE` (was `#F8FAFE` until 2026-09-29) | `oklch(0.1582 0.0118 260.6)` `#0A0D12` | The page canvas. Set once on `body`; a page does not repaint it. Deliberately **not** pure white: `--card` is, and a card keeps its hairline and `shadow-raised`, so it still reads as a panel. |
| `--foreground` | `oklch(0.30 0.006 260.6)` `#2C2E31` | `oklch(0.97 0.006 260.6)` `#F3F5F9` | Body text, headings, any value a person reads. |
| `--card` | `oklch(1 0 0)` `#FFFFFF` | `oklch(0.21 0.006 260.6)` `#17181B` | A panel one elevation above the canvas. Stays pure white in light mode (decision 0.5-25). |
| `--card-foreground` | `#2C2E31` | `#F3F5F9` | Text on a card. Same value as `--foreground`; use it inside a card so a future card recolour carries. |
| `--popover` | `oklch(1 0 0)` `#FFFFFF` | `oklch(0.21 0.006 260.6)` `#17181B` | Dropdown, popover, tooltip, command-palette surface. |
| `--popover-foreground` | `#2C2E31` | `#F3F5F9` | Text on those. |
| `--secondary` | `oklch(0.97 0.006 260.6)` `#F3F5F9` | `oklch(0.26 0.006 260.6)` `#222427` | The fill of a secondary button. |
| `--secondary-foreground` | `#2C2E31` | `#F3F5F9` | Its label. |
| `--muted` | `oklch(0.97 0.006 260.6)` `#F3F5F9` | `oklch(0.26 0.006 260.6)` `#222427` | A quiet surface: a table's header strip, a skeleton block, an icon medallion. |
| `--muted-foreground` | `oklch(0.52 0.006 260.6)` `#67696C` | `oklch(0.70 0.006 260.6)` `#9C9EA2` | Metadata, help text, axis labels, placeholder text. Passes 4.5:1 — it is quiet, not decorative. |
| `--accent` | `oklch(0.97 0.006 260.6)` `#F3F5F9` | `oklch(0.26 0.006 260.6)` `#222427` | A neutral hover wash on a row or menu item. |
| `--accent-foreground` | `#2C2E31` | `#F3F5F9` | Text on it. Neutral, not tinted (decision 0.5-27). |
| `--border` | `oklch(0.922 0.006 260.6)` `#E3E5E9` | `oklch(1 0 0 / 10%)` | Every hairline. `app.css` already applies `border-border` to `*`, so `border` alone is enough. |
| `--input` | `oklch(0.922 0.006 260.6)` `#E3E5E9` | `oklch(1 0 0 / 10%)` | A form field's boundary (`border-input`). |

### 1.3 Semantic

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--primary` | `oklch(0.56 0.205 34.4)` `#D12D00` | `oklch(0.72 0.174 34.4)` `#FE7555` | Anything carrying text: the primary button's fill, a link. Two lightness steps off `--brand` on the same hue — that gap is what makes it legal, do not close it. |
| `--primary-hover` | `oklch(0.50 0.205 34.4)` `#BC0A00` | `oklch(0.78 0.150 34.4)` `#FF9175` | Hover/pressed on a primary control. Note dark is **lighter** than `--primary`, not darker (decision 0.5-14). |
| `--primary-foreground` | `oklch(0.99 0 0)` `#FCFCFC` | `oklch(0.1582 0.0118 260.6)` `#0A0D12` | Text on a `--primary` fill. Only on `--primary` — see §6. |
| `--destructive` | `oklch(0.577 0.245 27.325)` `#E7000B` | `oklch(0.704 0.191 22.216)` `#FF6467` | Delete, revoke, "this cannot be undone", a required-field asterisk, an inline error. shadcn's default, deliberately unchanged (decision 0.5-12). |
| `--ring` | `var(--brand)` `#F04E27` | `var(--brand)` `#FE6845` | The focus ring, everywhere, and **always opaque** — `app.css` sets `outline-ring` and a control writes `focus-visible:ring-ring`. Never add an opacity modifier: at 50 % it measures 1.90:1 (§2.2). Do not restyle focus per component; the one exception is a control on a `--primary` fill, which uses `ring-primary-foreground` because `--ring` is 1.43:1 there. |
| `--radius` | `0.75rem` (12 px) | same | Exposed as `rounded-sm` (8) / `rounded-md` (10) / `rounded-lg` (12) / `rounded-xl` (16). Cards are `rounded-xl`, controls `rounded-md`. |
| `--font-sans` | `'Inter', ui-sans-serif, system-ui, sans-serif` | same | Applied to `body`. Inter is loaded from fonts.bunny.net in `resources/views/app.blade.php`. |

### 1.4 Status — eight keys, each a four-token set

The `StatusKey` union is
`backlog | todo | progress | review | changes | done | waiting | cancelled`, declared in
`Components/StatusBadge.vue`. Nothing outside that list is a status. A badge is one lookup:
`bg-status-<key>-bg text-status-<key>-fg border-status-<key>-border`, with the plain
`bg-status-<key>` as the dot.

Light: fg at `L 0.45–0.50`, bg at `L 0.96–0.97`, border at `L 0.88 C 0.04`.
Dark mirrors uniformly — fg `L 0.80`, bg `L 0.26`, border `L 0.38` — rather than swapping fg and
bg lightness, which would fail `cancelled` and collapse hue identity (decision 0.5-8).

| Key | Hue | `--status-<key>` (dot) | `-fg` (text) | `-bg` (fill) | `-border` |
| --- | --- | --- | --- | --- | --- |
| `backlog` | 196 | `#109C9E` / `#109C9E` | `#066263` / `#0FD8DA` | `#DDFAFA` / `#112929` | `#BAE0E0` / `#274949` |
| `todo` | 260.6 (neutral) | `#9C9EA2` / `#9C9EA2` | `#5C5E61` / `#BBBEC2` | `#F3F5F8` / `#232426` | `#C9D8F3` / `#364358` |
| `progress` | 240 | `#2382BA` / `#4FA8E1` | `#005B90` / `#6FC8FF` | `#E3F5FF` / `#19262F` | `#C1DCF0` / `#2E4556` |
| `review` | 75 | `#EBA42C` / `#EBA42C` | `#874F00` / `#EEB154` | `#FFF3DF` / `#2C2213` | `#E7D4BB` / `#4F3F2A` |
| `changes` | 344 | `#D34FA5` / `#D34FA5` | `#A9177E` / `#FE96D5` | `#FFECF6` / `#2F1E28` | `#ECCEDE` / `#523A48` |
| `done` | 150 | `#459F5D` / `#459F5D` | `#00682A` / `#7CD591` | `#E4F8E7` / `#19281C` | `#C6DFCA` / `#334937` |
| `waiting` | 50 | `#EB7C33` / `#EB7C33` | `#993F00` / `#FFA167` | `#FFF0E4` / `#302017` | `#EED1C1` / `#543C2F` |
| `cancelled` | 27 | `#E7000B` / `#FF6467` | `#BB0916` / `#FF8174` | `#FFEBE7` / `#311E1C` | `#F1CEC9` / `#563A37` |

*(Each cell is light / dark.)* The table is in lifecycle order, which is also the order the
Kanban board draws its columns in.

**Why `backlog` is 196 and `changes` is 344 (Phase 2).** The first six were drawn before the task
spec existed and left `BACKLOG` and `CHANGES REQUESTED` homeless. Mapping either onto an existing
tone was rejected: a board where `BACKLOG` and `TO DO` are the same colour is a board that lies.
A Viénot (1999) deuteranope simulation was run over every hue that clears this file's own
separation rules — ≥ 15° off each `--chart-*` hue, ≥ 20° off each existing status hue — with
chroma capped at what sRGB can actually render at the recipe's lightness. Only two families
survived: **196 (cyan)** and **344 (magenta)**. Violet (280–315) collapses onto `progress`, and
yellow-green (100–120) onto `review`, at ΔE < 1.5. A second red was rejected without measuring:
it separates under deutan but lies to normal vision next to `cancelled`.

196 and 344 are near-complementary, so at a shared lightness they would collapse into *each
other*. The separation therefore lives in `L`, inside the recipe's own band — `backlog` fg at
`L 0.45`, `changes` fg at `L 0.50` — which is the same move §1.6 makes for the chart palette, and
for the same reason: hue is what collapses under CVD, lightness is what survives.

Worst deutan ΔE for a pair involving a new key is **7.05 (dark, `backlog`/`changes`)**, and every
pair against the existing six is ≥ 8.78. For scale, the *existing* six contain a pair at ΔE 0.16
(light `review`/`waiting`) — the new keys are an order of magnitude better separated than the set
they joined, which is exactly why §6's "colour is never the only carrier" rule is not optional.

`--status-done-fg` and `--status-cancelled-fg` double as the
direction colours for a `StatCard` delta and a `Sparkline` tone — they are the only two "good /
bad" colours in the app, and nothing introduces a third.

### 1.4b Priority — four marks, no new hue (Board card, 29 Sep 2026)

The Board card's priority flag (`Tasks/TaskPriorityFlag.vue`). Classes `text-priority-<key>` and
`fill-priority-<key>`. Keys are `App\Support\TaskPriority`'s values. They are **marks** (a filled
`Flag` icon), so they are held to 3:1 on `--card`, not 4.5:1 — never use one as a text colour.
No hue here is new: each is one this palette already spends. `--priority-high` light is the one
new value — `--status-waiting` itself measures 2.81:1 on white — so it takes hue 50 at L 0.64.
The word is printed beside the flag (`Urgent`, `High`, `Medium`, `Low`) in `text-xs text-muted-foreground`, never in the priority token; the flag is `aria-hidden` and a `sr-only` " priority" completes the word for a screen reader, so it is read once: §5.6.

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--priority-urgent` | `var(--status-cancelled)` `#E7000B` | `var(--status-cancelled)` `#FF6467` | The Urgent flag (red). |
| `--priority-high` | `oklch(0.64 0.165 50)` `#D8670E` | `var(--status-waiting)` `#EB7C33` | The High flag (orange). |
| `--priority-medium` | `var(--status-progress)` `#2382BA` | `var(--status-progress)` `#4FA8E1` | The Medium flag (blue). |
| `--priority-low` | `var(--muted-foreground)` `#67696C` | `var(--muted-foreground)` `#9C9EA2` | The Low flag (grey). |

No priority → no flag. Measured in §2.2.

### 1.5 Elevation — three steps, and only three

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--elevation-flat` / `shadow-flat` | `0 0 #0000` | `0 0 #0000` | Table rows, list items, nav rows. Things that sit *in* a surface. A **transparent** shadow, never the keyword `none` — see below. |
| `--elevation-raised` / `shadow-raised` | `0 1px 2px 0 oklch(0 0 0/.05), 0 1px 3px 0 oklch(0 0 0/.06)` | `…/.30`, `…/.36` | Cards and panels — one step above the canvas. |
| `--elevation-overlay` / `shadow-overlay` | `0 10px 15px -3px oklch(0 0 0/.10), 0 4px 6px -4px oklch(0 0 0/.10), 0 0 8px 0 oklch(0 0 0/.06)` | `…/.50`, `…/.50`, `0 0 12px 0 oklch(0 0 0/.45)` | Dialog, popover, drawer, command palette, toast. The third (all-round) layer was added 2026-09-29 — see *Overlay panels carry no border* below. |

Dark carries its own deeper values because a 5 % black shadow is invisible on `#0A0D12`
(decision 0.5-10). **The rule: one surface may not sit on another at the same elevation.**

**Overlay panels carry no border; elevation only (2026-09-29).** The client asked for the border to
go from every dropdown and hover panel. The panels are generated files, so the rule is one
unlayered block at the end of `app.css`, keyed on each primitive's `data-slot`:
`dropdown-menu-content`, `dropdown-menu-sub-content`, `select-content`, `popover-content`,
`hover-card-content`, `tooltip-content`, `combobox-list` → `border-width: 0`. Unlayered, so the
generated `border` utility cannot win it back. Bespoke overlays drop it at the call site:
`Messages/MentionPicker.vue`'s panel and `Shell/GlobalSearch.vue`'s palette (`border-0` on its
`DialogContent`; other dialogs keep theirs). `Command` draws none. Separators and header/footer
rules *inside* a panel stay. Without the hairline, the old two layers only fell *below* a panel, so
a white menu on a white card (and `--popover` on `--card` in dark, which are the same colour) had
no side edges: `--elevation-overlay` gained an all-round blur layer in both themes. The tooltip is
unchanged — it never had a border or a shadow, and its inverted `--foreground` fill separates it.

**Why `shadow-xs` kept coming back.** `app.css` adds these three to the `--shadow-*` namespace and
never clears Tailwind's own scale, so `shadow-xs` / `shadow-sm` / `shadow-md` / `shadow-lg` all
still compile. Nothing broke when an agent wrote one, so three agents in three slices matched their
neighbours instead of the token set — 85 `shadow-xs` call sites, and `Components/ui/card/Card.vue`
itself generated with `shadow-sm`. Phase 12's polish pass swept them: **the card's elevation now
lives on `Card.vue` as `shadow-raised`**, which is why a page writes `<Card class="min-w-0 gap-4">`
and no shadow at all; an overlay primitive is `shadow-overlay`; a control (input, outline button,
toggle, checkbox) is `shadow-flat`. `tests/Unit/DesignVocabularyTest.php` fails on a reintroduction,
because the class working is not the same as the class being allowed.

**And `--elevation-flat` is `0 0 #0000`, not `none`, because `none` deleted the focus ring.**
Tailwind v4 composes one `box-shadow` out of five custom properties —
`var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow),
var(--tw-ring-shadow), var(--tw-shadow)` — and the keyword `none` is legal only as the *sole* value
of `box-shadow`. With `--elevation-flat: none` the whole list was invalid CSS, the declaration was
dropped, and the computed value fell back to `none`, taking `--tw-ring-shadow` with it. Every
control carrying `shadow-flat` — input, textarea, select, native select, checkbox, radio, switch,
toggle, outline button, pin-input slot — then painted **no focus ring at all**, with
`:focus-visible` matching and `focus-visible:ring-3 focus-visible:ring-ring` sitting on the element.
Measured in Chromium on 2026-09-26: `getComputedStyle(el).boxShadow === 'none'`. This is the second
way the ring vanishes and it has nothing to do with opacity, so §2.2's ratios cannot catch it;
`tests/Unit/DesignVocabularyTest.php` asserts no `--elevation-*` is the keyword `none`. `0 0 #0000`
is what Tailwind's own `shadow-none` sets, for this reason.

### 1.6 Chart — T8 / decision 0.5-4

Five hues: `34.43` (brand) / `131` / `212` / `265` / `329`. Each of the four categoricals is at
least 20° off the brand and at least 15° off every status hue, so a category can never be
mistaken for a status. Lightness is staggered, not flat, because hue is what collapses under
deuteranopia and protanopia. Dark is re-stepped for `#17181B`, not a flip of light.

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--chart-1` | `var(--brand)` `#F04E27` | `var(--brand)` `#FE6845` | Series 1 — **the number being read**. A single-series chart uses only this. |
| `--chart-2` | `oklch(0.59 0.16 131)` `#5A8F04` | `oklch(0.78 0.20 131)` `#88D029` | Categorical slot 2. Carries no meaning of its own. |
| `--chart-3` | `oklch(0.65 0.11 212)` `#15A0B6` | `oklch(0.78 0.13 212)` `#26CDE7` | Categorical slot 3. |
| `--chart-4` | `oklch(0.56 0.21 265)` `#3667ED` | `oklch(0.70 0.155 265)` `#6F9AFE` | Categorical slot 4. |
| `--chart-5` | `oklch(0.57 0.21 329)` `#B538B2` | `oklch(0.70 0.20 329)` `#DF68DA` | Categorical slot 5. |

Under CVD simulation the worst pair separation is ΔE 7.0 (deutan, light) / 7.3 (deutan, dark) —
inside the 6–8 band, which is only legal with a second encoding. That is why every wrapper that
draws more than one series ships labels and a 2 px `--card` gap between adjacent fills.
A chart never reads a colour from a class: it asks `Charts/chartTokens.ts` (§5.4).

### 1.7 Sidebar — T2 / decision 0.5-1, Option A

The shell recedes: it is the top of the neutral ramp, one shade off the canvas, separated from
content by a hairline and **not** by a colour change. The one orange on the screen is the active
row's rail. This is a full theme in both modes, not a light design with a dark afterthought.

| Variable | Light | Dark | Use it for |
| --- | --- | --- | --- |
| `--sidebar` | `oklch(0.985 0.006 260.6)` `#F8FAFE` | `oklch(0.20 0.012 260.6)` `#13161C` | The rail and the mobile drawer surface. |
| `--sidebar-foreground` | `oklch(0.30 0.006 260.6)` `#2C2E31` | `oklch(0.96 0.004 260.6)` `#F0F2F4` | Nav row labels. |
| `--sidebar-foreground-muted` | `oklch(0.52 0.006 260.6)` `#67696C` | `oklch(0.70 0.006 260.6)` `#9C9EA2` | Group labels, the role line, "Coming soon" phase badges. |
| `--sidebar-primary` | `var(--brand)` `#F04E27` | `var(--brand)` `#FE6845` | Graphics in the shell. Never behind text. |
| `--sidebar-primary-foreground` | `oklch(0.99 0 0)` `#FCFCFC` | `oklch(0.1582 0.0118 260.6)` `#0A0D12` | Reserved pair; the shell does not currently render text on `--sidebar-primary` and must not start (§3, §6). |
| `--sidebar-accent` | `var(--brand-tint)` `#FFF0EB` | `var(--brand-tint)` `#371B15` | The active row's fill. |
| `--sidebar-accent-foreground` | `#2C2E31` | `#F0F2F4` | The active row's label. |
| `--sidebar-border` | `oklch(0.922 0.006 260.6)` `#E3E5E9` | `oklch(1 0 0 / 10%)` | The hairline between shell and content **and** the neutral hover wash on a non-active row. |
| `--sidebar-rail` | `var(--brand)` `#F04E27` | `var(--brand)` `#FE6845` | The 3 px left bar on the active row. The single active treatment — there is no second one. |
| `--sidebar-ring` | `var(--brand)` | `var(--brand)` | Focus inside the shell; same ring as everywhere else. |

Active row = `--sidebar-accent` fill + 3 px `--sidebar-rail` + `font-medium`. Hover on a
non-active row is `--sidebar-border`; hover on the active row is `--brand-tint-strong`.

### 1.7b People and scrollbars — messaging polish (28 Sep 2026)

**`--person-1` … `--person-8`** (fill) and **`--person-N-fg`** (initials and the person's name):
one tint per person, chosen by user id in `Components/Messages/people.ts` (`personTone(id)`), so
the same person is the same colour on every screen and after every reload. Eight hues at L 0.93 /
C 0.055 light and L 0.33 dark, none within 40° of `--brand` (your own rows keep `--brand-tint`).
Used on message avatars and author names, the conversation members list, the new-message picker
and task assignee avatars. The name is always written beside the colour — colour never carries
identity alone.

| Token pair | Light | Dark |
| --- | --- | --- |
| `--person-N-fg` / `--person-N` (worst of eight) | 6.10:1 ✅ | 8.24:1 ✅ |
| `--person-N-fg` / `--card` (worst of eight) | 7.35:1 ✅ | 11.69:1 ✅ |

**`--scrollbar-thumb` / `--scrollbar-thumb-hover`**: a thin neutral thumb on a transparent track,
set once in `@layer base` for every scroller (standard `scrollbar-color` plus the
`::-webkit-scrollbar` fallback). `color-scheme: light | dark` on `:root` / `.dark` makes the
browser's own controls follow the theme — without it dark mode drew a white scrollbar.

### 1.8 Spacing, type and numbers

- **Spacing:** Tailwind's 4 px scale only. Page padding `p-4 md:p-6`; card padding `p-4` compact /
  `p-6` default; `gap-4` between grid cells, `gap-2` inside a row. Control height `h-9`, top bar
  `h-14`, sidebar `w-64` (`w-14` railed).
- **Type:** body `text-sm`; page title `text-2xl font-semibold tracking-tight`; card title
  `text-sm font-medium`; stat number `text-3xl font-semibold tabular-nums`; metadata
  `text-xs text-muted-foreground`.
- **Numbers:** every number, money and date column is `tabular-nums`. `DataTable` applies it
  automatically for the `number`, `currency` and `date` cell types.

---

## 2. Measured contrast

WCAG 2.x relative luminance on sRGB after gamut clipping, computed by parsing the oklch values
straight out of `app.css`. Translucent tokens (`oklch(1 0 0 / 10%)`) are composited over the
surface named in the row. Body text is held to 4.5:1; graphics, marks and boundaries to 3:1.

**A ratio here has to be a number something recomputes.** §2.2's ring rows said 3.45:1 ✅ for
eleven phases while the screen showed 1.90:1, because the table measured the token and the
application rendered it at 50 % — and a markdown table does not notice. The focus-indicator rows
and §2.4 are now computed by `tests/Unit/FocusRingContrastTest.php`, which reads `app.css`, follows
the `var()` chains, reads the opacity off the global `@apply … outline-ring` rule, composites and
fails under 3:1. The other rows were measured by the Phase 0.5 script and are unchanged.

The Phase 12 helper quantises each channel to 8 bits before compositing — which is what a screen
does — where the Phase 0.5 script stayed in floats, so a few rows differ by up to 0.03 (`--primary`
/ `--primary-foreground` reads 5.01 here and 4.99 below). Neither is wrong and no row changes side
of a threshold; the older figures are left as measured rather than churned.

### 2.1 Text on a surface — 4.5:1

**The light `--background` rows were re-measured on 2026-09-29** when the canvas went from `#F8FAFE`
to `#FCFDFE`, with the Phase 12 8-bit helper (`tests/Support/Colour.php`); every one moved up
(the canvas only got lighter behind dark text and marks). No dark row changed.

| Pair | Light | Dark |
| --- | --- | --- |
| `--foreground` / `--background` | 13.37:1 ✅ | 17.84:1 ✅ |
| `--foreground` / `--card` (= `--popover`) | 13.63:1 ✅ | 16.25:1 ✅ |
| `--foreground` / `--muted` (= `--secondary`, `--accent`) | 12.50:1 ✅ | 14.25:1 ✅ |
| `--muted-foreground` / `--background` | 5.41:1 ✅ | 7.28:1 ✅ |
| `--muted-foreground` / `--card` | 5.51:1 ✅ | 6.63:1 ✅ |
| `--muted-foreground` / `--muted` | 5.05:1 ✅ | 5.82:1 ✅ |
| `--primary-foreground` / `--primary` | 4.99:1 ✅ | 7.31:1 ✅ |
| `--primary` / `--background` (link on the canvas) | 5.05:1 ✅ | 7.31:1 ✅ |
| `--primary` / `--card` (link on a card) | 5.13:1 ✅ | 6.66:1 ✅ |
| `--primary-hover` / `--background` | 6.48:1 ✅ | 8.85:1 ✅ |
| `--primary-hover` / `--card` | 6.62:1 ✅ | 8.06:1 ✅ |
| `--destructive` / `--background` (`text-destructive`) | 4.68:1 ✅ | 6.73:1 ✅ |
| `--destructive` / `--card` | 4.76:1 ✅ | 6.13:1 ✅ |
| white / `--destructive` — **raw token** | 4.76:1 ✅ | 2.89:1 ❌ |
| white / `--destructive`/60 over `--card` — **as shadcn renders it in dark** | — | 6.00:1 ✅ |
| `--foreground` / `--brand-tint` (active/selected row) | 12.31:1 ✅ | 14.45:1 ✅ |
| `--foreground` / `--brand-tint-strong` (hover on selected) | 11.06:1 ✅ | 11.96:1 ✅ |
| white / `--brand-ink` (the mark's glyphs on its tile) | 19.46:1 ✅ | 19.46:1 ✅ |
| `--sidebar-foreground` / `--sidebar` | 13.06:1 ✅ | 16.12:1 ✅ |
| `--sidebar-foreground-muted` / `--sidebar` | 5.28:1 ✅ | 6.78:1 ✅ |
| `--sidebar-accent-foreground` / `--sidebar-accent` | 12.31:1 ✅ | 14.03:1 ✅ |
| `--sidebar-foreground` / `--sidebar-border` (hover wash) | 10.83:1 ✅ | 13.65:1 ✅ |
| `--status-backlog-fg` / `--status-backlog-bg` | 6.54:1 ✅ | 8.68:1 ✅ |
| `--status-todo-fg` / `--status-todo-bg` | 5.99:1 ✅ | 8.32:1 ✅ |
| `--status-progress-fg` / `--status-progress-bg` | 6.43:1 ✅ | 8.38:1 ✅ |
| `--status-review-fg` / `--status-review-bg` | 6.08:1 ✅ | 8.20:1 ✅ |
| `--status-changes-fg` / `--status-changes-bg` | 5.94:1 ✅ | 7.89:1 ✅ |
| `--status-done-fg` / `--status-done-bg` | 6.26:1 ✅ | 8.65:1 ✅ |
| `--status-waiting-fg` / `--status-waiting-bg` | 6.18:1 ✅ | 7.87:1 ✅ |
| `--status-cancelled-fg` / `--status-cancelled-bg` | 5.79:1 ✅ | 6.47:1 ✅ |

The `white / --destructive` dark row is a ❌ that never reaches a screen: shadcn's destructive
button and badge render `bg-destructive/60` over `--card` in dark, which is the row beneath it at
6.00:1 (decision 0.5-12). Do not "fix" the raw token; do not bypass the `/60`.

### 2.2 Graphics, marks and boundaries — 3:1

| Pair | Light | Dark |
| --- | --- | --- |
| `--brand` / `--background` | 3.54:1 ✅ | 6.73:1 ✅ |
| `--brand` / `--card` | 3.61:1 ✅ | 6.13:1 ✅ |
| `--sidebar-rail` / `--sidebar` | 3.45:1 ✅ | 6.26:1 ✅ |
| `--sidebar-rail` / `--sidebar-accent` (rail on the active row's fill) | 3.26:1 ✅ | 5.45:1 ✅ |
| `--chart-1` / `--card` | 3.61:1 ✅ | 6.13:1 ✅ |
| `--chart-2` / `--card` | 3.91:1 ✅ | 9.34:1 ✅ |
| `--chart-3` / `--card` | 3.11:1 ✅ | 9.23:1 ✅ |
| `--chart-4` / `--card` | 4.88:1 ✅ | 6.52:1 ✅ |
| `--chart-5` / `--card` | 5.01:1 ✅ | 6.02:1 ✅ |
| `--status-cancelled` dot / `--status-cancelled-bg` | 4.14:1 ✅ | 5.43:1 ✅ |
| `--status-progress` dot / `--status-progress-bg` | 3.76:1 ✅ | 5.89:1 ✅ |
| `--status-changes` dot / `--status-changes-bg` | 3.41:1 ✅ | 4.06:1 ✅ |
| `--status-backlog` dot / `--status-backlog-bg` | 3.05:1 ✅ | 4.61:1 ✅ |
| `--status-done` dot / `--status-done-bg` | 2.97:1 ❌ | 4.65:1 ✅ |
| `--status-waiting` dot / `--status-waiting-bg` | 2.53:1 ❌ | 5.55:1 ✅ |
| `--status-todo` dot / `--status-todo-bg` | 2.45:1 ❌ | 5.82:1 ✅ |
| `--status-review` dot / `--status-review-bg` | 1.94:1 ❌ | 7.35:1 ✅ |
| `--priority-urgent` / `--card` (flag) | 4.77:1 ✅ | 6.15:1 ✅ |
| `--priority-high` / `--card` (flag) | 3.58:1 ✅ | 6.31:1 ✅ |
| `--priority-medium` / `--card` (flag) | 4.22:1 ✅ | 6.78:1 ✅ |
| `--priority-low` / `--card` (flag) | 5.51:1 ✅ | 6.62:1 ✅ |
| `--status-*-border` / `--card` (all eight) | 1.41–1.46:1 ❌ | 1.73–1.80:1 ❌ |
| `--border` / `--background` (hairline) | 1.24:1 ❌ | 1.27:1 ❌ |
| `--border` / `--card` (hairline) | 1.26:1 ❌ | 1.33:1 ❌ |
| `--input` / `--card` (field boundary) | 1.26:1 ❌ | 1.33:1 ❌ |

#### The focus indicator — measured as rendered, not as declared

Every row below is composited the way the browser composites it and recomputed by
`tests/Unit/FocusRingContrastTest.php`. WCAG 2.2 §1.4.11 asks 3:1 of a focus indicator.

| Focus indicator, as rendered | Light | Dark |
| --- | --- | --- |
| `--ring` / `--background` | 3.54:1 ✅ | 6.75:1 ✅ |
| `--ring` / `--card` (= `--popover`) | 3.61:1 ✅ | 6.15:1 ✅ |
| `--ring` / `--muted` (= `--secondary`, `--accent`) | 3.30:1 ✅ | 5.39:1 ✅ |
| `--ring` / `--sidebar` | 3.45:1 ✅ | 6.28:1 ✅ |
| `--ring` / `--brand-tint` (= `--sidebar-accent`, the selected row) | 3.25:1 ✅ | 5.47:1 ✅ |
| `--primary-foreground` / `--primary` (the DM bubble's own ring) | 5.01:1 ✅ | 7.31:1 ✅ |
| `--destructive` / `--background` (destructive button, badge, invalid field) | 4.68:1 ✅ | 6.74:1 ✅ |
| `--destructive` / `--card` | 4.77:1 ✅ | 6.15:1 ✅ |
| `--destructive` / `--muted` | 4.37:1 ✅ | 5.39:1 ✅ |
| `--ring` / `--accent` — the **inset** ring on a highlighted menu, command or select item | 3.30:1 ✅ | 5.39:1 ✅ |
| `--destructive` / `--accent` — the same ring on a destructive menu item | 4.37:1 ✅ | 5.39:1 ✅ |

And the same tokens at the opacities the repo shipped from Phase 0.5 to Phase 12, kept because they
are the whole of POLISH-BACKLOG §E.1 and because a row that only says ✅ teaches nobody anything:

| What shipped until 2026-09-26 | Light | Dark |
| --- | --- | --- |
| `--ring`/50 / `--background` — `outline-ring/50`, `focus-visible:ring-ring/50` | 1.90:1 ❌ | 2.45:1 ❌ |
| `--ring`/50 / `--card` | 1.92:1 ❌ | 2.43:1 ❌ |
| `--ring`/50 / `--muted` | 1.86:1 ❌ | 2.31:1 ❌ |
| `--ring`/50 / `--primary` — a control in a DM's own bubble | 1.20:1 ❌ | 1.04:1 ❌ |
| `--destructive`/20 light, /40 dark / `--background` — shadcn's destructive and `aria-invalid` ring | 1.43:1 ❌ | 1.96:1 ❌ |

Three things that follow from those rows, all of them now in the code:

- **The ring is opaque everywhere.** `app.css` applies `outline-ring`, and a control writes
  `focus-visible:ring-ring`. An opacity modifier on a focus ring fails
  `tests/Unit/DesignVocabularyTest.php`.
- **Text-entry fields paint no focus border and no ring (2026-09-30, decision 12-72).** The client
  asked for the orange focus border to go from every field. An unlayered block at the end of
  `app.css`, keyed on `data-slot` (`input`, `textarea`, `select-trigger`, `native-select`,
  `pin-input-slot`, `command-input`), clears `--tw-ring-shadow` and holds the border at `--input`
  (`aria-invalid` keeps `--destructive`); bespoke fields (composer pill, MentionPicker, global
  search, file input, profile timezone select, subtask add row) dropped their ring classes at the
  call site. The caret is the indicator. Buttons, links, checkboxes, switches, tabs, menu items and
  cards are untouched and keep the opaque `--ring` above.
- **A menu item's highlight is not a focus indicator, so it also carries a ring.** shadcn ships
  `DropdownMenuItem`, its checkbox/radio/sub-trigger siblings, `SelectItem`, `CommandItem` and
  `ComboboxItem` with `outline-hidden` and nothing but `focus:bg-accent` to say where the keyboard
  is. `--accent` on `--popover` is **1.05:1** in both themes, so arrowing down a menu moved an
  indicator nobody could see, and it was invisible to §E.1's sweep because there was no ring class
  to find. Each of those seven now adds `ring-2 ring-ring ring-inset` on the same state, keeping
  `bg-accent` as the second carrier (§6). The ring is **inset** because the items are flush inside
  a `p-1` container: an outside ring would overlap its neighbour and clip at the edge. Inset, it is
  drawn over the item's own `--accent` fill at 3.30:1 / 5.39:1, and on a destructive item over
  `--destructive` at 4.37:1 / 5.39:1.
  **It is the keyboard's ring, and since 2026-09-29 only the keyboard gets it.** reka highlights an
  item for the pointer too, so every mouse hover framed the row in orange, and a Select opened by a
  click framed its selected row — the client's "border on hover". `app.css` now clears
  `--tw-ring-shadow` on an item that is `:hover`ed, and on every item while
  `<html data-input-modality="pointer">` (`lib/inputModality.ts`, set on `pointerdown`, flipped
  back on any `keydown`). `:focus-visible` could not do it: Chromium reports the click-opened
  Select's row as focus-visible. The `bg-accent` fill still marks the pointer's row; the first
  arrow key brings the ring back.
- **`--ring` cannot go on a `--primary` fill.** At 1.43:1 light and 1.08:1 dark it is invisible
  rather than weak, and no opacity rescues it — it is the same hue two lightness steps away. A
  control inside a DM's own bubble paints `ring-primary-foreground` instead (§2.4).
- **The `aria-invalid` ring is a focus indicator.** `aria-invalid:ring-destructive` sets only the
  ring *colour*; the width comes from the control's own `focus-visible:ring-3`, so it paints nothing
  until the field is focused and what it paints then is the focus ring, recoloured. At `/20` it
  replaced a 3.45:1 indicator with a 1.43:1 one on exactly the fields a person is most likely to be
  tabbing through. Opaque, it is 4.68:1 on the canvas.

One ratio is recorded and never rendered: `--ring` / `--brand-tint-strong` (hover on an
already-selected row) is **2.92:1 ❌** light, 4.50:1 ✅ dark. A Tailwind ring is drawn *outside* the
element's box, so a row that is hovered *and* focused paints its ring over `--sidebar` at 3.45:1,
not over its own hover fill. The test asserts the number rather than the threshold, so a token move
forces a recompute instead of a surprise.

Two rings keep an alpha on purpose, and neither is a focus indicator:
`Tasks/Gantt/GanttBar.vue`'s `ring-ring/40` (armed) and `ring-ring/60` (staged). Armed only happens
while the bar is focused, so the opaque `focus-visible:ring-ring` is what is painted then — a
`:focus-visible` rule outranks an unqualified one. Those two are a second, weaker mark separating
armed from staged, and both states are also in the button's accessible name and in
`data-gantt-staged`, so §6 is satisfied without them.

**Why each ❌ here is recorded rather than fixed:**

- **Status dots and status borders** are decoration on a badge that always prints its label —
  `StatusBadge` marks the dot `aria-hidden` and renders the text beside it. They carry no
  information of their own, so 1.4.11 does not apply to them. This is precisely the measurement
  that makes "colour is never the only carrier of meaning" (§6) a rule and not a preference: the
  moment a screen shows a bare dot with no label, four of the eight statuses are illegible.
  (The two Phase 2 dots, `backlog` and `changes`, clear 3:1 in both themes — that is a property
  of where their hues sit, not a new rule, and it does not license a bare dot for them either.)
- **`--border` and `--input`** are shadcn's own hairlines. A separator is decorative. A *field*
  boundary is not — a text input identified only by a 1.26:1 hairline is a genuine 1.4.11
  shortfall in the shadcn default, and it is recorded here so nobody re-derives it. It is not
  fixed in this file's scope; the focus state is fine (`--ring` at 3.54:1 on the canvas, opaque, since 2026-09-26)
  and `aria-invalid` swaps the border to `--destructive` at 4.56:1.

### 2.3 The recorded failure that is a rule

| Pair | Light | Dark |
| --- | --- | --- |
| `--primary-foreground` / `--brand` — **never rendered** | 3.50:1 ❌ | 6.73:1 ✅ |
| `--sidebar-primary-foreground` / `--sidebar-primary` — **never rendered** | 3.50:1 ❌ | 6.73:1 ✅ |

`--brand` under white text is 3.50:1 in light mode. That number is the whole reason
`--brand` and `--primary` are two tokens (decision 0.5-22), and it is kept as a failure on
purpose: if a task ever puts text on a `--brand` fill, this is the row that says why it must not.
The dark value passes, which changes nothing — a rule that only holds in one theme is not a rule
anyone can follow in a template. **`--brand` is graphics only, in both modes.**

### 2.4 The chat — decision M-23

The colour treatment the chat slice shipped, recomputed by `tests/Unit/FocusRingContrastTest.php`.
It arrived without a row in this file, which is POLISH-BACKLOG §E.2.

A DM draws the viewer's own messages in a solid `--primary` bubble and everyone else's in `--muted`
with a hairline; a channel keeps one list and tints the viewer's own row `--brand-tint` instead. So
`--primary` is a **fill behind text** here, which is exactly what `--primary` exists for (§2.3) and
exactly what `--brand` may never be.

| Pair | Where | Light | Dark |
| --- | --- | --- | --- |
| `--primary-foreground` / `--primary` | the body text and the clock inside the viewer's own DM bubble | 5.01:1 ✅ | 7.31:1 ✅ |
| `--primary` / `--primary-foreground` | the "Mentions you" chip on that bubble — the pair inverted | 5.01:1 ✅ | 7.31:1 ✅ |
| `--foreground` / `--muted` | the other person's bubble | 12.48:1 ✅ | 14.26:1 ✅ |
| `--foreground` / `--brand-tint` | the viewer's own row in a channel | 12.27:1 ✅ | 14.47:1 ✅ |
| `--primary-foreground` / `--primary` — as a **mark** | the mention `ring-2` on the viewer's own bubble, and the focus ring of any control inside it | 5.01:1 ✅ | 7.31:1 ✅ |
| `--primary` / `--muted` — as a **mark** | the mention `ring-2` on the other person's bubble | 4.71:1 ✅ | 5.85:1 ✅ |

Three things this table is load-bearing for:

- **The clock is `--primary-foreground`, not a muted grey**, and it is the size that makes it quiet.
  Any alpha on top of 5.01:1 puts it under 4.5:1.
- **A mention is a ring, not a left bar and not a tint**, because it has to survive on both fills —
  and the words *"Mentions you"* are printed on every row of the run, so the ring is the second
  carrier and never the only one (§6).
- **The bubble's side and its squared tail corner** carry who spoke, so the fill is not doing that
  alone either.

**Messaging polish (28 Sep 2026).** The composer is one pill (`rounded-3xl`, `border-input`,
`bg-card`): Attach, the textarea, the mic and "@ Mentions" inside it, and **Send joined to its right
end** as a `--primary` segment (`rounded-r-3xl`). The textarea has no border of its own; its focus
indicator was painted on the pill (decision 12-70: a 1 px `--ring` border). **Since 2026-09-30
(decision 12-72) the pill paints no focus border or ring at all**, like every other text field
(§2.2): its border stays `--input` (`--destructive` on an error) and the caret shows focus.
Below `sm` the Attach / Mentions / Send words go and the icons stay, each with its accessible name.
An attachment is a **card of its own size** (`w-fit`, file cards `sm:max-w-xs`, images inside
`max-w-xs × max-h-64` at their natural ratio); only the card is a link, never the message row.

**Pinned composer — task drawer only (brief 016, 30 Sep 2026).** `MessageThread`'s opt-in
`composerPlacement="footer"` pins the composer to the bottom of the `DetailDrawer` it sits in, Asana
style: a hairline `border-t` on `--background`, `px-4 py-3`, and one compact pill row —
`[Attach] [textarea, up to ~5 lines] [mic] [@] [Send]`, every control a 32 px (`icon-sm`) ghost icon
button with an `aria-label` and a tooltip, Send the one `--primary` disc. **No placeholder** and no
visible helper line: the line stays as the field's `sr-only` description, and only "N characters
left" comes back on screen below 200. The chip, errors and the recording strip stack above the row.
The Messages page and the full task page keep the inline pill above, unchanged.


---

## 3. Breakpoints

Tailwind v4 defaults, unmodified — `app.css` defines no `--breakpoint-*`. Acceptance widths are
**375 / 768 / 1280**; measure with `node .claude/dispatch/dispatch-measure.mjs <url> 375 768 1280`.

| Prefix | Min width | What changes |
| --- | --- | --- |
| *(base)* | 0 | One column. `DataTable` renders its rows as a stacked card list. The sidebar is off-canvas behind the hamburger. Page padding `p-4`, top bar `px-4`. `DetailDrawer` is a near-full-width sheet. |
| `sm` | 640 px | `DetailDrawer` starts honouring its `width` prop (`sm:max-w-sm/md/lg/xl`). |
| `md` | **768 px** | **`DataTable` switches to the real table** — the stacked list is `md:hidden`, the table is `hidden md:block`. The **top bar's breadcrumb trail expands**: below `md` only the last crumb shows (`hidden md:inline-flex` on the rest and on every separator), so a phone gets the page name, not the path. Page padding goes `md:p-6`, top bar `md:px-6`. `SkeletonCardGrid` goes 1 → 2 columns. |
| `lg` | **1024 px** | **The sidebar rail appears.** `AppSidebar` is `hidden … lg:flex` and fixed; below `lg` the only navigation is `MobileNavSheet` (its trigger is `lg:hidden`). The three layouts pad their content `lg:pl-64`, or `lg:pl-14` when the rail is collapsed. |
| `xl` | 1280 px | `SkeletonCardGrid` reaches 3 or 4 columns; dashboard stat rows widen. |
| `2xl` | 1536 px | Content stops growing: `<main>` is `max-w-screen-2xl mx-auto`. |

The sidebar's rail mode (`w-64` ↔ `w-14`) is a *user* toggle, not a breakpoint — it only exists
at `lg` and up, is driven by `SidebarRailToggle`, and is stored in `lib/sidebarState.ts`.

---

## 4. Components

Every signature below was read from the component's own `defineProps` / `defineEmits` /
`defineSlots` in this pass. Import paths are the `@/` alias (`resources/js`).

### 4.1 Layout shell

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `Shell/SkipToContent.vue` | The skip link (WCAG 2.4.1). Off-screen until focused, then the first thing on the page; it **moves focus** to `<main>`, not just the scroll position. | no props · targets `#main-content` | Never directly, and never anywhere but as the **first child** of a `Layouts/*.vue`. The layout's `<main>` must carry `id="main-content" tabindex="-1" outline-none`. Not on `AuthLayout` — its content is already the first stop. |
| `PageShell.vue` | The standard page head: breadcrumb, title (or greeting), description, an actions area and an optional tabs strip, above the page body. Absorbed the deleted `PageHeader.vue`. | props `title: string`, `description?`, `breadcrumb?: Crumb[]`, `greeting?: { name: string; today: string }`, `bleed?: boolean` · slots `actions`, `tabs`, default · exports `interface Crumb { label: string; href?: string }` | Always, at the top of every Inertia page. `greeting` replaces the title with "Good morning, Name" over the date — dashboards only. **`bleed`** (29 Sep 2026, brief 008) makes the page a full-width work surface: the shell's `<main>` drops its max width for 16 px gutters and a 12 px top band (the layouts read `data-page-bleed`), and the page's rhythm tightens from `gap-6` to `gap-3`. **Tasks views only**; every other page keeps the standard padding. |
| `Shell/AppSidebar.vue` | The fixed `lg`-and-up rail: wordmark, nav groups, "Coming soon" disclosure, rail toggle. | props `groups: NavGroup[]`, `homeHref: string` | Only from a `Layouts/*.vue`. A page never mounts it. |
| `Shell/AppSidebarNav.vue` | Renders the live nav groups; drops every item with a `phase` (decision 0.5-3). | props `groups: NavGroup[]`, `rail?: boolean = false` · emits `navigate` | Only from `AppSidebar` / `MobileNavSheet`. |
| `Shell/SidebarComingSoon.vue` | The one closed disclosure at the bottom holding every unbuilt row, with its phase badge. | props `groups: NavGroup[]` | Never directly — it is how unbuilt work is shown, and the only way. |
| `Shell/SidebarRailToggle.vue` | Collapse-to-icons control. | props `rail: boolean` · emits `update:rail` | Only from `AppSidebar`. |
| `Shell/MobileNavSheet.vue` | The below-`lg` off-canvas drawer and its hamburger. | props `groups: NavGroup[]`, `homeHref: string` | Only from `AppTopBar`. |
| `Shell/AppTopBar.vue` | Sticky `h-14` bar: hamburger, breadcrumb, then search / create / bell / user menu. | props `groups: NavGroup[]`, `homeHref: string`, `breadcrumb?: string \| null = null` | Only from a layout. Pass `breadcrumb` when the last crumb is a record's name the URL cannot give. |
| `Shell/GlobalSearch.vue` | The ⌘K / Ctrl+K command palette: nav rows matched in the browser, record rows from `GET /search`. | props `groups: NavGroup[]` | Only from `AppTopBar`. **Records are searched in the current section only** (29 Sep 2026, brief 010): `lib/searchScope.ts` maps the page to `SearchableType`s (Tasks → tasks, Projects → projects, Clients, Messages, Meetings, Team/Employees → people, Finance income/expenses) and sends them as `type=`; a Dashboard, or a page with no searchable type of its own, searches everything. The trigger (`Search tasks`), the placeholder (`Search tasks…`) and a `bg-muted` scope label inside the input row (`Tasks only` / `Everything`) name the scope. No "search everything" switch — the Dashboard is where that lives. |
| `Shell/QuickCreate.vue` · `UserMenu.vue` · `ThemeToggle.vue` | The `+` menu, the avatar menu, and the Light/Dark/System control inside it. | no props | Only from `AppTopBar` (`ThemeToggle` from `UserMenu`). |
| `Shell/NotificationBell.vue` | The bell: an unread badge capped at `9+`, and the newest ten behind it — read ones included, because a bell that empties itself as you glance at it gives you no way back to what you just dismissed. Loading is three `Skeleton` lines, a failed poll is an `error` `EmptyState`, an empty mailbox says *Nothing yet*. Footer links to the Center. **Not empty since Phase 2, slice 5.** | no props · reads `useNotificationBell()` (§4.8) | Only from `AppTopBar`. **It does not own the poll**, so a second mount cannot start a second interval, and it **removes itself** rather than greying out for somebody with no mailbox (§5.12) — it learns that by being refused once, which is `status === 'denied'`. The count is the server's: marking read writes and the write re-reads; nothing here subtracts one. |
| `AppWordmark.vue` | The only sanctioned rendering of the mark: inline SVG + "GoodTechies HQ", cap-height matched. | props `variant?: 'lockup' \| 'mark' = 'lockup'`, `surface?: 'light' \| 'dark' = 'light'`, `size?: number = 28`, `class?` | Anywhere the mark appears. `surface="dark"` drops the tile so there is no black square on a dark surface. Never render the logo any other way. |

`NavGroup` comes from `@/navigation/types`; the per-role data is
`navigation/{admin,employee,accountant}.ts`. A row goes live by gaining an `href` and losing its
`phase` key — the nav files are not restructured to enable one.

### 4.2 Data display

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `DataTable/DataTable.vue` | The list table: sortable server-driven headers, column-visibility menu, density toggle, row selection + bulk bar, per-row ⋯ menu, sticky header, pagination, loading skeleton, automatic empty-vs-filtered state, **collapsible group bands**, and a stacked card fallback below `md`. | generic `<T extends { id: number \| string }>` · props `id: string`, `columns: ColumnDef<T>[]`, `rows?: T[]`, `groups?: TableGroup<T>[]`, `groupBy?: string`, `meta?: PaginationMeta`, `links?: PaginationLinks`, `loading?=false`, `filtersActive?=false`, `selectable?=false`, `density?: Density`, `viewOptions?=true`, `showPerPage?=false`, `perPageOptions?: number[]`, `rowLabel?: (row:T)=>string`, `rowClickable?=false`, `noun?: string`, `emptyIcon?: Component`, `emptyTitle?='Nothing here yet'`, `emptyDescription?`, `filteredTitle?='Nothing matches these filters'`, `filteredDescription?` · emits `row-click(row)`, `clear()`, `update:selected(ids)`, `sort-change(SortState)`, `per-page-change(number)` · slots `toolbar`, `bulk-actions({selected})`, `row-actions({row})`, `card({row})`, `empty-action`, and `cell-<key>({row, value, column})` per column | Any list of records an admin scans. Describe the columns as data (`ColumnDef`), not as markup. Leave `sortable` off a column the controller cannot order by and `showPerPage` off a controller that ignores `per_page` (decisions 0.5-16, 0.5-19) — a control the server ignores is a lie. An employee reading five projects gets cards instead (decision 0.5-20). |
| ↳ **row click** | `rowClickable` gives a row a pointer, a `tabindex="0"` stop and Enter, and emits `row-click(row)` — in **both** layouts. Slice 3 added the card list's half: without it the drawer a list opens on a row is unreachable below `md`, which is every phone. A click on a link, button, checkbox or menu item inside the row is that control's and does not fire it. | — | Opening a record beside its list. The screen mounts `DetailDrawer` and passes the clicked row's id. |
| ↳ **grouping** | Pass `groups` instead of `rows` and the same table grows a band per group — chevron, the label (inside a `StatusBadge` when the group carries a `tone`), and the count — above its rows, in **both** layouts: a `<tr>` spanning every column at `md` and up, a banded `<li>` in the card list below it. The band is a `<button>` with `aria-expanded`, so Tab reaches it and Enter/Space toggle it. There is **no second table component**; ungrouped call sites are untouched. | `TableGroup<T>` = `key`, `label`, `tone?: StatusKey \| null`, `count?` (defaults to `rows.length`), `rows: T[]` · `groupBy` names the grouping variant and only scopes the stored collapsed state, so two variants cannot close each other's groups | Any list the server already bucketed. **Defaults:** groups open from the top until ~15 rows are open, then the rest arrive collapsed with their counts; an empty group never opens. A viewer's own toggles are stored per viewer, per table, per variant, and override the rule. **Never** re-derive a group's `tone` in Vue — the server resolves it, and a second copy of that mapping drifts. A grouping may put one row in two groups, so the counts can sum higher than the list's total: that is correct, and no screen prints a sum of groups. |
| `DataTable/types.ts` | The column and group contracts. | `ColumnDef<T>` = `key`, `header`, `cell?: 'text'\|'badge'\|'avatar'\|'date'\|'currency'\|'number'\|'actions'`, `sortable?`, `align?`, `width?` (a token class), `hideable?`, `defaultHidden?`, `value?: (row)=>unknown`, `headerHidden?`, `nowrap?` · `TableGroup<T>` = `key`, `label`, `tone?`, `count?`, `rows` · also `Density`, `SortState`, `alignOf()`, `isNumericCell()` | Import the type; never redeclare a local column or group shape. |
| `DataTable/DataTableBulkBar.vue` | The bar that appears only once rows are selected. | props `count: number`, `noun?: string` · emits `clear` · default slot for the verbs | Never directly — `DataTable` mounts it; you fill its `bulk-actions` slot. |
| `DataTable/DataTableColumnMenu.vue` | The column-visibility dropdown. | props `columns: ColumnDef<never>[]`, `hidden: string[]` · emits `toggle(key, visible)` | Never directly. |
| `Pagination.vue` | Laravel paginator links plus "Showing x–y of z". | props `links: PaginationLinks`, `meta: PaginationMeta` · exports `PaginationLinks`, `PaginationMeta`, `Paginated<T>` | Under a card grid that `DataTable` does not own. Inside a table it is already there. |
| `StatusBadge.vue` | The status pill: tinted fill, hairline, dot, label. One lookup into the T1 triplets. | props `status: StatusKey`, `label?: string`, `size?: 'sm' \| 'md' = 'md'` · exports `type StatusKey`, `labelFor(status)`, **`statusToneClass(status)`** | Every status, everywhere. There is deliberately **no colour prop** — the status *is* the colour. `statusToneClass` hands the same `bg/text/border` triplet to a surface that cannot *be* a pill — slice 4's calendar span bars — so there is one mapping of a status to a colour and not two. A surface that takes it still prints its label: a tinted rectangle with no words is colour carrying meaning alone (§5.6). |
| `StatusPill.vue` | A thin deprecated alias of `StatusBadge` keeping the Phase 1 `label` / `tone` props. | props `label: string`, `tone: StatusTone` · exports `type StatusTone` (= `StatusKey`), `toneForProjectStatus(status: string)` | Do not use it in new code — use `StatusBadge`. Do import `toneForProjectStatus` from here: it is the one mapping of a project status to a status key. |
| `StatCard.vue` | One KPI: label, big `tabular-nums` number, sub-line, optional icon, delta and sparkline; optionally the whole card is a link. | props `label: string`, `value?: string\|number`, `sub?: string`, `phase?: number`, `icon?: Component`, `delta?: StatDelta`, `href?: string`, `size?: 'default'\|'compact' = 'default'` · slot `sparkline` · exports `interface StatDelta { value: string\|number; direction: 'up'\|'down'\|'flat'; since?: string }` | A number someone acts on. Omit `value` and pass `phase` for the "Arrives in Phase N" placeholder. A delta is only ever passed when it is computed from real data — there is no "unknown" delta. |
| `Dashboard/AttentionList.vue` | "Needs your attention": a card of linked rows, each an icon medallion, title, meta and chevron; falls back to an `EmptyState`. | props `title: string`, `items?: AttentionItem[] = []`, `emptyTitle: string`, `emptyDescription?`, `emptyIcon?: Component = Inbox` · slot `action` · exports `interface AttentionItem { id; icon: Component; title; meta?; href; tone?: 'default'\|'urgent' }` | The tier-2 block of a dashboard. Every row must be a link to the actual thing. |
| `Dashboard/TimerHeroCard.vue` | The employee dashboard hero: the timer or the clock-in, and the page's one primary button (decision 0.5-5). | props `mode: TrackingMode`, `target?: string` | Top of the employee dashboard. `mode === 'none'` renders nothing — the caller shows no hero rather than an empty card. |

### 4.3 Feedback

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `EmptyState.vue` | Icon medallion, title, one line of help, and an action. Replaced the deleted `PlaceholderPanel.vue`. | props `icon: Component`, `title: string`, `description?`, `variant?: 'empty'\|'filtered'\|'error' = 'empty'` · emits `clear` · slot `action` | Any list, panel or popover with nothing in it. `filtered` offers *Clear filters* by itself, so a filtered-to-nothing view always has a way out; `error` tints the medallion destructive. |
| `Skeletons/SkeletonTable.vue` | A table-shaped placeholder. | props `rows?: number = 6`, `columns?: number = 4` | `DataTable` swaps it in from its own `loading` prop. Use it directly only for a bespoke table. |
| `Skeletons/SkeletonCardGrid.vue` | A card-grid placeholder. | props `cards?: number = 6`, `columns?: 1\|2\|3\|4 = 3` | While a card-grid page is loading. |
| `Skeletons/SkeletonDetail.vue` | A detail-page placeholder. | no props | While a show page is loading. |
| Skeleton wiring | `useNavigationPending(delay = 200)` from `@/lib/useNavigationPending` returns a readonly ref that is true once an Inertia visit has run longer than `delay`. | — | Bind a skeleton to it. Visits under 200 ms never flip it, so a fast page does not flash. |
| `Toaster.vue` | The app's one Sonner toaster, with the status tints and `--elevation-overlay` handed over as CSS variables. | no props | Mounted once per layout. Already there — do not mount a second one. |
| `lib/toast.ts` | Client-side feedback. | `toast.success(msg, opts?)`, `.error(…)`, `.loading(…)`, `.promise(promise, { loading, success, error })`, `.dismiss(id?)` | After a client action (a row archived, a copy-to-clipboard). |
| `FlashMessage.vue` | Renders `page.props.flash.success` / `.error` as an `Alert`. | no props | Server-side flash on page load. It is already in each layout. **Never send one message through both this and `toast()`** — that is how a user gets told twice. |
| `DetailDrawer.vue` | The right slide-over a list opens on a row. Deep-linkable: while open the URL carries `?detail=<id>`, written with `syncQuery()` — **not** `pushQuery()`. Esc, focus trap and focus restore come from reka's dialog. | props `open: boolean`, `title: string`, `subtitle?`, `width?: 'sm'\|'md'\|'lg'\|'xl' = 'md'`, `deepLinkId?: string\|number\|null` · emits `update:open` · slots `header-actions`, default, `footer` · provides `DRAWER_FOOTER_INSET` (`drawerFooter.ts`) | Showing one record beside its list. **A pinned composer (brief 016)** is drawn `absolute bottom-0` against the panel — outside the scroll container, still in its own place in the tree (one `MessageThread`, typed text and live updates intact) — and reports its height through `DRAWER_FOOTER_INSET`; the body pads its bottom by that much. **The page reads `queryParam('detail')` in `setup`, never in `onMounted`:** this component syncs the URL from a watcher with `immediate: true`, and a child's setup runs before its parent's `onMounted`, so a drawer that starts closed strips `?detail=` before an `onMounted` could read it and a pasted deep link opens nothing. Omitting `deepLinkId` keeps the drawer out of the URL. Slice 3 was its first caller: the two defects above (the URL write, the read timing) were both found by opening it from a row and pressing Esc. |
| `FilterBar.vue` | Debounced search plus one removable chip per active filter, an *Add filter* popover and *Clear all*. Chip mode serialises straight to the query string, so a filtered list is a shareable URL. | props `search?: string\|null = null`, `active?=false`, `placeholder?='Search…'`, `inputId?='filter-bar-search'`, `filters?: FilterDef[]`, `extraActive?=false` · emits `update(search)` (300 ms after the last keystroke), `clear()` · slots `extra`, default · exports `FilterKind = 'select'\|'multi-select'\|'date-range'\|'toggle'`, `FilterOption`, `FilterDef { key; label; kind; options?; searchPlaceholder? }` | Above every list. Passing `filters` turns chip mode on and the bar owns the query string; leaving it off keeps the plain search bar and the page owns navigation. A `date-range` filter writes `<key>_from` / `<key>_to`; a `multi-select` comma-joins into one key. A **`toggle`** (29 Sep 2026, brief 008) is a yes/no filter picked straight from the *Add filter* list with no second level — set, it writes `<key>=1` and shows a label-only chip; removed, the key leaves the URL. The Tasks bar's *Overdue only* and *Show archived* live there. Opt-in. |
| `FilterChip.vue` | One chip: `Label: value ×`, as two adjacent buttons so each has its own accessible name. When `value` is empty (a `toggle` filter) it draws a **label-only** chip, `Label ×`, with the label in full weight. | props `label: string`, `value: string` · emits `edit`, `remove` | Never directly — `FilterBar` renders them. |

### 4.4 Charts — `@unovis/vue` (decision 0.5-4)

`@unovis/vue` + `@unovis/ts` are the **only** charting dependency, and `--chart-*` is consumed by
nothing except these wrappers. Phase 10's Gantt is a custom component, not a chart.

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `Charts/chartTokens.ts` | The one place a chart learns a colour. Resolves `--chart-1…5`, the eight status colours, `--border`, `--muted-foreground`, `--foreground`, `--card`, `--popover` and `--font-sans` to live `rgb()`, and re-reads them when the `dark` class on `<html>` flips (one shared `MutationObserver`). | `useChartTokens(): Ref<ChartTokens>` · `chartCssVars(tokens)` → the `--vis-*` overrides (gridlines, tooltip on `--popover` with `--elevation-overlay`, the 2 px surface gap) · `niceTicks(max, count = 4)` · `chartTooltip(label, value, swatch?)` · `chartEscape(value)` | Building any new chart. A chart that hard-codes a colour, or reads one from a Tailwind class, is wrong by construction — ask this module. |
| `Charts/AreaTrend.vue` | A single-series filled line over time, with a crosshair tooltip and a data-table fallback. Plotted by index, so no date library. | props `data: AreaTrendPoint[]`, `label?`, `height? = 200`, `loading? = false` · exports `interface AreaTrendPoint { x: string\|number\|Date; y: number }` | A trend: attendance over 14 days, hours per week. One series, so it gets `--chart-1`. |
| `Charts/BarCompare.vue` | One-series bars, vertical or horizontal, one tick per category. | props `data: BarCompareItem[]`, `orientation?: 'vertical'\|'horizontal' = 'vertical'`, `label?`, `height? = 200`, `loading? = false` · exports `interface BarCompareItem { label: string; value: number }` | Comparing named categories. Bars are compared by length, not by colour — they are all `--chart-1`. |
| `Charts/DonutBreakdown.vue` | A donut with a total in the middle, labelled slices and a 2 px `--card` gap between fills. | props `data: DonutSlice[]`, `total?`, `centerLabel?`, `height? = 200`, `loading? = false` · exports `interface DonutSlice { label: string; value: number; tone?: StatusKey }` | A breakdown of a whole. Give a slice a `tone` and it takes that status colour instead of the categorical slot, so a "tasks by status" donut agrees with the badges beside it. |
| `Charts/Sparkline.vue` | A bare line inside a `StatCard`, with an `aria-label` summary instead of a table. | props `data: number[]`, `tone?: 'neutral'\|'up'\|'down' = 'neutral'`, `height? = 32`, `label?`, `loading? = false` | The `sparkline` slot of a `StatCard`. `up` / `down` borrow `--status-done` / `--status-cancelled`, so a sparkline can never introduce a seventh colour. Two points minimum. |

Every wrapper ships the same three states: `loading` → a `Skeleton`, no data → an `EmptyState`,
and an accessible fallback (a data table, or an `aria-label` for `Sparkline`).

### 4.5 Tasks — `Components/Tasks/` (Phase 2)

The detail screen is **one body mounted twice**: as a page under `PageShell`, and inside
`DetailDrawer` from a row click on the List. Nothing below decides what a person may do —
`task.permissions` and `task.available_transitions` arrive resolved by `TaskPolicy`, and the
endpoint checks again. A second copy of `TaskStatus::TRANSITIONS` in Vue is a copy that drifts.

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `Tasks/taskDetail.ts` | The detail payload's types, every task endpoint spelled once, the one mutation helper, and the formatters. | `TaskSurface = 'admin'\|'employee'`, `TaskDetail`, `TaskTransition`, `TaskChecklistItem`, `TaskLink`, `TaskStub`, `TaskFirstCompletion`, `TaskActivityEntry`, `TaskSibling` · `taskRoutes(surface, id)` (slice 4 added **`reorder`**) · `mutateTask(method, url, data, { onAccepted, onSettled, onFinish })` · `focusField(ref)` · `formatDate`, `formatDateTime`, `formatMinutes`, `initials` · the `STATUS_*` constants | Any task write. `mutateTask` posts with `preserveState`/`preserveScroll` and calls `onAccepted` only when the server did **not** flash an error — a `TaskStateException` comes back 200 with a message, so a 2xx is not acceptance. `focusField` exists because a template ref on `<Input>`/`<Textarea>` is the component, not the element, and `.focus()` on it is silently a no-op. |
| `Tasks/TaskDetailBody.vue` | The whole detail screen: status strip and moves, every panel, archive and delete. | props `task: TaskDetail`, `activity: TaskActivityEntry[]`, `surface: TaskSurface`, `reviewers: {id,name}[]`, `employees?`, `priorities?`, `siblings?`, `tags?: TaskTag[]`, `projects?: TaskNamedRef[]`, `variant?: 'page'\|'drawer' = 'page'` · emits `settled`, `removed` | Both task detail mounts, and nothing else. **Brief 014 (Asana layout):** one main column — status strip and moves, the label \| value rows (Assignee from `TaskPeoplePanel variant="row"` in the fields' `#assignee` slot), Description, Subtasks, Checklist, Attachments (`FilePanel compact`), Work summary, Discussion; Links, Dependencies, Time, Activity and Manage follow (a side column on the page from `xl`). Every `Card` inside the body is drawn flat and its `CardDescription` hidden (arbitrary `[&_[data-slot=card]]` variants on the root) — headings, not boxes. `variant` only chooses the column count. `settled` means "a write landed": the page mount ignores it (Inertia re-rendered it), the drawer re-reads its payload. |
| `Tasks/TaskStatusActions.vue` | The moves, from `available_transitions`, with what each one has to collect first. | props `task`, `surface`, `reviewers`, **`variant?: 'inline' \| 'headless' = 'inline'`** · emits `settled`, `hand-off`, **`outcome(accepted: boolean)`**, **`dismissed`** · exposes **`requestMove(status, afterId?) → 'committed' \| 'asking' \| 'unavailable'`** | The one status control, and the one the board drag **does** reuse. Whether the summary on the task is the primary assignee's is decided by id — `work_summary_by.id === primary_assignee.user_id`, the comparison `TaskService::assertCompletable()` makes — never by display name. Submitting for review **is** the work-summary form, so it cannot fail for a missing summary; cancelling and reopening collect their required reason up front; Approve *reads* the summary and whose it is instead of offering a field, because a summary typed by the reviewer would be the reviewer's and completion wants the primary assignee's. Only the review-cycle verbs get a button; everything else, cancelling included, is in the `Change status` menu. **Slice 4 made it the Board's flow too, rather than letting a second one exist:** `headless` draws the dialogs and no controls, `requestMove()` is the caller's trigger, `afterId` rides along as `after_id`, and `outcome` / `dismissed` are how a caller that moved a card in anticipation learns it must put it back. Dragging into In review therefore opens the *same* work-summary form and commits nothing until it is sent. |
| `Tasks/TaskSummaryPanel.vue` | The work summary, editable, and **every completion the task has had**. | props `task`, `surface` · emits `settled` | `first_completion` is why it exists: a reopening clears `completed_at`/`completed_by`, so the history cannot be read off them. It prints the first completion, its summary as it stood, who recorded it, and whether the task has been reopened since. |
| `Tasks/TaskFieldsPanel.vue` | Name, description, dates, priority, estimate, tags and — on Admin — which project the task is in: read, and edit where the request would accept it. | props `task`, `surface`, `priorities?`, `tags?: TaskTag[]`, `projects?: TaskNamedRef[]` · slot `#assignee` · emits `settled` | **Brief 014:** a `<dl>` of label \| value rows (Assignee, Due date, Priority, Project, Start date, Estimate, Tags, Created by), then **Description** as borderless text. Due date (`type="date"` `Input`), Priority (`Select`) and the description (saved on blur, re-read only while untouched) are edited **in place**, one field per `PUT`, for someone who may plan; title, start date and estimate stay behind the ghost **Edit details**. The plan/work split is `UpdateTaskRequest`'s: an employee sending a date gets a `prohibited` error with a sentence. The screen offers only what the requester may write, so nobody types into a refused control. **Tags are assigned on both surfaces** (`PUT` takes `tag_ids` from an employee too); `tags` is the controller's list — this project's tags plus the global ones, which is exactly what the request accepts — and the picker groups the two, because only the scoped ones are left behind by a move. Creating or deleting a tag is not here. **`projects` is Admin-only**: `project_id` is `prohibited` for anybody else, so the employee page draws no move control rather than one that exists to be refused. The move opens a dialog that names the tags the move will detach, because the server detaches them and writes the timeline line either way. |
| `Tasks/TaskPeoplePanel.vue` | Assignees, which of them is primary, and the hand-off. | props `task`, `surface`, `employees?`, `variant?: 'card'\|'row' = 'card'` · emits `settled` · exposes `openHandOff()` | `row` (brief 014) is the value of the Assignee row: avatars and names as one button into the same assign dialog, plus a Hand over icon button. Assigning is Admin-only (there is no employee endpoint); handing over is the primary's own move too, and always needs a reason. |
| `Tasks/TaskChecklistPanel.vue` · `TaskLinksPanel.vue` · `TaskDependenciesPanel.vue` · `TaskActivityPanel.vue` | The four side panels. | each props `task`, `surface` (+ `siblings?` on dependencies; activity takes `activity` alone) · emits `settled` | Dependencies are writable on the Admin surface only — the employee routes have none, so it reads both directions there. Activity prints the server's sentences unchanged; a line the screen rewrites stops matching the audit log beside it. |
| `Tasks/TaskSubtasksPanel.vue` | **Subtasks** (flow F2, decision 12-71), below the checklist: real child tasks, one level deep. Each row is a check button (`Circle` / `CircleCheck`), the title (a button that opens that subtask — in place in the drawer, a visit on the page), the status word (≥ `sm`), `DueCountdown` and one assignee avatar (dashed circle when unassigned). Brief 014 made it Asana's: a **Subtasks** heading with `done/total` and a `+`, compact rows (check · title with an `Open →` hint on hover · `DueCountdown` · avatar; the status word is `sr-only`), and, for `can_add_subtask` only, **one Add subtask line**: a borderless title `Input` (Enter adds) with a calendar icon button (a `Popover` holding a `type="date"` `Input`; the choice shows as a date chip with a clear ×) and a person icon button (a `Popover` list of `employees`; the choice shows as the avatar) on the right. | props `task`, `surface`, `employees?` · emits `settled`, `open(id)` | **The check is one step forward, never "done"**: it makes the server's `check_to` move (`TaskService::CHECK_STEP`: To do → In progress → In review → Completed, offered only when `TaskPolicy::transition` allows it) through the ordinary `…/status` endpoint, and is disabled when there is none. The move into In review opens an inline work-summary `Textarea` first, because the machine refuses it without one. Rows are only the subtasks this reader may view. Not colour alone: a completed row is a filled check, a struck title and its status word. Mounted by `TaskDetailBody` only when there are rows or `can_add_subtask`, so a subtask (which cannot have subtasks) draws no panel; its body instead shows **Part of \<parent\>** (`CornerDownRight`) when — and only when — the server sent `parent`. |
| `Tasks/TaskDetailDrawer.vue` | `TaskDetailBody` inside `DetailDrawer`, fetched. | props `open: boolean`, `taskId: number\|null`, `surface` · emits `update:open` | A row click on either Tasks List. **It fetches** because the list payload is not the detail payload: `TaskResource` puts the checklist, links and dependencies behind `whenLoaded` and `available_transitions` behind a `task_detail` attribute, and there is no JSON endpoint for one task — so it asks the detail route for its Inertia payload over the protocol's own `X-Inertia` GET and hands those props to the body unchanged. |
| `Tasks/QuickAddTaskModal.vue` | The quick-add dialog — `docs/design-refs/08-new-task-modal.png`. | props `open`, `projects`, `priorities`, `employees?`, `projectId?` · emits `update:open`, `created` | Creating a task. **Task** is name · project · description; **Details** is the rest of `StoreTaskRequest`. Only Backlog and To do are offered as a starting status (`TaskService::BIRTH_STATUSES`). Reachable from the List's primary action and from `QuickCreate`, which routes to `/admin/tasks?new=1` because the menu has no project list of its own. |
| `Tasks/TaskList.vue` | The grouped List (slice 1), now with the assignee filter and row click. | adds props `employees?: TaskNamedRef[]` · emits `row-click(task)` | Passing `employees` turns on the assignee chip; the employee surface is sent none, so it never appears there. It also exports the payload's types — `Task`, `TaskPerson`, `TaskEmployeeRef` (an employee `id` **plus** the `user_id` behind it), `TaskAssignee`, `TaskTag`, `TaskOption`, `TaskNamedRef`, `TaskFilters`, `TaskGroups` — and `tagTone(colour)`. Its `tags` prop is the filter chip's options, which the controller scopes to the projects the requester can see, not every tag in the system. **Slice 4 moved its chip bar out into `TaskFilterBar`** — three views now read one `TaskService::filters()` — and nothing else about the List changed. |

### 4.6 Utilities in `lib/`

| Module | Exports | Notes |
| --- | --- | --- |
| `lib/utils.ts` | `cn(...inputs)` | `clsx` + `tailwind-merge`. Every conditional class goes through it. |
| `lib/searchScope.ts` | `searchScopeFor(url) → { types: SearchType[] \| null; noun; label }`, `searchUrl(term, scope)`, `EVERYTHING` | The one route → search-types map for the ⌘K palette (brief 010). Pure, no imports; tested by `tests/js/searchScope.test.ts`. `types: null` is every type. |
| `lib/tableState.ts` | `currentQuery()`, `queryOf(url)`, `queryParam(key, url?)`, `sortFrom(url)`, `pushQuery(patch, { keepPage })`, **`syncQuery(patch)`**, `resetQuery(keep)`, `readHiddenColumns(id)`, `writeHiddenColumns(id, hidden)`, `readDensity(id)`, `writeDensity(id, density)` | The URL carries what is shareable (search, filters, sort, page). `localStorage` holds only what is personal — hidden columns and density, under `hq.table.<id>.*`. Every storage call is wrapped in try/catch. **`pushQuery` vs `syncQuery`:** `pushQuery` navigates, for state the server answers; `syncQuery` rewrites the address bar with `history.replaceState` and asks for nothing, for state only the browser holds — which record an overlay is showing, or a one-shot `?new=1`. A visit for one of those is a round trip for an unchanged payload, and it destroys the row element an overlay must give focus back to. |
| `lib/sidebarState.ts` | `navSlug(label)`, `groupStateKey(role, group)`, `readGroupOpen`, `writeGroupOpen`, `useSidebarRail(): Ref<boolean>`, `setSidebarRail(collapsed)` | Keys `hq.nav.rail` and `hq.nav.<role>.<group>`. |
| `lib/theme.ts` | `type ThemeMode = 'light'\|'dark'\|'system'`, `THEME_KEY = 'hq.theme'`, `THEME_MODES`, `isThemeMode`, `resolveDark`, `applyTheme`, `useTheme(): Ref<ThemeMode>`, `setTheme(next)` | `system` is the default. The same key is read by an inline script in `resources/views/app.blade.php` before first paint, so the theme never flashes. The `dark` class lives on `<html>`. |
| `lib/breadcrumb.ts` | `Crumb`, `BreadcrumbOptions`, `pathOf`, `matchNavItem`, `isIdSegment`, `humanizeSegment`, `resourceName`, `trailingCrumb`, `buildBreadcrumbs(groups, url, options)` | The top bar derives crumbs from the nav tree plus Inertia page props. |
| `lib/useNavigationPending.ts` | `useNavigationPending(delay = 200)` | See §4.3. |
| `lib/flashChannel.ts` | `useFlashAsToast()`, `flashClaimed`, `lastFlash`, `flashSeq` | Which channel says the server's flash on this screen. Calling `useFlashAsToast()` in a page's setup claims the flash for as long as that page is mounted: `FlashMessage.vue` draws nothing, and each arriving flash is read, cleared off the page props and spoken once by the toaster. It is §5.19 honoured, not bent — the message is *moved*, never duplicated. Reach for it on a screen that is a stream of small writes, or where an overlay covers the layout's alert strip. The watcher is `flush: 'sync'`, so `lastFlash` is already set when a visit's `onSuccess` runs and a caller can tell an accepted write from a refused one. |

### 4.7 Tasks — Board and Calendar (Phase 2, slice 4)

Three views of one query, each a route of its own: `/…/tasks`, `/…/tasks/board`, `/…/tasks/calendar`.
The filters travel as query parameters, which is the whole reason every controller echoes `filters`
back. **Drag is the native HTML5 API** — `package.json` carries no drag library, and adding one is a
dependency decision, not a screen's.

**Two halves of one rule.** `transitions` (`{from: [to]}`) is the ROLE half and is what the Board
makes legible *before* a drag: a lane this role could never drop into is dimmed and says so the
moment a card is lifted. The TASK half — am I assigned, am I this project's reviewer — is
`TaskPolicy`'s and is checked on the drop, so an offered drop can still be refused. Every screen
here therefore moves the card optimistically, keeps the board it moved *from*, and puts the card
back on a refusal while the server's own sentence is spoken through the flash channel (§5.19).

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `Tasks/TaskViewSwitcher.vue` | List · Board · Calendar, as **links** — the view is the URL, so a bookmark and the back button both work. | props `surface: TaskSurface`, `current: TaskView` · exports `type TaskView = 'list'\|'board'\|'calendar'` | The `tabs` slot of every Tasks `PageShell`, on both surfaces. It carries the query string across, dropping `?detail=` and `?new=` (overlay state, meaningless on the next route) and dropping `date_from`/`date_to` when the target is not the Calendar — those are the grid's **window**, and `TaskService` would read them on a List as a filter that silently clipped it to one month. Selected is `aria-current="page"` plus a `--card` pill on a `--muted` track; no tint, because the shell already spends the screen's one brand colour (§5.3). |
| `Tasks/TaskFilterBar.vue` | The chip bar all three views wear: search, Status / Priority / Project / Tag (+ Assignee on Admin), and *Overdue only* / *Show archived* as `toggle` filters in *Add filter* (brief 008; they were checkboxes). | props `filters: TaskFilters`, `statuses`, `priorities`, `projects`, `tags`, `employees?`, `placeholder`, `idPrefix`, `clearKeeps?: string[] = []` · exposes `clearFilters()` · exports `taskFiltersActive(filters)` | Above any Tasks view. `idPrefix` keeps two bars' labels apart. `clearKeeps` is what an **empty state's** *Clear filters* preserves (`group_by` on the List, the window on the Calendar); the bar's own *Clear all* is `FilterBar`'s and keeps nothing, unchanged from slice 1. `taskFiltersActive` deliberately does **not** count `date_from`/`date_to` — a grid that called its own month a filter would offer to clear September. |
| `Tasks/taskBoard.ts` | The Board's types and the two pieces of arithmetic a drop needs. | `BoardCard`, `BoardProject`, `BoardColumn`, `BoardPayload`, `TransitionMap`, `CardNeighbours` · `movesFor(status, transitions, columns)` · `asDetail(card, moves)` · `cloneColumns` · `moveCard(columns, cardId, from, to, index)` · `neighbours(column, cardId)` | Any board write. `movesFor` reads each move's label and tone off the payload's **own columns** — never a second status-to-colour map in Vue. `moveCard` computes `after_id` against the target list with the dragged card already removed, and picks the nearest card above **of the same project**: a column here is a status, but a column to the server is a *(project, status)* pair, so an anchor from another project is one `TaskService::reorder()` refuses outright. |
| `Tasks/TaskBoard.vue` | Eight lanes in lifecycle order with counts, drag between and within, under one `N tasks · N overdue` line. **No status chip row** above the lanes (removed 29 Sep 2026, brief 007) — each lane's header carries its own count. | props `board: BoardPayload`, `transitions`, `filters`, `surface`, `statuses`, `priorities`, `projects`, `tags`, `employees?`, `searchPlaceholder`, `emptyTitle`, `emptyDescription`, `addableStatuses?` · emits `open-task(id)`, `add-task(status)` | The Board pages. **Lane "Add task"** (30 Sep 2026, brief 012): a ghost `Button size="sm"` (`Plus` + *Add task*, sr-only *"to <lane>"*) at the bottom of each lane named in `addableStatuses`; the Admin Board passes `BIRTH_STATUSES` (Backlog, To do — the only statuses `StoreTaskRequest` lets a task be born in), the Employee Board passes nothing and shows none. It sits outside the card list, so `indexAt` never counts it and it is no drop zone of its own, and as a button it is in `PAN_EXCLUDE`; the page opens `QuickAddTaskModal` with `status` preset to the lane, and closing it returns focus to the button. A drop between lanes is `POST …/status` with `after_id`; inside one it is `POST …/reorder`. It mounts **one** headless `TaskStatusActions` re-bound per move rather than one per card — the dialogs are modal, and forty mounted focus traps is thirty-nine too many — and gives focus back by **card id**, because the optimistic move has already re-rendered the card that opened the dialog. Lanes are **`min-w-72 flex-1`** with lane gap **`gap-3`** (29 Sep 2026, brief 008): they share a wide screen and never drop below 18 rem, and the strip is the only thing that scrolls sideways; the page never does. |
| `Tasks/TaskBoardCard.vue` | One card: title (with the hover grip top-right; **no ⋯ menu**, removed 29 Sep 2026, brief 007), two-line description, the leave banner, then one footer row — assignee **avatars only** (primary first, at most 3 overlapped, then `+N`; unassigned is a dashed circle), the countdown (`DueCountdown`), the checklist count (`ListChecks` + `done/total`), the **subtask progress** (`ListTree` + `subtask_done_count/subtask_count`, flow F2 — live subtasks only, cancelled left out of both numbers, named *"N of M subtasks completed"*), the **comment count** (`MessageSquare` + `comment_count`, the messages in the task's discussion, named *"N comments"*) and the **attachment count** (`Paperclip` + `attachment_count`, named *"N attachments"*) — each only above zero, `text-xs text-muted-foreground`, brief 012 — the **tracked total** (`Clock` + `formatDuration(tracked_seconds)`, e.g. `3h 20m`, from a whole minute up, named *"N hours M minutes tracked"*, flow F3), the **running timers** of other people (`Timer/RunningTimers`, watchers only — the key is absent for anyone else) — and, on the right, the reader's own **▶ / ⏸ / ⏹** (`Timer/TaskTimerButton`, only when `permissions.can_track_time` or `my_timer`) followed by the priority flag with its word (`TaskPriorityFlag`, §1.4b). **No project line and no tag chips** — the drawer, the List and the filters carry both. A subtask's card (seen under *Show subtasks* or a personal scope) carries one muted `↳ <parent title>` line (`CornerDownRight`, sr-only "Subtask of") under the title, **only** when the payload has `parent`; the List's title cell carries the same line. | props `card: BoardCard`, `surface`, `moves: TaskTransition[]`, `canMoveUp`, `canMoveDown` (these three only decide whether the card is draggable), `dragging`, `busy` · emits `drag-start(event)`, `drag-end`, `open` | Only from `TaskBoard`. The two counts are `withCount`s on every query that makes a card, so they cost no query per card, and a comment rings `commented` (flow F1) so the bubble updates live. The footer wraps (`flex-wrap`) rather than overflowing: at the 288 px lane minimum a crowded card drops the flag to its own line. Names live in each avatar's tooltip and `aria-label`; the priority word is printed beside the flag. **The countdown** is `lib/dueCountdown.ts` (pure TS, cases in `tests/fixtures/due-countdown-cases.json`, shared with the Pest agreement test): one unit, floored, never `0` — a date-only due date ends at 00:00 of the next day in `page.props.app.timezone`, never the browser's zone. `DueCountdown.vue` is the **only** reader of `lib/minuteTicker.ts` (one module timer, minute-aligned, paused while the tab is hidden), so a tick never re-renders a card. **There is no keyboard move on the card**: without a mouse, status changes from the drawer's status control, and order inside a lane has no keyboard path; the title is a `Link` with `draggable="false"`, or the anchor drags its own href instead of the card. **The card is `relative`** so its `sr-only` labels are contained by it; without that they were positioned against the page at their static place in a scrolled-off column and the whole document scrolled sideways (F3 browser check). **A press on the timer never drags**: the card remembers a `pointerdown` inside `[data-card-timer]` and refuses the following `dragstart`; the Board's pan already ignores `button`. |
| `Timer/TaskTimerButton.vue` | Flow F3's ▶ / ⏸ / ⏹ for one task, on a card and in the drawer's Time section. none → `▶` *"Start timer"*; running → `⏸ 0:12:34` *"Pause timer"* + `⏹` *"Stop timer"*; paused → `▶ 0:12:34` *"Start timer"* (resumes) + `⏹`. A 422 `clock_in` answer opens the confirm **"Clock in and start?"** (*Cancel* / *Clock in and start*), drawn only by the instance that asked. | props `taskId`, `myTimer: MyTaskTimer \| null`, `variant?: 'card' \| 'panel'` (xs vs sm buttons) · emits `settled` | Wherever a task can be timed. **Its 1 s clock is local** and runs only while the reader's own timer is running, so a tick re-renders this button's text and never the card. The state is announced once (`aria-live`, *"Timer running"* / *"Timer paused"*), never per second. |
| `Timer/RunningTimers.vue` | Who else is timing a task: per person a pill with their initials avatar (`personTone`) and the running time at **minute** resolution (`formatDuration`), `⏸` when paused; the name and time are in the tooltip and accessible name (*"Yaseen: timing this task, 12m"*). | props `timers: RunningTaskTimer[]` | Card footer and the drawer's Time section, for watchers only (`running_timers` present). Reads `lib/minuteTicker.ts` — one module timer for the whole board. No percentage, ranking or comparison (Part H). |
| `Timer/TimerWidget.vue` (drawer **Time** section) | The task's total, then: the remote timer's `TimerControls` for `canTrackTime`, or `TaskTimerButton variant="panel"` for everyone else who may time it (never both), then *"Timing this task now"* + `RunningTimers` for watchers. | props `taskId`, `trackedSeconds`, `myTimer?`, `canTrackTask?`, `runningTimers?` · emits `settled` (the drawer re-reads) | Only from `TaskDetailBody`. |
| `Timer/TaskTimerPulse.vue` + `Timer/taskTimer.ts` | The office/Admin task timer's heartbeat (renderless, once per shell, module-level loop) and the client half of flow F3: types `MyTaskTimer`, `RunningTaskTimer`, `taskTimerRoutes`, `useTaskTimer(canTrackTime)`, `beatTaskTimer()`, `ownElapsed()`, `CLOCK_IN_CONFIRM_TEXT`. | — | Mounted in `AdminLayout` and `EmployeeLayout` beside `ShellLive`. One beat per full page load asks whether a timer is open, then every 60 s while one is; a remote employee's timer beats through `timer.ts` instead, and a write from a card makes that store re-read. |
| `Tasks/TaskCalendar.vue` | The month grid, drawn from the window the payload names, with one bar per `span`. | props `calendar: CalendarPayload`, `canPlan: boolean`, `surface`, `filters`, `statuses`, `priorities`, `projects`, `tags`, `employees?`, `searchPlaceholder`, `emptyTitle`, `emptyDescription` · exports `CalendarPayload`, `CalendarTask`, `CalendarSpan`, `CalendarWindow`, `toDay`, `fromDay`, `addDays`, `weekdayIndex` | The Calendar pages. It **never recomputes a span**: `visible_start`/`visible_end` arrive clipped and `continues_before`/`continues_after` say which edge it runs off, drawn as a squared edge plus a chevron — and a bar that merely carries into the next week row gets the same treatment, so the two read alike. Lanes are packed greedily in the order the payload already sorted for it. `unscheduled_count` is printed rather than dropped. Month arrows write `date_from`/`date_to` into the URL. **Date handles are live only when `can_plan`** — the same `TaskService::mayPlan()` answer that makes the fields `prohibited` in `UpdateTaskRequest` — and are drawn visibly disabled otherwise, with the reason under the grid. Nothing moves optimistically here: the bar dims until the server answers. **A bar prints its status word after the title** (`· In review`), sharing the title's truncation — `statusToneClass` gives it a `StatusBadge`'s fill and §4.2's rule for that class is that whatever takes it still says the word. The Phase 2 close-out found the bars tinted and wordless, which under §1.4's ΔE 0.16 deutan pair is a rectangle that says nothing; the `aria-label` already carried title, status and range and still does. On a one-day bar at 360 px the pair truncates, so the word is a wide-bar affordance and the `aria-label` is the guarantee. |

### 4.8 Files, tags, discussion and notifications — Phase 2, slices 4 and 5

Four features that are not tasks and not lists, each with one component that owns it. All four
were missing from this file until the Phase 2 close-out; the signatures below were read from
their own `defineProps` / `defineEmits` in that pass.

| Component | What it does | Signature | Reach for it when |
| --- | --- | --- | --- |
| `Files/FilePanel.vue` | The attachments panel, whole: the list, the picker with its size and extension hint, per-row download / replace / delete, and the version-history disclosure. It **fetches its own list** from `routes.index` on mount and after every write, so the page it sits on does not carry files in its Inertia payload. | props `routes: FileRoutes`, `canUpload?=false`, `title?='Files'`, `description?`, `emptyDescription?` · emits `changed` | The Files tab on project and client detail, and the Attachments panel on task detail. `canUpload` is the **owner record's** server-resolved `permissions.can_update` — the ability `FileService::guardMayAttach()` asks for — never a role and never an id comparison; per-file delete and replace come off each file's own `permissions`, not off this. A replaced file grows a `Version N` badge and a *Version history of …* disclosure that fetches `routes.history(id)` only when opened. |
| `Files/files.ts` | Every file endpoint, spelled once, plus the client-side validation the server repeats. | `FileSurface`, `FileOwnerKind = 'tasks'\|'projects'\|'clients'`, `FilePerson`, `FileSummary`, `FileIndexResponse`, `FileHistoryResponse`, `FileRoutes { index; store; history(id); version(id); destroy(id) }` · `fileRoutes(surface, owner, ownerId)` · `FILE_MAX_BYTES` (25 MB), `FILE_EXTENSIONS`, `FILE_ACCEPT`, `FILE_MAX_LABEL`, `rejectionFor(file)`, `iconFor(file)`, `formatUploadedAt(value)` | Any file work. **`fileRoutes` is overloaded on purpose**: the employee surface has the task routes and the two per-file routes and nothing else, so asking for an employee *project's* files is a compile error rather than a 404 somebody finds in staging. A URL on a `FileSummary` is a `temporarySignedRoute` re-checked by `FilePolicy` on every fetch — never mint a `Storage::url()` beside it. |
| `Tags/TagManagerDialog.vue` | Create, rename, recolour and delete tags, with each row showing its scope and how many tasks wear it. | props `open: boolean`, `base: string`, `projects?: TagProjectRef[]` · emits `update:open` | Only from `TaskFilterBar`, and only when the server said `canManageTags`. `base` is `/admin/tags` or `/employee/tags` — an identical four routes, because a Manager manages tags from the Employee shell, so nothing here varies by surface except the prefix. `projects` is the scopes a new tag may be created in: exactly the set `StoreTagRequest` accepts, which is why a scope picked here is never refused as "that project does not exist". Editing a row returns focus to that row's *Edit* button. |
| `Tags/TagColourSelect.vue` | The colour picker for a tag. | props `modelValue`, `colours` · emits `update:modelValue` | Only from `TagManagerDialog`. A tag's colour is a **`StatusKey` name**, not a hex (decision 2-22), so the option list reads *Cyan*, *Magenta*, *Blue* — the word is in the dialog beside the swatch, which is §5.6 on a control whose whole subject is colour. |
| `Tasks/TaskDiscussionPanel.vue` | The task's conversation: the thread oldest-first, an unread line, a composer with an optional attachment, and a manual refresh. | props `task: TaskDetail`, `surface: TaskSurface`, `discussion: TaskDiscussion` · emits `settled` | Task detail, both mounts. **`discussion.can_post` is the only thing that decides whether a composer exists** — `ConversationPolicy::post`, which delegates to `TaskPolicy::view`. Not a role, not `is_mine`, and never a `conversation_members` row: membership is read state (`last_read_at`) and grants nothing, so a row left behind on a reassigned task must buy its holder nothing. `Ctrl`/`⌘`+`Enter` posts. Brief 016: prop `composerPlacement?: 'inline'\|'footer'` — `TaskDetailBody` passes `footer` in the drawer (composer pinned to the drawer's bottom edge) and `inline` on the full task page; no placeholder on either. |
| `Tasks/MyTasks.vue` | The My Tasks body: seven bucket cards over one list of the chosen bucket. Mounted by `Pages/{Admin,Employee}/MyTasks.vue` — **one component, both surfaces**. | props `MyTasksPayload & { surface: TaskSurface }` · exports `interface MyTaskBucket { key; label; count; href }`, `interface MyTasksPayload { buckets; bucket; tasks; limit; today }`, `bucketIcon(key)`, `bucketSubline(key, count)` | The My Tasks pages, and — for `bucketIcon` / `bucketSubline` alone — the Employee dashboard's five cards, so a card and the page it opens say the same words about the same number. The strip is a `<nav>` of links to this same page: `aria-current` marks the shown bucket, the ring is the sighted half, and the heading under the strip is the third, so a bucket is never identified by a ring alone. The list **says when it is capped** rather than letting a count and a row total silently disagree. Columns are `taskColumns(['title','project','status','due_date','tags'])` — no Assignee, because every row here is already this person's. |
| `Notifications/NotificationRow.vue` | One notification, in the bell's popover and in the Center — one component, because they are one row. | props `row: NotificationRow`, `compact?=false` | Never directly; the bell and the Center render it. **The sentence is the server's**: `summary` arrives already written and already pluralised by `NotificationType::summary()`, where the count lives, and nothing here reads `count` to build a line — so `count` is printed nowhere, because it is already spoken inside `summary`. Read and unread differ by **three** carriers and no hue (§5.6): a neutral dot with an `sr-only` "Unread", `font-medium` while unread, and a *Mark read* button that exists only on an unread row. That button is a sibling of the link, not nested inside it, and its accessible name names the row. |
| `Notifications/notifications.ts` | The notification client: types, endpoints, the shared poll, and the writes. | `NotificationTabKey`, `NotificationPriorityKey`, `NotificationActor`, `NotificationRow`, `NotificationTabSummary`, `NotificationRecent`, `BellStatus = 'idle'\|'loading'\|'ready'\|'failed'\|'denied'` · `notificationRoutes { center; recent; read(id); readAll }` · `centerHref(tab?)` · `TAB_PHASE` · `priorityMark(priority)` · `relativeTime`, `exactTime` · `useNotificationBell()` → `{ unreadCount, recent, status }` · `refreshBell()`, `markRead(id, after?)`, `markAllRead()`, `openNotification(row, event)` | Anything notification-shaped. **`useNotificationBell()` owns the single 15 s interval** and stops it when nobody is looking (`visibilitychange`), so mounting the bell twice cannot start two polls; §11 is in-app only until Reverb arrives in Phase 6. `centerHref` puts the tab in the **query string** because it is shareable (§5.10). `TAB_PHASE` is the front end's calendar, not the server's: `NotificationTab::isBuilt()` says *whether* a tab can hold anything, and the phase numbers live here so they agree with `navigation/*.ts` rather than putting a second calendar in the domain. `priorityMark` is an arrow by rank **plus the word** — the same vocabulary `TaskBoardCard` uses for task priority, so there is one and not two. |
| `Pages/Shared/Notifications.vue` | The Notification Center: the tab strip (All / Tasks / Messages / Meetings / Leave / Payroll / System) with unread counts, *Mark all read*, the paginated list, and a per-tab empty state. | props `tab`, `tabs: NotificationTabSummary[]`, `unread_count`, `notifications`, `links`, `meta` | It is the **same route that answers the bell's JSON**, so the ten in the popover and the page behind them cannot disagree. A tab with no types yet is not hidden and not faked: `is_built` comes from the server and `TAB_PHASE` supplies the number, so an empty Payroll tab says *Arrives in Phase 9* rather than reading as a bug. |

**A `TabsContent` needs its ring put back.** Reka gives every tab panel `tabindex="0"` so a
keyboard can reach what the strip controls, and `Components/ui/tabs/TabsContent.vue` — a
generated file, not hand-edited — bases it on `outline-none`. A panel is therefore a tab stop
that paints nothing unless the call site adds
`rounded-lg focus-visible:ring-3 focus-visible:ring-ring/50`. Every `TabsContent` in this repo
does; the Phase 2 close-out added it to the project and client detail panels, which had been
ringless since Phase 1 and which Phase 2 widened by giving them a Files tab.

---

## 5. Never

Each one with the reason it exists. These apply to this phase and every phase after it.

1. **Never write a raw hex, `rgb()`, `hsl()` or an arbitrary colour value in a template, a class
   or a component.** Use the token class. Two reasons: a literal colour cannot follow the theme,
   so it is a light-mode bug shipped into dark mode; and a token change then has to be chased
   through thirty files. The one legal literal is inside `AppWordmark.vue`'s inlined SVG, where
   the artwork owns its three colours.
2. **Never put text on a `--brand` fill** — including `--sidebar-primary`, `--sidebar-rail`,
   `--ring` and `--chart-1`, which are all the same colour. It measures 3.50:1 in light mode
   (§2.3). Text-bearing surfaces are `--primary` (decision 0.5-22). Never "simplify" the two
   tokens back into one.
3. **Never show more than one accent hue.** `--brand` plus the neutral ramp plus the six
   `--status-*` is the entire palette. The logo spends its accent on one dot; so does a screen —
   **orange appears once per screen**, and if it appears three times, two of them are wrong.

   **The one carve-out: a DM thread.** A conversation of the viewer's own messages is a column of
   `--primary` bubbles, which is not "once per screen" by any reading. It is still the rule and not
   an exception to it, because the rule is about *spending* the accent: on every other screen the
   orange marks the one thing that matters, and in a DM the viewer's own half of the conversation
   **is** the content — the accent is carrying the one distinction the screen exists to make, which
   is who said what. Two things keep it from becoming a licence: the fill is `--primary` and never
   `--brand` (§5.2 is untouched, and §2.4 is the measurement), and the side of the thread plus the
   squared tail corner carry the same distinction without colour. Nothing else on a Messages screen
   spends orange a second time. Do not read this as permission to tint a list somewhere else.
4. **Never use off-scale spacing.** Tailwind's 4 px scale only; no `p-[13px]`, no `text-[11px]`,
   no arbitrary `w-[220px]`. Off-scale values are what make two screens built a month apart fail
   to line up.
5. **Never add a second chart library.** `@unovis/vue` is the one, chosen so that no phase is
   tempted (decision 0.5-4). Phase 10's Gantt is a custom component, not a chart. A chart also
   never hard-codes a colour — it reads `Charts/chartTokens.ts`.
6. **Never let colour be the only carrier of meaning.** Status always prints its label beside the
   dot; a `StatCard` delta always carries an arrow icon as well as its colour; a chart with more
   than one series always ships labels. §2.2 is the measurement: with the label removed, four of
   the eight status dots fail 3:1 in light mode, and the chart palette sits in the 6–8 ΔE CVD band
   where a second encoding is mandatory. §1.4 adds the sharper version of the same point: two of
   the eight statuses are ΔE 0.16 apart under deuteranopia, so a bare dot is not a status.
7. **Never import `PageHeader.vue` or `PlaceholderPanel.vue`.** They were deleted in T6 —
   `PageShell.vue` and `EmptyState.vue` replaced them. (`AGENTS.md` still lists them; it is
   stale, they do not exist.) Do not use `StatusPill.vue` in new code either: it is a thin alias
   kept only so the Phase 1 screens keep compiling.
8. **Never build a second way to do something this file already specifies.** No bespoke table, no
   bespoke empty state, no bespoke badge, no bespoke drawer, no bespoke filter bar. If the base
   component cannot do it, extend the base component.
9. **Never read or write a `localStorage` key outside `resources/js/lib/`.** All three key
   families — `hq.table.*`, `hq.nav.*`, `hq.theme` — live in `tableState.ts`, `sidebarState.ts`
   and `theme.ts`, wrapped in try/catch because Safari's private mode throws on write. A key
   written from a component is a key nobody can find, migrate or clear.
10. **Never put what is shareable into `localStorage`.** Search, filters, sort, page and the open
    drawer go in the query string, so a filtered view is a URL a colleague can open. Only what is
    personal (hidden columns, density, rail state, theme) is stored locally.
11. **Never render a control the server ignores.** Sorting and rows-per-page stay off until the
    controller reads `sort`, `dir` and `per_page` (decisions 0.5-16, 0.5-19). A control that does
    nothing is a lie in the UI.
12. **Never show a disabled control where a hidden one would do.** `QuickCreate` and
    `NotificationBell` degrade to nothing, not to greyed-out menus. Unbuilt nav rows live in the
    one closed *Coming soon* disclosure, never in their groups (decision 0.5-3).
13. **Never let one surface sit on another at the same elevation.** Three steps only:
    `shadow-flat` for rows, list items and controls, `shadow-raised` for cards and panels,
    `shadow-overlay` for dialogs, popovers, drawers and menus. No ad-hoc `shadow-xs` /
    `shadow-sm` / `shadow-md` / `shadow-lg` / `shadow-none`.

    **This rule and the repo disagreed for eleven phases and the repo was the one that was wrong**
    (POLISH-BACKLOG §E.3, §E.17; flagged by three agents in three slices). `app.css` defines exactly
    `--elevation-flat` / `-raised` / `-overlay` and exposes exactly `--shadow-flat` / `-raised` /
    `-overlay`; it defines no `shadow-xs` and never has. What it also does not do is *clear*
    Tailwind's own `--shadow-*` namespace, so `shadow-xs` compiled, looked fine, and got copied 85
    times. Since this file is generated from `app.css`, `app.css` settles it: the vocabulary is
    three and the call sites were drift. Phase 12's polish pass swept them.

    **A `<Card>` now carries its own elevation** — `Components/ui/card/Card.vue` is `shadow-raised`
    — so a page writes `<Card class="min-w-0 gap-4">` and adds no shadow class at all. That is the
    part that stops this recurring: there is no longer a shadow to get wrong at a call site.
    `tests/Unit/DesignVocabularyTest.php` greps for the off-vocabulary classes and names the file
    and line.
14. **Never ship a gradient promo, upsell or marketing card.** This is an internal tool; nobody
    in it needs to be sold to.
15. **Never put an emoji in a page heading.** The greeting text stays; `👋` does not.
16. **Never add glassmorphism, neumorphism, decorative blurs, gradient text, or a floating
    rounded dark sidebar.**
17. **Never recolour, re-round, stretch or redraw the logo.** It has exactly three colours and a
    4.6 % corner radius, all four fixed. `AppWordmark.vue` is the only way it is rendered; a dark
    surface uses `surface="dark"`, which drops the tile rather than putting a black square on
    black.
18. **Never animate a number count-up on a KPI**, and never mix the two shell models — the shell
    is light-neutral Option A in light and its own mirror in dark (decision 0.5-1), and no screen
    mixes them.
19. **Never announce one message twice.** Server flash goes through `FlashMessage.vue`; client
    actions go through `toast()`.

    The rule is "once", not "never as a toast". A screen whose every write is a server round trip
    — the task detail, where even a refused transition answers `back()->with()` — may claim the
    flash channel with `useFlashAsToast()` from `lib/flashChannel.ts`. While it is mounted
    `FlashMessage` draws nothing and each flash is **moved**: read, cleared off the page props,
    spoken once by the toaster. What is forbidden is a flash rendered *and* toasted, which is what
    happens if a screen calls `toast()` itself on a response that already carries one.
    Anything else still goes through `FlashMessage`.
20. **Never import icons from `lucide-vue-next`.** It is deprecated and not installed — icons come
    from `@lucide/vue` (decision 0-9). Generated files under `Components/ui/` are not hand-edited;
    new primitives are added only with the shadcn-vue CLI.
