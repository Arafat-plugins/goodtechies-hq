# The report contract — Phase 10

> Frozen for Phase 10. Both the backend (`ReportService` + builders) and the surface
> (`Pages/Admin/Reports/*`) are written against this file and nothing else. A builder that
> needs a shape this file does not have is a finding to raise, not a shape to invent.

## Why there is a contract at all

Part D §15 names **sixteen** reports. Sixteen bespoke screens is sixteen places for a total to be
summed differently, for an empty state to be worded differently, and for a restricted column to
be forgotten once. So a report is **data in one shape**, rendered by **one** generic screen:

- adding a report in the next slice is adding a **builder**, not a page;
- every report gets the same filter bar, the same footer totals, the same empty state and the
  same ≤ 3 chart budget for free;
- the privacy rules are asked in **one** place — the builder returns only columns the viewer may
  read, and the renderer has no idea what a salary is.

## 1. `ReportKey` — the catalogue

`app/Support/ReportKey.php`, a backed string enum. Phase 10 slice A builds the **eight core**
cases (spec §43). The other eight are named in the enum's docblock as the next slice's work and
are **not** cases yet — a case with no builder is a menu entry that 500s.

| case | value | question it answers | permission | filters |
| --- | --- | --- | --- | --- |
| `Task` | `task` | How much work is there, and what state is it in? | `tasks.view` | date range, employee, project, client |
| `EmployeeWork` | `employee-work` | What did each person work on in this window? | `attendance.manage_others` | date range, employee, project |
| `Project` | `project` | Where does each project stand? | `projects.view` | date range, project, client |
| `Overdue` | `overdue` | What is late, and by how long? | `tasks.view` | employee, project, client |
| `Attendance` | `attendance` | Who was in, late, absent or away? | `attendance.manage_others` | date range, employee |
| `Time` | `time` | Where did the tracked hours go? | `attendance.manage_others` | date range, employee, project |
| `Finance` | `finance` | What came in and what went out? | `finance.view` | date range |
| `Payroll` | `payroll` | What did each period cost? | `payroll.view_others` | date range |

Each case exposes: `label()`, `question()` (the sentence above, shown under the title),
`permission()` (a `Permission` case), `filters()` (`list<ReportFilter>`), `group()`
(`ReportGroup::Work|Workforce|Money`).

**No `reports.view` permission is added.** A report requires the permission of **the data it
reads**, which is why the catalogue needs no role branch: the Reports index lists exactly the
cases whose `permission()` the viewer holds, and it is a capability that decides, not a name.

## 2. `ReportRequest` — what a report is asked

One Form Request, `app/Http/Requests/Reports/ReportRequest.php`, reading the query string:

| key | type | note |
| --- | --- | --- |
| `from`, `to` | `Y-m-d` | The date range. `to` ≥ `from`. Defaults: the current calendar month. Ignored by a report whose `filters()` omits `ReportFilter::DateRange`. |
| `employee` | int | `employees.id`. |
| `project` | int | `projects.id`. |
| `client` | int | `clients.id`. |

Anything not on the list is ignored, never passed through. An id the viewer may not see is
**not** an error: the scope simply returns nothing for it (a 404 here would confirm the row
exists — Part C §1).

## 3. `ReportResult` — what a report answers

`app/Support/ReportResult.php`, a `final readonly class`, `JsonSerializable`.

```
ReportResult {
    columns: list<ReportColumn>
    rows:    list<array<string, string|int|float|bool|null>>   // keyed by ReportColumn::$key
    totals:  ?array<string, string|int|float|null>             // footer row, same keys; null = no footer
    charts:  list<ReportChart>                                  // 0..3 — MORE THAN 3 THROWS
    notes:   list<string>                                       // caveats printed under the table
    empty:   string                                             // the empty-state sentence
}
```

`ReportColumn`:

```
ReportColumn { key: string, label: string, format: ReportFormat, align: 'start'|'end',
               labels?: map<tone, word>, link_key?: string }
```

`labels` is for a `Status` column: the tone → **the app's own word** for it. `StatusBadge`'s
default word per tone is not always this app's vocabulary, and a report that prints
*"Waiting"* in a cell under a legend reading *"Waiting / Blocked"* has said one thing twice.

