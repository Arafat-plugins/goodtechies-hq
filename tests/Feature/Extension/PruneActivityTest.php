<?php

use App\Models\ActivitySample;
use App\Models\ActivitySite;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;

/*
| `hq:prune-activity` (docs/extension-api.md §8): per-minute rows past the retention setting
| go; the rollup columns stay.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension prune project']);
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension prune task']);

    $this->entry = TimeEntry::factory()->forEmployee($tapu)->onTask($task)->create();
    $this->entry->forceFill(['active_minutes' => 7, 'idle_minutes' => 2])->saveQuietly();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function EXT_PRUNE_minute(TimeEntry $entry, Carbon $at): void
{
    ActivitySample::query()->create([
        'time_entry_id' => $entry->id,
        'minute_at' => $at,
        'state' => 'active',
        'source' => 'extension',
    ]);

    ActivitySite::query()->create([
        'time_entry_id' => $entry->id,
        'minute_at' => $at,
        'kind' => 'site',
        'host' => 'docs.google.com',
        'seconds' => 60,
    ]);
}

it('deletes rows older than 90 days, keeps newer ones and leaves the rollup alone', function (): void {
    $old = Carbon::now()->subDays(91)->startOfMinute();
    $recent = Carbon::now()->subDays(10)->startOfMinute();

    EXT_PRUNE_minute($this->entry, $old);
    EXT_PRUNE_minute($this->entry, $recent);

    $this->artisan('hq:prune-activity')
        ->expectsOutput('Pruned 1 activity samples and 1 activity sites.')
        ->assertSuccessful();

    expect(ActivitySample::query()->pluck('minute_at')->map->toDateString()->all())->toBe([$recent->toDateString()])
        ->and(ActivitySite::query()->count())->toBe(1);

    $entry = $this->entry->fresh();

    expect((int) $entry->active_minutes)->toBe(7)
        ->and((int) $entry->idle_minutes)->toBe(2);
});
