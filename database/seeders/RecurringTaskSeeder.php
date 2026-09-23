<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Support\RecurrenceRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The agency's three retainer templates (master prompt Phase 3 seed list).
 *
 * ## Idempotent, because the user's launcher reseeds on every start
 *
 * `start-hq.bat` runs `db:seed` every time the machine boots the app. A seeder that used a raw
 * `create()` would hand them a fourth, fifth and sixth copy of every template, and the engine
 * would then generate three copies of October's maintenance task — all of them legitimate, since
 * the unique index is per template. So every row is `updateOrCreate` keyed on (project, title
 * template), and the rule, assignee and checklist are REFRESHED on each run rather than only
 * written on creation: an edited seeder should change the seeded data, not sit behind a row that
 * already exists.
 *
 * ## It seeds templates and no instances
 *
 * Nothing here generates a task. `hq:generate-recurring-tasks` does that, and a seeder that
 * pre-generated a month would be a second creation path with none of the engine's rules — which
 * is exactly what this phase exists to make impossible. A demo therefore starts with three
 * templates and an empty generation log, and one command fills both.
 */
class RecurringTaskSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $row) {
            $project = Project::where('name', $row['project'])->first();
            $assignee = Employee::where('employee_number', $row['assignee'])->first();

            // A demo database missing a project is not an error worth stopping a seed for; the
            // template simply has nowhere to live.
            if ($project === null) {
                continue;
            }

            $rule = $row['rule'];

            $template = RecurringTask::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'title_template' => $row['title_template'],
                ],
                [
                    'checklist_template' => $row['checklist'],
                    'frequency' => $rule->frequency,
                    'recurrence_rule' => $rule->toArray(),
                    'default_assignee_id' => $assignee?->id,
                    // The template's author, and therefore the actor the engine creates its
                    // instances as: the project's PM, who is who would have made these by hand.
                    'created_by' => $project->pm?->user_id,
                    'active' => true,
                ],
            );

            $template->refreshNextRunAt(Carbon::today());
        }
    }

    /**
     * The three retainers the spec names, with the owners it names.
     *
     * Every title carries `{period}`, so two consecutive months produce two tasks a person can
     * tell apart in a list — which is the whole visible outcome of this phase.
     *
     * @return list<array{project: string, title_template: string, assignee: string, rule: RecurrenceRule, checklist: list<string>|null}>
     */
    private function templates(): array
    {
        return [
            [
                'project' => 'abc.com — Monthly Maintenance',
                'title_template' => 'abc.com Monthly Maintenance — {period}',
                // Yaseen (GT-004), the office employee who holds the maintenance projects.
                'assignee' => 'GT-004',
                // The 1st of the month, due on the last day of it.
                'rule' => RecurrenceRule::monthly(1),
                // Part D §6's own example, verbatim and in order.
                'checklist' => [
                    'WordPress core updates',
                    'Plugin updates',
                    'Backup verification',
                    'Security check',
                    'Broken-link check',
                    'Performance check',
                    'Form testing',
                    'General maintenance',
                ],
            ],
            [
                'project' => 'Heat Gap — SEO Retainer',
                'title_template' => 'Heat Gap Monthly SEO Tasks — {period}',
                // Tapu (GT-003), the remote employee on both SEO retainers.
                'assignee' => 'GT-003',
                'rule' => RecurrenceRule::monthly(1),
                'checklist' => [
                    'Rank and traffic review',
                    'Keyword gap check',
                    'On-page fixes',
                    'Content brief for the month',
                    'Backlink outreach',
                    'Monthly client report',
                ],
            ],
            [
                'project' => 'Buffalo Modular — SEO',
                'title_template' => 'Buffalo Modular Monthly SEO — {period}',
                'assignee' => 'GT-003',
                'rule' => RecurrenceRule::monthly(1),
                'checklist' => [
                    'Rank and traffic review',
                    'Model-page technical audit',
                    'Internal linking pass',
                    'Content brief for the month',
                    'Monthly client report',
                ],
            ],
        ];
    }
}
