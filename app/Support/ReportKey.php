<?php

namespace App\Support;

/**
 * The report catalogue (report contract §1).
 *
 * Part D §15 names sixteen reports. Sixteen bespoke screens would be sixteen places for a
 * total to be summed differently and for a restricted column to be forgotten once, so a report
 * here is **data in one shape** — a `ReportResult` — rendered by one generic screen. Adding a
 * report is adding a case and a builder, never a page.
 *
 * ## Sixteen cases: the eight core reports (spec §43) and the eight Part D §15 adds
 *
 * The first eight are spec §43's core (slice A). The second eight — **Completion, Leave,
 * Maintenance, SEO, Website Project, Meeting, Performance and In-app coordination** — are Part
 * D §15's remainder, added in slice B with a builder each, because a case with no builder is a
 * menu entry that 500s.
 *
 * Two of the second eight carry a definition the spec leaves open, and Part D settles both.
 * They are stated here as well as on their builders, because this is the file somebody reads
 * when they wonder what the report IS:
 *
 *   - **Performance is per PROJECT** (Part D §2's table): estimated vs tracked hours per
 *     project, completion rate per project for the window, overdue rate. Never per person —
 *     spec §30 forbids scoring and Part H §1 repeats it, so there is no per-employee row and no
 *     per-employee column, and the report takes no employee filter at all.
 *   - **In-app coordination is a COUNT, not a judgement** (Part D §15, for AC6): messages per
 *     week in task and project conversations against the team channel and DMs, per project. The
 *     80 % reading of it is the client's; this report exists to put the numbers in front of
 *     that sign-off, not to make it.
 *
 * ## No `reports.view` permission exists, and that is the design
 *
 * A report requires the permission of **the data it reads**. The Reports index lists exactly
 * the cases whose `permission()` the viewer holds, so the catalogue needs no role branch and
 * no role name appears in this file: it is a capability that decides what is on the menu, and
 * the same capability that decides whether the route answers.
 *
 * A consequence worth stating, because it looks like an omission: there is no Finance report
 * on `/admin/reports` for somebody who holds `finance.view` but cannot reach the admin
 * surface. Their finance reporting is Phase 8's `/finance/report`, on their own surface. The
 * surface middleware refuses them here before any of this is asked.
 */
enum ReportKey: string
{
    /** How much work is there, and what state is it in? */
    case Task = 'task';

    /** What did each person work on in this window? */
    case EmployeeWork = 'employee-work';

    /** Where does each project stand? */
    case Project = 'project';

    /** What is late, and by how long? */
    case Overdue = 'overdue';

    /** Who was in, late, absent or away? */
    case Attendance = 'attendance';

    /** Where did the tracked hours go? */
    case Time = 'time';

    /** What came in and what went out? */
    case Finance = 'finance';

    /** What did each period cost? */
    case Payroll = 'payroll';

    /** How much of what was started got finished, and how long did it take? */
    case Completion = 'completion';

    /** Who took leave, of what kind, and how much is left? */
    case Leave = 'leave';

    /** What happened on the maintenance retainers this period? */
    case Maintenance = 'maintenance';

    /** What happened on the SEO retainers this period? */
    case Seo = 'seo';

    /** Where does each website build stand? */
    case WebsiteProject = 'website-project';

    /** What was met about, and what came out of it? */
    case Meeting = 'meeting';

    /** Project performance — never per person (Part D §2). */
    case Performance = 'performance';

