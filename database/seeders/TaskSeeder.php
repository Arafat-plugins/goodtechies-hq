<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\User;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo tasks for the seeded dev/demo environment (master prompt Part D, Phase 2).
 *
 * Idempotent: every task is looked up by its title before it is created, and its dates are
 * refreshed on every reseed because they are relative to today() — a demo whose "overdue"
 * column empties out a week after seeding is not a demo.
 *
 * Two things this data has to do besides look real:
 *   - populate every status, so the List, Board and Calendar all have something in every
 *     column, including the two Phase 2 added (BACKLOG and CHANGES REQUESTED);
 *   - give the privacy tests something to fail on. Tapu has tasks on the SEO projects Yaseen
 *     is not a member of, and Yaseen has tasks on the maintenance projects Tapu is not on, so
 *     "an employee sees only their own tasks" is a claim with real rows behind it.
 */
class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $team = [
            'shahadat' => $this->employee('GT-001'),
            'faruk' => $this->employee('GT-002'),
            'tapu' => $this->employee('GT-003'),
            'yaseen' => $this->employee('GT-004'),
        ];

        $tags = $this->tags();

        // The demo board shows every status at once, which is a picture rather than a history:
        // these rows are not transitions and do not go through TaskService. The guard on
        // tasks.status is suspended for exactly that reason and for nothing else — application
        // code moves a task through TaskService::transition().
        Task::withoutStatusGuard(function () use ($team, $tags): void {
            foreach ($this->tasks() as $row) {
                $this->seedTask($row, $team, $tags);
            }
        });
    }

    /**
     * The four labels the spec names. All global: the agency's work is the same four kinds of
     * work whichever client it is for, so scoping them to one project would only mean
     * recreating them per project.
     *
     * @return array<string, Tag>
     */
    private function tags(): array
    {
        $tags = [];

        // Colours are StatusKey token names, resolved to CSS variables by the badge; never a
        // hex value. Branding takes `changes` (magenta) and SEO `backlog` (cyan) — the two
        // hues Phase 2 added — so the palette is used, not just declared.
        foreach ([
            'Branding' => 'changes',
            'Development' => 'progress',
            'SEO' => 'backlog',
            'Maintenance' => 'done',
        ] as $name => $colour) {
            $tags[$name] = Tag::firstOrCreate(
                ['project_id' => null, 'name' => $name],
                ['colour' => $colour],
            );
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Employee>  $team
     * @param  array<string, Tag>  $tags
     */
    private function seedTask(array $row, array $team, array $tags): void
    {
        $project = Project::where('name', $row['project'])->firstOrFail();
        $creator = $team[$row['created_by']]->user;

        $status = $row['status'];
        $completed = $status === TaskStatus::Completed;

        $task = Task::firstOrCreate(
            ['title' => $row['title']],
            [
                'project_id' => $project->id,
                'description' => $row['description'],
                'status' => $status,
                'priority' => $row['priority'],
                'created_by' => $creator?->id,
                'estimated_minutes' => $row['estimated_minutes'],
                'position' => $row['position'],
            ],
        );

        $primaryUser = $this->primaryUser($row, $team);
        $completedAt = $completed ? $this->day($row['due'])?->copy()->setTime(16, 30) : null;

        // Dates and tracked time are relative to today(), so they are refreshed on every
        // reseed rather than only on creation — same rule DemoSeeder applies to projects.
        $task->forceFill([
            'project_id' => $project->id,
            'status' => $status,
            'priority' => $row['priority'],
            'start_date' => $this->day($row['start']),
            'due_date' => $this->day($row['due']),
            'estimated_minutes' => $row['estimated_minutes'],
            'tracked_seconds' => $row['tracked_seconds'],
            'position' => $row['position'],
            // A work summary is mandatory on submit-for-review and on completion, so every
            // task that reached either state has one — and it is the PRIMARY assignee's, which
            // is what the completion rule checks. An anonymous summary would make every
            // seeded In-review task impossible to approve.
            'work_summary' => $row['work_summary'] ?? null,
            'work_summary_by' => ($row['work_summary'] ?? null) === null ? null : $primaryUser?->id,
            'work_summary_at' => ($row['work_summary'] ?? null) === null ? null : $completedAt ?? now(),
            'completed_by' => $completed ? $primaryUser?->id : null,
            'completed_at' => $completedAt,
            // The first completion, which a reopening keeps. Seeded alongside the live one so
            // the demo's completed tasks already carry the history a reopen would preserve.
            'first_work_summary' => $completed ? ($row['work_summary'] ?? null) : null,
            'first_completed_by' => $completed ? $primaryUser?->id : null,
            'first_completed_at' => $completedAt,
        ])->save();

        $assignees = [];

        foreach ($row['assignees'] as $index => $key) {
            // First listed is the primary — the one whose work summary completion needs.
            $assignees[$team[$key]->id] = ['is_primary' => $index === 0];
        }

        $task->assignees()->sync($assignees);

        $task->tags()->sync(array_map(
            fn (string $name): int => $tags[$name]->id,
            $row['tags'],
        ));

        $this->seedChecklist($task, $row['checklist'] ?? [], $this->primaryUser($row, $team));
        $this->seedLinks($task, $row['links'] ?? [], $creator);
        $this->seedDependencies($task, $row['depends_on'] ?? [], $creator);
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $items
     */
    private function seedChecklist(Task $task, array $items, ?User $ticker): void
    {
        foreach ($items as $index => [$title, $done]) {
            $item = TaskChecklistItem::firstOrCreate(
                ['task_id' => $task->id, 'title' => $title],
                ['position' => ($index + 1) * Task::POSITION_STEP],
            );

            $item->forceFill([
                'is_done' => $done,
                'completed_by' => $done ? $ticker?->id : null,
                'completed_at' => $done ? Carbon::today()->subDays(2)->setTime(11, 0) : null,
                'position' => ($index + 1) * Task::POSITION_STEP,
            ])->save();
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $links
     */
    private function seedLinks(Task $task, array $links, ?User $creator): void
    {
        foreach ($links as [$url, $label]) {
            TaskLink::firstOrCreate(
                ['task_id' => $task->id, 'url' => $url],
                ['label' => $label, 'created_by' => $creator?->id],
            );
        }
    }

    /**
     * @param  list<string>  $titles
     */
    private function seedDependencies(Task $task, array $titles, ?User $creator): void
    {
        foreach ($titles as $title) {
            $dependsOn = Task::where('title', $title)->first();

            if ($dependsOn === null) {
                continue;
            }

            $task->dependencies()->syncWithoutDetaching([
                $dependsOn->id => ['created_by' => $creator?->id, 'created_at' => now()],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Employee>  $team
     */
    private function primaryUser(array $row, array $team): ?User
    {
        $primary = $row['assignees'][0] ?? null;

        return $primary === null ? null : $team[$primary]->user;
    }

    private function day(?int $offset): ?Carbon
    {
        return $offset === null ? null : Carbon::today()->addDays($offset);
    }

    private function employee(string $employeeNumber): Employee
    {
        return Employee::where('employee_number', $employeeNumber)->firstOrFail();
    }

    /**
     * 25 tasks across the seven demo projects.
     *
     * `start` and `due` are day offsets from today, so a negative due date on an open status
     * is what makes a task overdue. Positions are sparse multiples of 1000 inside each
     * (project, status) column, which is the convention slice 2's drag endpoint continues.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(): array
    {
        return [
            // ── Buffalo Modular — Website Development (PM Shahadat) ──────────────
            [
                'project' => 'Buffalo Modular — Website Development',
                'title' => 'Rebuild the model-home gallery template',
                'description' => 'Rework the gallery to use the photography the client supplied, with lazy loading below the fold.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
                'start' => -12, 'due' => 4,
                'created_by' => 'shahadat',
                'assignees' => ['faruk'],
                'tags' => ['Development'],
                'estimated_minutes' => 960, 'tracked_seconds' => 21600, 'position' => 1000,
                'checklist' => [
                    ['Import the supplied photography', true],
                    ['Rework the grid for three breakpoints', true],
                    ['Lazy-load everything below the fold', false],
                    ['Check the gallery on a real phone', false],
                ],
                'links' => [
                    ['https://www.figma.com/file/buffalo-modular-gallery', 'Gallery design'],
                    ['https://buffalomodular.com/model-homes', 'Live gallery'],
                ],
            ],
            [
                'project' => 'Buffalo Modular — Website Development',
                'title' => 'Migrate the enquiry form to the new endpoint',
                'description' => 'Point the enquiry form at the new handler and keep the old one alive for one release.',
                'status' => TaskStatus::InReview,
                'priority' => TaskPriority::Medium,
                'start' => -9, 'due' => 2,
                'created_by' => 'shahadat',
                'assignees' => ['faruk'],
                'tags' => ['Development'],
                'estimated_minutes' => 240, 'tracked_seconds' => 12600, 'position' => 1000,
                'work_summary' => 'New handler is live behind a feature flag; old endpoint still accepts posts until the next release.',
            ],
            [
                'project' => 'Buffalo Modular — Website Development',
                'title' => 'Agree the launch brand palette with the client',
                'description' => 'Walk Karen through the three palette options and get one signed off.',
                'status' => TaskStatus::ChangesRequested,
                'priority' => TaskPriority::High,
                'start' => -20, 'due' => -2,
                'created_by' => 'shahadat',
                'assignees' => ['shahadat'],
                'tags' => ['Branding'],
                'estimated_minutes' => 180, 'tracked_seconds' => 9000, 'position' => 1000,
                'work_summary' => 'Presented all three; client wants the warm option reworked with a lighter secondary.',
            ],
            [
                'project' => 'Buffalo Modular — Website Development',
                'title' => 'Write the 404 and maintenance pages',
                'description' => 'Two static pages in the new brand, with the enquiry number on both.',
                'status' => TaskStatus::Backlog,
                'priority' => TaskPriority::Low,
                'start' => null, 'due' => 30,
                'created_by' => 'shahadat',
                'assignees' => ['faruk'],
                'tags' => ['Development', 'Branding'],
                'estimated_minutes' => 120, 'tracked_seconds' => 0, 'position' => 1000,
            ],
            [
                'project' => 'Buffalo Modular — Website Development',
                'title' => 'Set up staging on the client subdomain',
                'description' => 'staging.buffalomodular.com, behind basic auth, seeded from production.',
                'status' => TaskStatus::Completed,
                'priority' => TaskPriority::Medium,
                'start' => -30, 'due' => -21,
                'created_by' => 'shahadat',
                'assignees' => ['faruk'],
                'tags' => ['Development'],
                'estimated_minutes' => 180, 'tracked_seconds' => 10800, 'position' => 1000,
                'work_summary' => 'Staging is up behind basic auth, DNS propagated, nightly refresh from production scheduled.',
            ],

            // ── Buffalo Modular — SEO (PM Shahadat, Tapu assigned) ───────────────
            // Yaseen is not a member of this project: these are the rows the privacy tests
            // check he cannot see.
            [
                'project' => 'Buffalo Modular — SEO',
                'title' => 'Rewrite the Home Model page titles',
                'description' => 'Target the model names plus the county; UK English throughout.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
                'start' => -6, 'due' => 3,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 300, 'tracked_seconds' => 7200, 'position' => 1000,
            ],
            [
                'project' => 'Buffalo Modular — SEO',
                'title' => 'Fix the duplicate canonical tags on model pages',
                'description' => 'Eight model pages all canonicalise to the index; point each at itself.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::Urgent,
                'start' => -14, 'due' => -5,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO', 'Development'],
                'estimated_minutes' => 120, 'tracked_seconds' => 3600, 'position' => 2000,
                // The dependency demo: the titles cannot be rewritten until the canonicals are
                // fixed, so the rewrite waits for this one. Seeded on the waiting task below.
                'checklist' => [
                    ['List every page with a wrong canonical', true],
                    ['Point each model page at itself', false],
                    ['Re-crawl and confirm the canonicals resolved', false],
                    ['Ask Google to re-index the eight pages', false],
                ],
                'links' => [
                    ['https://search.google.com/search-console', 'Search Console'],
                    ['https://buffalomodular.com/sitemap.xml', 'Sitemap'],
                ],
            ],
            [
                'project' => 'Buffalo Modular — SEO',
                'title' => 'Monthly rankings report — Buffalo Modular',
                'description' => 'Pull positions for the tracked set and write the two-paragraph summary.',
                'status' => TaskStatus::InReview,
                'priority' => TaskPriority::Medium,
                'start' => -4, 'due' => 1,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 120, 'tracked_seconds' => 5400, 'position' => 1000,
                'work_summary' => 'Report drafted, 14 of 20 tracked terms up month on month; commentary written.',
            ],
            [
                'project' => 'Buffalo Modular — SEO',
                'title' => 'Build the internal link map for the county pages',
                'description' => 'One pass over the county landing pages to link them to the relevant models.',
                'status' => TaskStatus::Backlog,
                'priority' => TaskPriority::Low,
                'start' => null, 'due' => 25,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 240, 'tracked_seconds' => 0, 'position' => 1000,
                // Linking the county pages to the models is pointless while the models all
                // canonicalise to the index, so this one waits for that one. Listed after it
                // in this array because a dependency can only point at a row that exists.
                'depends_on' => ['Fix the duplicate canonical tags on model pages'],
            ],
            [
                'project' => 'Buffalo Modular — SEO',
                'title' => 'Waiting on client copy for the Devon page',
                'description' => 'Karen owes us 400 words on the Devon showroom before this page can ship.',
                'status' => TaskStatus::Waiting,
                'priority' => TaskPriority::Medium,
                'start' => -18, 'due' => -1,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 60, 'tracked_seconds' => 1800, 'position' => 1000,
            ],

            // ── Heat Gap — SEO Retainer (PM Shahadat, Tapu assigned) ─────────────
            [
                'project' => 'Heat Gap — SEO Retainer',
                'title' => 'Local pack audit for emergency plumber terms',
                'description' => 'Check the map pack for the six priority towns and log where Heat Gap sits.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
                'start' => -8, 'due' => -3,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 180, 'tracked_seconds' => 5400, 'position' => 1000,
            ],
            [
                'project' => 'Heat Gap — SEO Retainer',
                'title' => 'Claim and verify the Google Business Profile',
                'description' => 'Dave has lost the login; run the postcard verification.',
                'status' => TaskStatus::Waiting,
                'priority' => TaskPriority::Urgent,
                'start' => -25, 'due' => -10,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 60, 'tracked_seconds' => 2700, 'position' => 1000,
            ],
            [
                'project' => 'Heat Gap — SEO Retainer',
                'title' => 'Boiler-repair landing page copy',
                'description' => 'Eight hundred words, written for the emergency-callout intent.',
                'status' => TaskStatus::Todo,
                'priority' => TaskPriority::Medium,
                'start' => 1, 'due' => 9,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 240, 'tracked_seconds' => 0, 'position' => 1000,
            ],
            [
                'project' => 'Heat Gap — SEO Retainer',
                'title' => 'Quarterly backlink clean-up',
                'description' => 'Disavow the directory spam picked up last quarter.',
                'status' => TaskStatus::Completed,
                'priority' => TaskPriority::Low,
                'start' => -40, 'due' => -28,
                'created_by' => 'shahadat',
                'assignees' => ['tapu'],
                'tags' => ['SEO'],
                'estimated_minutes' => 120, 'tracked_seconds' => 8100, 'position' => 1000,
                'work_summary' => 'Disavow file updated with 31 domains and submitted; will re-check next quarter.',
            ],

            // ── Buffalo Modular — Website Maintenance (PM Faruk, Yaseen) ─────────
            // Tapu is not a member here: the mirror image of the SEO rows above.
            [
                'project' => 'Buffalo Modular — Website Maintenance',
                'title' => 'Apply the April core and plugin updates',
                'description' => 'Core, then plugins one at a time, with a restore point before each.',
                'status' => TaskStatus::Todo,
                'priority' => TaskPriority::Medium,
                'start' => 2, 'due' => 7,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 120, 'tracked_seconds' => 0, 'position' => 1000,
            ],
            [
                'project' => 'Buffalo Modular — Website Maintenance',
                'title' => 'Investigate the slow gallery page load',
                'description' => 'Gallery is taking nine seconds on mobile; find out what is blocking.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
                'start' => -5, 'due' => -1,
                'created_by' => 'faruk',
                'assignees' => ['yaseen', 'faruk'],
                'tags' => ['Maintenance', 'Development'],
                'estimated_minutes' => 180, 'tracked_seconds' => 6300, 'position' => 2000,
            ],
            [
                'project' => 'Buffalo Modular — Website Maintenance',
                'title' => 'Verify the nightly backup restores cleanly',
                'description' => 'Restore last night into the scratch environment and check the database came back.',
                'status' => TaskStatus::InReview,
                'priority' => TaskPriority::High,
                'start' => -3, 'due' => 2,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 90, 'tracked_seconds' => 4500, 'position' => 1000,
                'work_summary' => 'Restored 03:00 snapshot into scratch; row counts match production, media intact.',
            ],
            [
                'project' => 'Buffalo Modular — Website Maintenance',
                'title' => 'Renew the TLS certificate',
                'description' => 'Auto-renew has been failing since the DNS move; do it by hand and fix the hook.',
                'status' => TaskStatus::Completed,
                'priority' => TaskPriority::Urgent,
                'start' => -16, 'due' => -12,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 60, 'tracked_seconds' => 3600, 'position' => 1000,
                'work_summary' => 'Certificate renewed manually and the renewal hook repointed at the new DNS provider.',
            ],

            // ── APH — Website Maintenance (PM Faruk, Yaseen) — project on hold ────
            [
                'project' => 'APH — Website Maintenance',
                'title' => 'Hold all scheduled work pending the budget review',
                'description' => 'Nothing goes out until Priya confirms the Q4 budget; urgent breakage only.',
                'status' => TaskStatus::Waiting,
                'priority' => TaskPriority::Medium,
                'start' => -30, 'due' => null,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => null, 'tracked_seconds' => 0, 'position' => 1000,
            ],
            [
                'project' => 'APH — Website Maintenance',
                'title' => 'Contact form stopped delivering to the office inbox',
                'description' => 'Urgent breakage — the SPF record broke when they moved mail providers.',
                'status' => TaskStatus::ChangesRequested,
                'priority' => TaskPriority::Urgent,
                'start' => -7, 'due' => -4,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 90, 'tracked_seconds' => 5400, 'position' => 1000,
                'work_summary' => 'Added the new provider to SPF; reviewer wants DKIM done in the same change.',
            ],
            [
                'project' => 'APH — Website Maintenance',
                'title' => 'Archive the old promotions microsite',
                'description' => 'The 2023 promo site is still live and indexed; take it down and redirect.',
                'status' => TaskStatus::Cancelled,
                'priority' => TaskPriority::Low,
                'start' => -22, 'due' => -15,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 60, 'tracked_seconds' => 0, 'position' => 1000,
            ],

            // ── abc.com — Monthly Maintenance (PM Faruk, Yaseen) ─────────────────
            [
                'project' => 'abc.com — Monthly Maintenance',
                'title' => 'Monthly uptime and core update pass',
                'description' => 'The routine monthly pass: core, plugins, uptime report.',
                'status' => TaskStatus::Todo,
                'priority' => TaskPriority::Medium,
                'start' => 0, 'due' => 3,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 90, 'tracked_seconds' => 0, 'position' => 1000,
            ],
            [
                'project' => 'abc.com — Monthly Maintenance',
                'title' => 'Chase the expiring domain registration',
                'description' => 'abc.com renews in six weeks and the card on file has expired.',
                'status' => TaskStatus::Backlog,
                'priority' => TaskPriority::High,
                'start' => null, 'due' => 14,
                'created_by' => 'faruk',
                'assignees' => ['yaseen'],
                'tags' => ['Maintenance'],
                'estimated_minutes' => 30, 'tracked_seconds' => 0, 'position' => 1000,
            ],

            // ── GoodTechies HQ — Internal (PM Shahadat, Yaseen) ──────────────────
            [
                'project' => 'GoodTechies HQ — Internal',
                'title' => 'Write the onboarding runbook for new starters',
                'description' => 'Accounts, hardware, the first-week reading list and who to ask about what.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::Low,
                'start' => -10, 'due' => 12,
                'created_by' => 'shahadat',
                'assignees' => ['yaseen', 'shahadat'],
                'tags' => ['Branding'],
                'estimated_minutes' => 300, 'tracked_seconds' => 9900, 'position' => 1000,
            ],
            [
                'project' => 'GoodTechies HQ — Internal',
                'title' => 'Refresh the agency case-study deck',
                'description' => 'Swap in the three most recent builds and re-shoot the cover.',
                'status' => TaskStatus::Backlog,
                'priority' => TaskPriority::Low,
                'start' => null, 'due' => null,
                'created_by' => 'shahadat',
                'assignees' => ['shahadat'],
                'tags' => ['Branding'],
                'estimated_minutes' => 480, 'tracked_seconds' => 0, 'position' => 1000,
            ],
        ];
    }
}
