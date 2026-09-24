<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\ProjectMember;
use App\Services\ConversationService;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\ClientStatus;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo clients and projects for the seeded dev/demo environment (master prompt Part D §4, §20).
 * Idempotent: every record is looked up by a stable key (client/project name) before it is created.
 */
class DemoSeeder extends Seeder
{
    /**
     * Seed the demo clients, their projects, finance rows and project members.
     */
    public function run(): void
    {
        $shahadat = $this->employee('GT-001');
        $faruk = $this->employee('GT-002');
        $tapu = $this->employee('GT-003');
        $yaseen = $this->employee('GT-004');

        foreach ($this->clients() as $clientData) {
            $client = Client::firstOrCreate(
                ['name' => $clientData['name']],
                [
                    'contact_info' => $clientData['contact_info'],
                    'internal_notes' => $clientData['internal_notes'],
                    'status' => ClientStatus::Active,
                ],
            );

            foreach ($clientData['projects'] as $projectData) {
                $this->seedProject($projectData, $client, compact('shahadat', 'faruk', 'tapu', 'yaseen'));
            }
        }

        $this->seedProject($this->internalProject(), null, compact('shahadat', 'faruk', 'tapu', 'yaseen'));
    }

    /**
     * @param  array<string, mixed>  $projectData
     * @param  array{shahadat: Employee, faruk: Employee, tapu: Employee, yaseen: Employee}  $team
     */
    private function seedProject(array $projectData, ?Client $client, array $team): void
    {
        $attributes = [
            'client_id' => $client?->id,
            'domain' => $projectData['domain'] ?? null,
            'project_type' => $projectData['project_type'],
            'billing_type' => $projectData['billing_type'],
            'start_date' => $projectData['start_date'],
            'deadline' => $projectData['deadline'],
            'status' => $projectData['status'] ?? ProjectStatus::Active,
            'pm_id' => $team[$projectData['pm']]->id,
            'internal_notes' => $projectData['internal_notes'],
            'employee_notes' => $projectData['employee_notes'] ?? null,
        ];

        if (isset($projectData['priority'])) {
            $attributes['priority'] = $projectData['priority'];
        }

        $project = Project::firstOrCreate(['name' => $projectData['name']], $attributes);

        // Dates are relative to today(), so keep them fresh on every reseed instead of only on creation.
        $updates = [
            'start_date' => $projectData['start_date'],
            'deadline' => $projectData['deadline'],
        ];

        if (isset($projectData['priority'])) {
            $updates['priority'] = $projectData['priority'];
        }

        $project->update($updates);

        foreach ($projectData['members'] as $memberKey) {
            ProjectMember::firstOrCreate([
                'project_id' => $project->id,
                'employee_id' => $team[$memberKey]->id,
            ]);
        }

        if (isset($projectData['finance'])) {
            ProjectFinance::firstOrCreate(
                ['project_id' => $project->id],
                $projectData['finance'],
            );
        }

        // The project's channel (Phase 6). These rows do not go through ProjectService — they
        // are `firstOrCreate`d straight onto the table — so the guarantee that a project is
        // born with somewhere to talk about it has to be restated here, exactly as TaskSeeder
        // restates it for a task's discussion. `forProject()` is a firstOrCreate behind a
        // unique index, which is what makes restating it safe on the launcher's
        // reseed-every-start.
        app(ConversationService::class)->forProject($project);
    }

    private function employee(string $employeeNumber): Employee
    {
        return Employee::where('employee_number', $employeeNumber)->firstOrFail();
    }

