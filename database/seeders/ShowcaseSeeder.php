<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\ConversationService;
use App\Support\BillingType;
use App\Support\ClientStatus;
use App\Support\ProjectRecurrenceFrequency;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Polish 054: extra demo clients with several websites each, so the Clients list (stacked
 * project icons, task counts) and a client's page (website cards grouped by domain, services,
 * progress) can be judged with realistic data on a local machine.
 *
 * Local only: DatabaseSeeder calls it for demo seeding and never under the test suite, so no
 * count a test asserts moves. Idempotent like every seeder here — clients, projects and tasks
 * are looked up by name/title first — so the launcher's seed-every-start adds them once.
 * All names, domains and contacts are invented (.test addresses).
 */
class ShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $team = [
            'shahadat' => $this->employee('GT-001'),
            'faruk' => $this->employee('GT-002'),
            'tapu' => $this->employee('GT-003'),
            'yaseen' => $this->employee('GT-004'),
        ];

        foreach ($this->clients() as $data) {
            $client = Client::firstOrCreate(
                ['name' => $data['name']],
                [
                    'nickname' => $data['nickname'],
                    'contact_info' => [$data['contact']],
                    'internal_notes' => $data['notes'],
                    'status' => ClientStatus::Active,
                ],
            );

            foreach ($data['websites'] as $site) {
                foreach ($site['services'] as $index => $service) {
                    $project = $this->project($client, $site, $service, $team, $index);
                    $this->tasks($project, $service['tasks'], $team);
                }
            }
        }
    }

    /**
     * @param  array{name: string, domain: string}  $site
     * @param  array{type: ProjectType, tasks: list<array{0: string, 1: TaskStatus}>}  $service
     * @param  array<string, Employee>  $team
     */
    private function project(Client $client, array $site, array $service, array $team, int $index): Project
    {
        $recurring = in_array($service['type'], [ProjectType::WebsiteMaintenance, ProjectType::Seo, ProjectType::Marketing], true);
        $pm = array_values($team)[$index % count($team)];

        $project = Project::firstOrCreate(
            ['name' => $site['name'].' — '.$service['type']->label()],
            [
                'client_id' => $client->id,
                'domain' => $site['domain'],
                'project_type' => $service['type'],
                'billing_type' => $recurring ? BillingType::Recurring : BillingType::OneTime,
                'recurrence_frequency' => $recurring ? ProjectRecurrenceFrequency::Monthly : null,
                'start_date' => Carbon::today()->subDays(40 + $index * 7),
                'deadline' => Carbon::today()->addDays(10 + $index * 9),
                'status' => $service['status'] ?? ProjectStatus::Active,
                'pm_id' => $pm->id,
            ],
        );

        foreach ([$pm, $team['tapu']] as $member) {
            ProjectMember::firstOrCreate(['project_id' => $project->id, 'employee_id' => $member->id]);
        }

        app(ConversationService::class)->forProject($project);

        return $project;
    }

    /**
     * @param  list<array{0: string, 1: TaskStatus}>  $tasks
     * @param  array<string, Employee>  $team
     */
    private function tasks(Project $project, array $tasks, array $team): void
    {
        $people = array_values($team);

        foreach ($tasks as $index => [$title, $status]) {
            $assignee = $people[($project->id + $index) % count($people)];
            $completed = $status === TaskStatus::Completed;
            $user = $assignee->user;

            $task = Task::firstOrCreate(
                ['title' => $title, 'project_id' => $project->id],
                [
                    'description' => null,
                    'status' => $status,
                    'priority' => [TaskPriority::Medium, TaskPriority::High, TaskPriority::Low][$index % 3],
                    'created_by' => $team['shahadat']->user?->id,
                    'estimated_minutes' => 60 * (1 + $index % 4),
                    'position' => ($index + 1) * Task::POSITION_STEP,
                ],
            );

            $task->forceFill([
                'start_date' => Carbon::today()->subDays(6 - $index),
                'due_date' => Carbon::today()->addDays($index * 2 - 1),
                'work_summary' => in_array($status, [TaskStatus::Completed, TaskStatus::InReview], true) ? 'Done as asked; checked on desktop and phone.' : null,
                'work_summary_by' => in_array($status, [TaskStatus::Completed, TaskStatus::InReview], true) ? $user?->id : null,
                'work_summary_at' => in_array($status, [TaskStatus::Completed, TaskStatus::InReview], true) ? now() : null,
                'completed_by' => $completed ? $user?->id : null,
                'completed_at' => $completed ? now()->subDays(1) : null,
                'first_work_summary' => $completed ? 'Done as asked; checked on desktop and phone.' : null,
                'first_completed_by' => $completed ? $user?->id : null,
                'first_completed_at' => $completed ? now()->subDays(1) : null,
            ])->save();

            $task->assignees()->syncWithoutDetaching([$assignee->id => ['is_primary' => true]]);

            app(ConversationService::class)->forTask($task);
        }
    }

    private function employee(string $number): Employee
    {
        return Employee::where('employee_number', $number)->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function clients(): array
    {
        $T = TaskStatus::Todo;
        $B = TaskStatus::Backlog;
        $P = TaskStatus::InProgress;
        $R = TaskStatus::InReview;
        $W = TaskStatus::Waiting;
        $C = TaskStatus::Completed;

        return [
            [
                'name' => 'Northwind Containers',
                'nickname' => 'Northwind Group',
                'contact' => ['name' => 'Oliver Grant', 'role' => 'Owner', 'email' => 'oliver@northwind.test', 'phone' => '+1 416 555 0142'],
                'notes' => "Prefers email; replies within a day.\nFour sites under one group — keep access details in the client folder.",
                'websites' => [
                    ['name' => 'Northwind Tanks', 'domain' => 'northwindtanks.test', 'services' => [
                        ['type' => ProjectType::WebsiteMaintenance, 'tasks' => [['Monthly plugin updates', $C], ['Fix broken quote form', $P], ['Speed up product pages', $P], ['Renew SSL', $T]]],
                        ['type' => ProjectType::Seo, 'tasks' => [['Keyword map for tank sizes', $R], ['Fix duplicate titles', $T], ['Local citations', $B]]],
                        ['type' => ProjectType::Marketing, 'tasks' => [['October newsletter', $C], ['Google Ads landing page', $P]]],
                        ['type' => ProjectType::WebsiteDevelopment, 'tasks' => [['New industrial range section', $P], ['Product comparison table', $T], ['Downloadable spec sheets', $W]]],
                    ]],
                    ['name' => 'Northwind Warehouse', 'domain' => 'northwindwarehouse.test', 'services' => [
                        ['type' => ProjectType::WebsiteMaintenance, 'tasks' => [['Backup check', $C], ['Uptime alert review', $C], ['Update contact page', $T]]],
                        ['type' => ProjectType::Seo, 'tasks' => [['Warehouse location pages', $P], ['Schema for opening hours', $T]]],
                    ]],
                    ['name' => 'Northwind Freight', 'domain' => 'northwindfreight.test', 'services' => [
                        ['type' => ProjectType::WebApplication, 'tasks' => [['Shipment tracking screen', $P], ['Driver login', $R], ['Rate calculator', $T], ['Export to CSV', $B]]],
                    ]],
                    ['name' => 'Northwind Boards', 'domain' => 'northwindboards.test', 'services' => [
                        ['type' => ProjectType::WooCommerce, 'tasks' => [['Product import', $C], ['Shipping zones', $P], ['Abandoned cart email', $T]]],
                        ['type' => ProjectType::Marketing, 'tasks' => [['Instagram launch posts', $T]]],
                    ]],
                ],
            ],
            [
                'name' => 'Harbor Dental Care',
                'nickname' => 'Harbor Dental',
                'contact' => ['name' => 'Dr. Mia Chen', 'role' => 'Practice Manager', 'email' => 'mia@harbordental.test', 'phone' => '+44 7700 900222'],
                'notes' => 'Two clinics. Changes to booking pages need sign-off from Mia.',
                'websites' => [
                    ['name' => 'Harbor Dental', 'domain' => 'harbordental.test', 'services' => [
                        ['type' => ProjectType::WebsiteDevelopment, 'tasks' => [['Online booking widget', $P], ['Treatments pages', $R], ['Team photos', $W]]],
                        ['type' => ProjectType::Seo, 'tasks' => [['Google Business Profile', $C], ['Review requests', $P]]],
                    ]],
                    ['name' => 'Harbor Kids Dentist', 'domain' => 'harborkids.test', 'services' => [
                        ['type' => ProjectType::WebsiteMaintenance, 'tasks' => [['Update holiday hours', $C], ['Fix menu on phones', $T]]],
                    ]],
                ],
            ],
            [
                'name' => 'Greenleaf Landscaping',
                'nickname' => 'Greenleaf Gardens Ltd',
                'contact' => ['name' => 'Sam Patel', 'role' => 'Director', 'email' => 'sam@greenleaf.test', 'phone' => '+44 7700 900333'],
                'notes' => 'Seasonal business — campaigns peak in March and September.',
                'websites' => [
                    ['name' => 'Greenleaf Gardens', 'domain' => 'greenleafgardens.test', 'services' => [
                        ['type' => ProjectType::Marketing, 'tasks' => [['Autumn campaign', $P], ['Before/after gallery', $T], ['Leaflet design', $C]]],
                        ['type' => ProjectType::Seo, 'tasks' => [['Service area pages', $B], ['Blog: lawn care tips', $T]]],
                        ['type' => ProjectType::WebsiteMaintenance, 'tasks' => [['Monthly updates', $C]]],
                    ]],
                ],
            ],
            [
                'name' => 'Brightside Plumbing',
                'nickname' => 'Brightside Plumbing & Heating',
                'contact' => ['name' => 'Tom Riley', 'role' => 'Owner', 'email' => 'tom@brightside.test', 'phone' => '+44 7700 900444'],
                'notes' => 'New client from October. Wants weekly progress messages.',
                'websites' => [
                    ['name' => 'Brightside Plumbing', 'domain' => 'brightsideplumbing.test', 'services' => [
                        ['type' => ProjectType::WebsiteDevelopment, 'tasks' => [['Homepage design', $R], ['Emergency call banner', $P], ['Service pages', $T], ['Contact form', $T]]],
                    ]],
                    ['name' => 'Brightside Boilers', 'domain' => 'brightsideboilers.test', 'services' => [
                        ['type' => ProjectType::Seo, 'tasks' => [['Boiler repair keywords', $T]]],
                    ]],
                    ['name' => 'Brightside Shop', 'domain' => 'brightsideshop.test', 'services' => [
                        ['type' => ProjectType::WooCommerce, 'tasks' => [['Payment gateway', $W], ['Product photos', $B]]],
                    ]],
                ],
            ],
            [
                'name' => 'Atlas Fitness Studio',
                'nickname' => 'Atlas Fitness',
                'contact' => ['name' => 'Lena Novak', 'role' => 'Marketing Lead', 'email' => 'lena@atlasfit.test', 'phone' => '+1 647 555 0199'],
                'notes' => 'Class timetable changes every Monday.',
                'websites' => [
                    ['name' => 'Atlas Fitness', 'domain' => 'atlasfitness.test', 'services' => [
                        ['type' => ProjectType::WebApplication, 'tasks' => [['Member portal', $P], ['Class booking', $P], ['Payments', $T]]],
                        ['type' => ProjectType::Marketing, 'tasks' => [['January promo', $B]]],
                        ['type' => ProjectType::WebsiteMaintenance, 'tasks' => [['Timetable update', $C], ['Image compression', $C]]],
                    ]],
                ],
            ],
        ];
    }
}