`link_key` names **another key on the row** holding that cell's href — `ReportColumn::linkedBy()`.
Every count of something openable is a link, which is the rule the rest of this app follows. The
href is written by the **builder**, which already knows the scope the row came from, so a cell
cannot link somewhere the reader may not go; nothing in Vue assembles a URL from an id. It is a
second flat key rather than a nested `{value, href}` cell so that a row stays scalars — which is
what lets `totals` share a row's shape. The href key is carried and never printed as a column.

`ReportFormat` (backed string enum) — the renderer's whole vocabulary:

| case | value | row value is | rendered as |
| --- | --- | --- | --- |
| `Text` | `text` | string | as-is |
| `Number` | `number` | int | grouped digits |
| `Money` | `money` | decimal **string** from PostgreSQL | `settings.currency` + grouped |
| `Minutes` | `minutes` | int | `4h 18m` |
| `Date` | `date` | `Y-m-d` | the app's date format |
| `Percent` | `percent` | int 0..100 | `62%` |
| `Status` | `status` | a `StatusBadge` tone key | the existing badge |

**Money never becomes a float.** It leaves PostgreSQL already `::numeric(12,2)::text` and reaches
the screen as that string. Nothing in PHP and nothing in Vue adds two money values up; a total
is `SUM()` in the same query that produced the rows.

`ReportChart`:

```
ReportChart { kind: 'bar'|'donut'|'area', title: string, format: ReportFormat,
              series: list<{label: string, value: int|string, tone?: string}> }
```

`format` defaults to `Number` and is bound into one `valueFormat` the wrapper hands the chart, so
the axis, the tooltip, the legend and the screen-reader table all write a figure the same way —
and the same way the table under the chart does, because it is the same function. **A chart whose
axis reads `432` above a table reading `432h` is a chart the reader has to be told how to read**,
and a unit smuggled into the title is a caption doing a scale's job.

`bar` → `Components/Charts/BarCompare.vue`, `donut` → `DonutBreakdown.vue`, `area` →
`AreaTrend.vue`. No fourth chart component is added in this phase. **Three is the cap** (Part D
§3) and `ReportResult`'s constructor enforces it, because a budget nobody can exceed is not a
rule anyone has to remember.

## 4. `ReportService` — how a report is built

`app/Services/ReportService.php`:

```php
public function build(ReportKey $key, User $viewer, ReportFilters $filters): ReportResult
```

`ReportFilters` is the validated value object the Form Request produces. `build()` dispatches to
one private method per key and **owns no SQL of its own**.

Three rules, and each one is a test:

1. **Every query is scoped by the model's existing `visibleTo()` scope.** No report writes a new
   access rule. If a report needs an access question nobody has asked before, that is a finding.
2. **Every figure the app already states elsewhere is read from the service that states it.**
   The Finance report's month totals come from `FinanceService`; attendance statuses come from
   `AttendanceService`; task buckets are `TaskBucket`, not re-written predicates. A report is a
   new *cut*, never a second opinion.
3. **No productivity score, no ranking, no per-person target percentage** (Part H §1). The
   Employee Work report is ordered **by name**, not by hours — a table sorted by output is a
   league table whatever the column is called.

## 5. The surface

- `GET /admin/reports` → `Admin/Reports/Index` — the catalogue, grouped, each card a title, its
  question, and the filters it accepts. Only the reports the viewer's permissions allow.
- `GET /admin/reports/{report}` → `Admin/Reports/Show` — **one** page component for all sixteen:
  `FilterBar` built from `filters`, `DataTable` built from `columns`/`rows`, a footer row from
  `totals`, the charts, the notes, the empty state. It knows `ReportFormat` and nothing else.
- `GET /employee/reports` → `Employee/Reports` (route name `employee.reports`) — self-scoped, a different screen and not a filtered
  copy of the above (Part D §15 names a different list): My Tasks / Completed / Pending /
  Overdue as linked counts, a **Time** section present only for timer roles, and a weekly or
  monthly work summary picked with `?period=week|month`.

**Absent, not zero.** A section the viewer may not have — the Time block for an office
employee — is **absent from the payload**, not sent as zeroes (Part C §1). An office employee's
`0h 0m` would be a fact about their tracking mode dressed up as a fact about their work.

## 6. What is NOT in this phase

No PDF and no CSV export: spec §44 puts exports in post-MVP Phase 2. The Reports screens have no
export control at all — not a disabled one, which is a promise with a date nobody set.