    /**
     * @return list<array{name: string, contact_info: list<array{name: string, role: string, email: string, phone: string}>, internal_notes: string, projects: list<array<string, mixed>>}>
     */
    private function clients(): array
    {
        return [
            [
                'name' => 'Buffalo Modular Homes',
                'contact_info' => [[
                    'name' => 'Karen Buffalo',
                    'role' => 'Marketing Manager',
                    'email' => 'karen@buffalomodular.test',
                    'phone' => '+44 7700 900111',
                ]],
                'internal_notes' => 'Long-standing client; pays on time, prefers email over calls.',
                'projects' => [
                    [
                        'name' => 'Buffalo Modular — Website Development',
                        'domain' => 'buffalomodular.com',
                        'project_type' => ProjectType::WebsiteDevelopment,
                        'billing_type' => BillingType::OneTime,
                        'start_date' => Carbon::today()->subMonths(2),
                        'deadline' => Carbon::today()->addWeeks(6),
                        'pm' => 'shahadat',
                        'members' => [],
                        'internal_notes' => 'Fixed-price rebuild, invoiced 50% up front, 50% on launch.',
                        'employee_notes' => 'New build on the existing brand kit; reuse the model-home photography the client already supplied.',
                        'finance' => [
                            'price' => 4500.00,
                            'contract_terms' => '50% deposit on signing, 50% on launch. Two rounds of revisions included.',
                        ],
                    ],
                    [
                        'name' => 'Buffalo Modular — SEO',
                        'domain' => 'buffalomodular.com',
                        'project_type' => ProjectType::Seo,
                        'billing_type' => BillingType::MonthlyRecurring,
                        'start_date' => Carbon::today()->subMonths(4),
                        'deadline' => null,
                        'pm' => 'shahadat',
                        'members' => ['tapu'],
                        'internal_notes' => 'Rolling monthly retainer, invoiced on the 1st.',
                        'employee_notes' => 'Target the Home Model pages first; client prefers UK English.',
                        'finance' => [
                            'recurring_amount' => 200.00,
                            'billing_frequency' => BillingFrequency::Monthly,
                            'contract_terms' => 'Rolling monthly retainer, cancellable with 30 days notice.',
                        ],
                    ],
                    [
                        'name' => 'Buffalo Modular — Website Maintenance',
                        'domain' => 'buffalomodular.com',
                        'project_type' => ProjectType::WebsiteMaintenance,
                        'billing_type' => BillingType::MonthlyRecurring,
                        'start_date' => Carbon::today()->subMonths(6),
                        'deadline' => Carbon::today()->addWeeks(2),
                        'pm' => 'faruk',
                        'members' => ['yaseen'],
                        'internal_notes' => 'Bundled with the SEO retainer on the client\'s statement.',
                        'employee_notes' => 'Core updates and backups only; no content changes without a separate ticket.',
                        'finance' => [
                            'recurring_amount' => 80.00,
                            'billing_frequency' => BillingFrequency::Monthly,
                            'contract_terms' => 'Rolling monthly retainer, cancellable with 30 days notice.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Heat Gap Heating & Plumbing',
                'contact_info' => [[
                    'name' => 'Dave Heatgap',
                    'role' => 'Owner',
                    'email' => 'dave@heatgap.test',
                    'phone' => '+44 7700 900222',
                ]],
                'internal_notes' => 'Sole trader; slow to reply, chase invoices by phone not email.',
                'projects' => [
                    [
                        'name' => 'Heat Gap — SEO Retainer',
                        'domain' => 'heatgap.co.uk',
                        'project_type' => ProjectType::Seo,
                        'billing_type' => BillingType::MonthlyRecurring,
                        'start_date' => Carbon::today()->subMonths(3),
                        'deadline' => Carbon::today()->addDays(10),
                        'pm' => 'shahadat',
                        'members' => ['tapu'],
                        'internal_notes' => 'Invoiced quarterly in advance despite the monthly rate.',
                        'employee_notes' => 'Focus on local boiler-repair and emergency-plumber keywords.',
                        'finance' => [
                            'recurring_amount' => 350.00,
                            'billing_frequency' => BillingFrequency::Monthly,
                            'contract_terms' => 'Monthly retainer, invoiced quarterly in advance.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'APH St Albans',
                'contact_info' => [[
                    'name' => 'Priya Aph',
                    'role' => 'Office Manager',
                    'email' => 'priya@aphstalbans.test',
                    'phone' => '+44 7700 900333',
                ]],
                'internal_notes' => 'Maintenance-only client; paused new work while they review budget.',
                'projects' => [
                    [
                        'name' => 'APH — Website Maintenance',
                        'domain' => 'aphstalbans.co.uk',
                        'project_type' => ProjectType::WebsiteMaintenance,
                        'billing_type' => BillingType::MonthlyRecurring,
                        'start_date' => Carbon::today()->subMonths(8),
                        'deadline' => Carbon::today()->subDays(4),
                        'status' => ProjectStatus::OnHold,
                        'priority' => Priority::High,
                        'pm' => 'faruk',
                        'members' => ['yaseen'],
                        'internal_notes' => 'On hold at the client\'s request pending their Q4 budget review.',
                        'employee_notes' => 'No scheduled work while on hold; only respond to urgent breakage tickets.',
                        'finance' => [
                            'recurring_amount' => 60.00,
                            'billing_frequency' => BillingFrequency::Monthly,
                            'contract_terms' => 'Rolling monthly retainer, cancellable with 30 days notice.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ABC Ltd',
                'contact_info' => [[
                    'name' => 'Sam Abc',
                    'role' => 'Director',
                    'email' => 'sam@abc.test',
                    'phone' => '+44 7700 900444',
                ]],
                'internal_notes' => 'Small account, low touch; renews automatically each January.',
                'projects' => [
                    [
                        'name' => 'abc.com — Monthly Maintenance',
                        'domain' => 'abc.com',
                        'project_type' => ProjectType::WebsiteMaintenance,
                        'billing_type' => BillingType::MonthlyRecurring,
                        'start_date' => Carbon::today()->subYear(),
                        'deadline' => Carbon::today()->addDays(3),
                        'priority' => Priority::Urgent,
                        'pm' => 'faruk',
                        'members' => ['yaseen'],
                        'internal_notes' => 'Rolling monthly retainer, low priority unless the site is down.',
                        'employee_notes' => 'Core updates and uptime monitoring only.',
                        'finance' => [
                            'recurring_amount' => 75.00,
                            'billing_frequency' => BillingFrequency::Monthly,
                            'contract_terms' => 'Rolling monthly retainer, cancellable with 30 days notice.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function internalProject(): array
    {
        return [
            'name' => 'GoodTechies HQ — Internal',
            'domain' => null,
            'project_type' => ProjectType::Internal,
            'billing_type' => BillingType::OneTime,
            'start_date' => Carbon::today()->subWeeks(2),
            'deadline' => Carbon::today()->addMonths(1),
            'pm' => 'shahadat',
            // Yaseen only: Done means pins Tapu to exactly the two SEO projects.
            'members' => ['yaseen'],
            'internal_notes' => 'Internal tooling and admin; not billed to a client.',
            'employee_notes' => 'Log time here for internal meetings, training and admin.',
        ];
    }
}
