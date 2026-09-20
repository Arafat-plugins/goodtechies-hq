# DESIGN.md — design source for UI work

Tokens come from the shadcn-tokens MCP; this file only records project-specific decisions on top of them.
**Phase 0 note:** the MCP was not reachable in the build session, so the values below were
derived by hand from the shadcn (new-york-v4, Tailwind v4) defaults, with the spec palette
layered on top. `resources/css/app.css` is the implementation. When the MCP is connected,
`get_theme` reads that file back and the two must agree.

## Tokens

**Palette (client spec).** Each role lists its shadcn variable(s), then light and dark values.

| Role | Variable(s) | Light | Dark |
| --- | --- | --- | --- |
| Deep Navy (sidebar, primary) | `--sidebar`, `--primary` | `#1B4B66` = `oklch(0.393 0.069 237.0)` | sidebar `oklch(0.310 0.053 237.8)`; primary = Teal Light |
| Teal (active, accent, ring, progress) | `--sidebar-primary`, `--accent-foreground` tint, `--ring`, `--chart-2` | `#2E8B8B` = `oklch(0.584 0.085 195.1)` | Teal Light `oklch(0.675 0.095 195.1)` |
| Slate (metadata text) | `--muted-foreground`, `--sidebar-foreground` at 70 % | `#8A9BA3` = `oklch(0.679 0.023 227.7)` | same |
| Soft Grey-Blue (banding, muted surfaces) | `--muted`, `--secondary`, `--accent` | `#EEF3F5` = `oklch(0.961 0.006 223.5)` | `oklch(0.27 0.02 237)` |
| Body text | `--foreground` | `#2B2B2B` = `oklch(0.289 0 0)` | `oklch(0.97 0.005 223)` |
| Page background | `--background` | `oklch(0.985 0.003 223)` (near-white, slightly cool) | `oklch(0.2 0.02 237)` |
| Card | `--card` | `oklch(1 0 0)` | `oklch(0.24 0.025 237)` |
| Border / input | `--border`, `--input` | `oklch(0.92 0.008 223)` (hairline) | `oklch(1 0 0 / 10%)` |
| Destructive | `--destructive` | shadcn default `oklch(0.577 0.245 27.325)` | shadcn default dark |

**Status colours** (pills with a dot) come only from these variables: `--status-todo` = slate,
`--status-progress` = teal, `--status-review` = amber `oklch(0.77 0.15 75)`,
`--status-done` = green `oklch(0.63 0.13 150)`, `--status-waiting` = orange `oklch(0.7 0.16 50)`,
`--status-cancelled` = destructive. They are exposed as `bg-status-*` / `text-status-*` through
`@theme inline`.

**Charts:** use `--chart-1` … `--chart-5` in this order: navy, teal, slate, amber, green.

**Type**
- Font: Inter (`--font-sans`), loaded from fonts.bunny.net.
- Scale: Tailwind's default steps.
  - Body: `text-sm` (14/20).
  - Page title: `text-2xl font-semibold tracking-tight`.
  - Card title: `text-sm font-medium`.
  - Stat number: `text-3xl font-semibold tabular-nums`.
  - Metadata: `text-xs text-muted-foreground`.
  - Sidebar section label: `text-[11px]` is **not** allowed; use `text-xs font-medium uppercase tracking-wider text-sidebar-foreground/60` (small caps look).

**Spacing**
- Tailwind's 4 px scale only.
- Page padding: `p-4 md:p-6`.
- Card padding: `p-4` for compact cards, `p-6` for default cards.
- Gaps: `gap-4` for grids, `gap-2` inside rows.
- Control height: `h-9`, the shadcn default.

**Radius:** `--radius: 0.75rem` (12 px), so cards are `rounded-xl` and controls `rounded-md`.

**Shadow:** cards use `shadow-xs` plus a hairline `border`; overlays use `shadow-lg`.

**Decisions recorded 2026-09-17** (Phase 0 build):
- **Dark `--chart-1`:** Teal Light, so it has contrast on dark cards.
- **Dark `--sidebar-accent` / `--sidebar-border`:** white at 10 %.
- **Sidebar metadata:** `text-sidebar-foreground/70`.
- **Inertia progress bar:** uses `var(--ring)`.
- **Icons:** from `@lucide/vue`.

## Breakpoints
- `md` 768 px: two-column grids; the employee bottom content widens.
- `lg` 1024 px: the Admin sidebar becomes fixed. Below `lg` it is an off-canvas drawer opened from a top bar.
- `xl` 1280 px: four-column stat-card rows.
- Acceptance widths are **375, 768 and 1280**. No other custom breakpoints.

## Components
- **App shell:**
  - Sidebar: navy `bg-sidebar`, 256 px (`w-64`).
  - Grouped sections, each with a small-caps label.
  - Each row: icon (lucide) + label, `h-9`, `rounded-md`, `px-3`.
  - Active row: `bg-sidebar-primary text-sidebar-primary-foreground`.
  - Hover: `bg-sidebar-accent`.
  - Disabled rows ("arrives in Phase N") use `opacity-50 cursor-not-allowed` plus a `title`, and have no link.
  - Top bar: `h-14 border-b bg-card`, with the page title, the user menu and a hamburger below `lg`.
  - Admin, Employee and Accountant shells are three separate layout files.
- **Stat card:** `Card` with a label (`text-sm text-muted-foreground`), a big number (`text-3xl font-semibold`) and one sub-line (`text-xs text-muted-foreground`). A placeholder stat card shows "—" and the sub-line "arrives in Phase N".
- **Status pill:** `inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium`, with a `size-1.5 rounded-full bg-status-*` dot.
- **Form field:** `Label` above the `Input`, a required asterisk in `text-destructive`, helper text in `text-xs text-muted-foreground`, and inline errors in `text-xs text-destructive`.
- **Auth card:** centred `max-w-sm` card on `bg-muted`, with the wordmark "GoodTechies HQ" (navy, `font-semibold`) above it.
- **Data table:** shadcn table; header `text-xs uppercase text-muted-foreground`; row banding `even:bg-muted/40`; same filter/sort bar above every list (search input + filter buttons + sort select).
- **Empty state:** icon in a `bg-muted rounded-full p-3`, a title and one line of help text.

## References
`docs/design-refs/`. Master prompt Part I says what to take from each image and what to leave.
- **Shells:** `02-admin-sidebar-flowza.png`. Take the grouped labels and icon rows. Leave FlowZa's green and hardware items.
- **Dashboards:**
  - `01-admin-dashboard-flowza.png`: the greeting row and the stat-card style.
  - `05-employee-dashboard-flowza.png`: the stat cards with a sub-line.
- **Task views:**
  - `07-employee-task-kanban-moru.png`: Kanban card anatomy.
  - `08-new-task-modal.png`: modal with tabs; a full-screen sheet on phones.
- **Notifications:** `09-notifications-panel-moru.png`, right-side panel.
- **Current ClickUp:** `10-clickup-current-workspace.png`, List grouped by status.

## Never
- more than 3 charts per screen
- productivity scores, rankings, activity heatmaps or per-person comparison charts
- hex colours or arbitrary colour values in classes; `!important`
- an attendance donut or activity feed on the Admin Company dashboard
- the Accountant shell importing Admin components
- hiding restricted data only in the UI (the backend omits it)
