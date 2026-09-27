# Runbook: cutover from ClickUp

How GoodTechies stops running on ClickUp and starts running on goodERP. Part E, Phase 12.

This is written to be followed by a person on the day, not read as a plan. Every command is one
you can paste. Where a step needs a decision rather than a command, it says so and says who
makes it.

---

## Before the day

### 1. The things only the client can supply

Cutover cannot start until these exist. They are GATE A items and they have been open since
Phase 0:

| Needed | Why cutover stops without it |
| --- | --- |
| **Real email addresses** for all five people | Their seeded `@goodtechies.test` logins are not accounts anybody can use |
| **The VPS** | There is nowhere to cut over to |
| **The backup bucket** (different provider account or region) | Part B §4; and the restore drill has never run against a real one — see `backup-restore-drill-2026-09-26.md` |
| **The ClickUp export** | `hq:import` has never seen a real one. Every column name it reads is an assumption |
| **Google Workspace answer** | Decides whether a Meet link is pasted by hand or created through the Calendar API |

### 2. Export from ClickUp

In ClickUp: the workspace → **Export** → CSV. Export the **whole** workspace, not one list — the
importer reads parent/child from one file and **a subtask whose parent row is missing is read as
a top-level row**, which means it would arrive as a client.

### 3. The decision nobody but the client can make

Open the CSV and look at the **top-level rows**. The importer's rule is *"a top-level ClickUp
task is a Client and a Project; its subtasks are Tasks"* — that is Part E's mapping and it is
right for *Buffalo Modular*, *APH*, *Abbey Heating*, *Woodfordoil*.

But the client's own workspace also has top-level rows like **SITES CREDENTIALS**, **Primary
keyword research**, **Keyword Analysis Doc** and **SEO sheet Global template**, which are plainly
internal work and not customers — and **no column in the export distinguishes them**. The
importer cannot decide this and does not try.

**So: before importing, delete from the CSV the top-level rows that are not clients**, or accept
that they arrive as clients and archive them afterwards. The dry run's *"clients that would be
created"* list is where you check this, and it is the single most important thing to read.

---

## The parallel-run window

**Run both systems for two weeks.** Not one: a fortnight covers a full recurring cycle, which is
what the retainer projects are made of, and it covers a payroll month boundary if you time it
right.

During the window:

- **ClickUp stays the system of record.** goodERP is being checked against it, not trusted yet.
- New work goes into **both**. It is duplicate effort and it is the point: the second week is
  when people notice what they cannot do yet.
- **Nobody is told to stop using ClickUp.** A cutover announced before the tool is ready
  produces a team using neither.

At the end of the window, one person decides: cut over, or extend. If anything on the
**Definition of done** list below is unmet, extend.

---

## Cutover day

### 1. Take a backup first, and verify it

```bash
cd /var/www/goodtechies-hq
php artisan backup:run --only-db
php artisan hq:verify-backup
```

Do not proceed on a failure. The import writes to a live database and the only way back is this
backup.

### 2. Dry run the import

```bash
php artisan hq:import --from=clickup --file=/root/cutover/clickup-export.csv --dry-run
```

**Read the whole report**, not the totals. Specifically:

- **WOULD CREATE → Clients.** Is every name on that list actually a customer? (See "the decision
  nobody but the client can make" above.)
- **SKIPPED.** Every row here is work that will not arrive. An unmapped status is the usual
  cause. Decide per row: fix the CSV, or accept the loss.
- **COULD NOT BE MAPPED.** Assignees that match nobody, tracked time with no employee to belong
  to, second assignees that were dropped. None of these stop the import; all of them are things
  somebody will otherwise notice a week later and call a bug.

The dry run rolls back inside a transaction, so it changes nothing. Run it as many times as it
takes.

### 3. Import for real

```bash
php artisan hq:import --from=clickup --file=/root/cutover/clickup-export.csv
```

The SKIPPED and COULD-NOT-BE-MAPPED blocks are **byte-identical** to the dry run's — that is
asserted by a test, and if they differ, stop and say so.

Notifications are **off** during an import by default. Three hundred *"you were assigned a task"*
rows at cutover is an outage of the bell, not a feature. `--notify` turns them on if you ever
want that.

It is **idempotent**: running it twice creates nothing the second time. If you are unsure whether
it completed, run it again and read the report rather than restoring the backup.

### 4. Check the numbers against ClickUp

Count clients, projects and tasks in goodERP and in the export. They will not match exactly —
the report says why, row by row — and the point is that **every difference is explained by a
line in the report**. A difference that is not is a finding.

### 5. Imported time is unapproved on purpose

Every imported `time_entries` row arrives **pending an Admin's approval**, with the reason
*"imported from ClickUp"*. Imported hours are a claim made by another system; they become the
agency's numbers when somebody signs them off. Until then `tasks.tracked_seconds` stays at zero,
which is correct and will look wrong to anybody who was not told.

Approve them at **Admin → Workforce → Time**, or leave them — they are history either way.

### 6. Real accounts

Change the five seeded emails to the real ones, and re-issue each person's password from
**Admin → Workforce → Employees → Re-issue password**. The password is shown **once**; have a way
to hand it over ready before you click.

Admins and the Accountant are walked through two-factor enrolment on their first sign-in; that is
`AUTH_TWO_FACTOR_ENFORCED` and it is not optional for those two roles (Part C §3).

### 7. Announce it

Post an announcement in goodERP itself — it is the first thing the team will see, and it proves
the messaging works. Say the date ClickUp becomes read-only.

---

## Decommissioning ClickUp

**Do not delete anything on cutover day.**

| When | What |
| --- | --- |
| Cutover day | ClickUp goes **read-only** for the team. Nobody is confused about where to work |
| Cutover + 30 days | Export the workspace **again** and keep the CSV with the backups. This is the last moment the data exists in its original shape |
| Cutover + 90 days | Cancel the ClickUp subscription |

Ninety days is chosen so that a quarterly cycle completes in goodERP before the old system is
gone. If anything is going to be found missing, it is found in the first quarter.

---

## Definition of done

Cutover is complete when all of these are true. Each maps to Part F §3's acceptance criteria.

- [ ] The real export imported, and every difference between the two systems is explained by a
      line in the import report
- [ ] All five people sign in with real email addresses and their own passwords
- [ ] Both Admins and the Accountant have completed two-factor enrolment
- [ ] A recurring cycle has generated in goodERP and the team worked it
- [ ] A payroll month has been drafted, calculated, reviewed, approved and locked
- [ ] `backup_last_verified_at` shows a verification against the **real** bucket
- [ ] ClickUp is read-only and the team has been told the date it goes away
- [ ] GATE D and GATE E are signed off

---

## If it goes wrong

The import is one transaction per run and it is idempotent, so a failed run leaves nothing
behind. If a **completed** run turns out to have been wrong — the client list was wrong, the
wrong file was used — restore the backup from step 1 rather than trying to unpick it:

```bash
# docs/runbooks/restore-from-backup.md has the full procedure
php artisan backup:list
```

That is why step 1 is a backup and a verify, and why it is step 1.