    /** How much of the talking is happening in here? */
    case InAppCoordination = 'in-app-coordination';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Task',
            self::EmployeeWork => 'Employee Work',
            self::Project => 'Project',
            self::Overdue => 'Overdue',
            self::Attendance => 'Attendance',
            self::Time => 'Time',
            self::Finance => 'Finance',
            self::Payroll => 'Payroll',
            self::Completion => 'Completion',
            self::Leave => 'Leave',
            self::Maintenance => 'Maintenance',
            self::Seo => 'SEO',
            self::WebsiteProject => 'Website Project',
            self::Meeting => 'Meeting',
            self::Performance => 'Performance',
            self::InAppCoordination => 'In-app coordination',
        };
    }

    /**
     * The sentence printed under the title — the question this report answers.
     *
     * It is part of the catalogue rather than the page because it is also what the index card
     * says, and a card promising one question that opens onto another is how somebody ends up
     * reading the wrong number.
     */
    public function question(): string
    {
        return match ($this) {
            self::Task => 'How much work is there, and what state is it in?',
            self::EmployeeWork => 'What did each person work on in this window?',
            self::Project => 'Where does each project stand?',
            self::Overdue => 'What is late, and by how long?',
            self::Attendance => 'Who was in, late, absent or away?',
            self::Time => 'Where did the tracked hours go?',
            self::Finance => 'What came in and what went out?',
            self::Payroll => 'What did each period cost?',
            self::Completion => 'How much of what was started got finished, and how long did it take?',
            self::Leave => 'Who took leave, of what kind, and how much is left?',
            self::Maintenance => 'What happened on the maintenance retainers this period?',
            self::Seo => 'What happened on the SEO retainers this period?',
            self::WebsiteProject => 'Where does each website build stand?',
            self::Meeting => 'What was met about, and what came out of it?',
            // The sentence says "project" out loud, because that is the whole of what Part D
            // §2 settles about this report and the one thing a reader must not assume it is.
            self::Performance => 'How does each project stand against its estimate, and how much of its work is done or late?',
            self::InAppCoordination => 'How much of the talking is happening in here?',
        };
    }

    /**
     * The permission this report's DATA requires — never a permission invented for the report.
     *
     * `tasks.view` for the task cuts, `attendance.manage_others` for the three that are about
     * other people's days and hours, `finance.view` and `payroll.view_others` for the two about
     * money. Each one is the key that already guards the screens those figures come from, which
     * is why a report cannot become a side door into a number its holder could not otherwise
     * read.
     *
     * Slice B adds four keys for the same reason, and each one is the key of the DATA:
     *
     *   - `leave.approve` for Leave, because the report is other people's leave. It is
     *     deliberately **not** `attendance.manage_others`: Part C §1 makes *Approve leave* and
     *     *Manage others' attendance* two different cells, and `Employee::leaveVisibleTo()`
     *     exists precisely so that taking one away does not quietly take the other's screens
     *     with it.
     *   - `meetings.use` for Meeting, the key `Meeting::visibleTo()` asks first.
     *   - `messages.use` for In-app coordination, the key `ConversationPolicy` asks first.
     *   - `projects.view` for the three project cuts and for Performance, which is a project
     *     report (Part D §2) and therefore takes the projects key rather than a person's.
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::Task, self::Overdue, self::Completion => Permission::TasksView,
            self::EmployeeWork, self::Attendance, self::Time => Permission::AttendanceManageOthers,
            self::Project, self::Maintenance, self::Seo, self::WebsiteProject, self::Performance => Permission::ProjectsView,
            self::Finance => Permission::FinanceView,
            self::Payroll => Permission::PayrollViewOthers,
            self::Leave => Permission::LeaveApprove,
            self::Meeting => Permission::MeetingsUse,
            self::InAppCoordination => Permission::MessagesUse,
        };
    }

    /**
     * What this report may be narrowed by. The filter bar is built from it, and
     * `ReportFilters` drops anything a report does not accept before it reaches a query — so a
     * hand-typed `?employee=3` on the Finance report is ignored rather than silently honoured.
     *
     * @return list<ReportFilter>
     */
    public function filters(): array
    {
        return match ($this) {
            self::Task => [ReportFilter::DateRange, ReportFilter::Employee, ReportFilter::Project, ReportFilter::Client],
            self::EmployeeWork => [ReportFilter::DateRange, ReportFilter::Employee, ReportFilter::Project],
            self::Project => [ReportFilter::DateRange, ReportFilter::Project, ReportFilter::Client],
            // No date range: "what is late" is a question about today, and a window would make
            // it a question about a window somebody chose — two different reports wearing the
            // same title.
            self::Overdue => [ReportFilter::Employee, ReportFilter::Project, ReportFilter::Client],
            self::Attendance => [ReportFilter::DateRange, ReportFilter::Employee],
            self::Time => [ReportFilter::DateRange, ReportFilter::Employee, ReportFilter::Project],
            self::Finance, self::Payroll => [ReportFilter::DateRange],
            self::Completion => [ReportFilter::DateRange, ReportFilter::Employee, ReportFilter::Project, ReportFilter::Client],
            self::Leave => [ReportFilter::DateRange, ReportFilter::Employee],
            // The three project cuts and Performance take no employee: their row is a project,
            // and an employee filter would narrow the tasks inside a row while leaving the
            // tracked hours beside them measuring everybody — two meanings in one row, which is
            // the reason 10-25 keeps hours out of Employee Work.
            self::Maintenance, self::Seo, self::WebsiteProject, self::Performance => [
                ReportFilter::DateRange, ReportFilter::Project, ReportFilter::Client,
            ],
            // No employee filter on either: a meeting's audience is computed by
            // `Meeting::visibleTo()` and a conversation's by `ConversationPolicy`, and neither
            // has a scope that answers "whose meetings or messages may I filter by". Inventing
            // one would be a new access rule written in a report (contract §4 rule 1), so the
            // filter is absent rather than guessed. No client either: neither table has one.
            self::Meeting, self::InAppCoordination => [ReportFilter::DateRange, ReportFilter::Project],
        };
    }

    public function group(): ReportGroup
    {
        return match ($this) {
            // Meeting and In-app coordination sit under Work rather than Workforce: their rows
            // are projects and conversations, not people, and a heading is the first thing that
            // says so. `ReportGroup` is frozen at three, so neither gets a heading of its own.
            self::Task, self::Project, self::Overdue, self::Completion, self::Maintenance,
            self::Seo, self::WebsiteProject, self::Performance, self::Meeting,
            self::InAppCoordination => ReportGroup::Work,
            self::EmployeeWork, self::Attendance, self::Time, self::Leave => ReportGroup::Workforce,
            self::Finance, self::Payroll => ReportGroup::Money,
        };
    }

    public function accepts(ReportFilter $filter): bool
    {
        return in_array($filter, $this->filters(), true);
    }

    /**
     * The catalogue in the order the index draws it — grouped, and within a group in the order
     * Part D §15 names them.
     *
     * @return list<self>
     */
    public static function inDisplayOrder(): array
    {
        return [
            self::Task,
            self::Project,
            self::Overdue,
            self::Completion,
            self::Maintenance,
            self::Seo,
            self::WebsiteProject,
            self::Performance,
            self::Meeting,
            self::InAppCoordination,
            self::EmployeeWork,
            self::Attendance,
            self::Time,
            self::Leave,
            self::Finance,
            self::Payroll,
        ];
    }

    /**
     * The card the index draws for this report. No counts and no preview: a card that showed a
     * figure would be a sixteenth place a total is stated, and it would be stated without any
     * of the filters the reader is about to choose.
     *
     * @return array{key: string, label: string, question: string, group: string, filters: list<string>}
     */
    public function toCard(): array
    {
        return [
            'key' => $this->value,
            'label' => $this->label(),
            'question' => $this->question(),
            'group' => $this->group()->value,
            'filters' => array_map(
                fn (ReportFilter $filter): string => $filter->value,
                $this->filters(),
            ),
        ];
    }
}
