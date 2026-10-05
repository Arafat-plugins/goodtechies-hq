<?php

use App\Http\Resources\TimeEntryResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\ExtensionPairingService;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| What the extension never stores or sends (docs/extension-api.md §5): no URL, no page title,
| no hostname key — and no score of any kind.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension privacy project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension privacy task']);
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);

    $this->entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)
        ->running(Carbon::parse('2026-09-24 08:30:00'))->heartbeatAt(Carbon::now())->create();

    $pairing = app(ExtensionPairingService::class);
    $this->token = $pairing->exchange($pairing->mintCode($this->tapu->user), 'Chrome on EXT-PRIV', (string) Str::uuid())['token'];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Every key at any depth, lower-cased. */
function EXT_PRIV_keys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = strtolower($key);
        }

        $keys = [...$keys, ...EXT_PRIV_keys($child)];
    }

    return $keys;
}

it('has no url, title or hostname column in the activity tables', function (string $table): void {
    expect(array_intersect(Schema::getColumnListing($table), ['url', 'title', 'hostname']))->toBe([]);
})->with(['activity_samples', 'activity_sites', 'devices']);

it('sends no url, title or hostname key in the entry resource or the state', function (): void {
    $resource = (new TimeEntryResource($this->entry->fresh(['task', 'project'])))->resolve(Request::create('/'));

    $state = $this->withToken($this->token)->getJson('/api/timer/state')->assertOk()->json();

    expect(array_intersect(EXT_PRIV_keys($resource), ['url', 'title', 'hostname']))->toBe([])
        ->and(array_intersect(EXT_PRIV_keys($state), ['url', 'title', 'hostname']))->toBe([]);
});

it('carries no score anywhere in the state or the contract', function (): void {
    $state = $this->withToken($this->token)->getJson('/api/timer/state')->assertOk()->getContent();
    $contract = (string) file_get_contents(base_path('docs/extension-api.md'));

    foreach ([$state, $contract] as $text) {
        expect(preg_match('/score|productivity/i', $text))->toBe(0);
    }
});
