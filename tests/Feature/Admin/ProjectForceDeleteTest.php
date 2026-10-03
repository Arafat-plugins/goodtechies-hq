<?php

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\File;
use App\Models\Income;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ConversationService;
use App\Support\AuditEvent;
use App\Support\RoleName;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Deleting an archived project for good
|--------------------------------------------------------------------------
|
| Admin only, archived only, the name typed back exactly, and never while income or paid time
| hangs off the project. The cascade takes the tasks, files, time and channels; the bytes of
| every file it took are removed from disk; one `project.deleted` audit row is what is left.
|
*/

beforeEach(function () {
    $this->seed();

    $this->disk = (string) config('filesystems.default');
    Storage::fake($this->disk);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->conversations = app(ConversationService::class);

    $this->project = Project::factory()->archived()->create(['name' => 'Throwaway Retainer']);
});

function PFD_file(File $file): File
{
    Storage::disk($file->disk)->put($file->path, 'bytes');

    return $file;
}

function PFD_delete(object $test, Project $project, ?string $name, ?User $as = null)
{
    $payload = $name === null ? [] : ['confirm_name' => $name];

    return $test->actingAs($as ?? $test->admin)
        ->from('/admin/projects?archived=1')
        ->delete(route('admin.projects.destroy', $project), $payload);
}

it('deletes an archived project, everything hanging off it, and the bytes of its files', function () {
    $project = $this->project;

    $task = Task::factory()->create(['project_id' => $project->id]);
    $trashedTask = Task::factory()->create(['project_id' => $project->id]);

    $projectChannel = $this->conversations->forProject($project);
    $taskChannel = $this->conversations->forTask($task);
    $message = Message::factory()->inConversation($projectChannel)->by($this->admin)->create();
    $taskMessage = Message::factory()->inConversation($taskChannel)->by($this->admin)->create();

    // Unpaid time (a manual entry that does not count toward hours) does not block the delete.
    TimeEntry::factory()->onTask($task)->create(['counts_toward_hours' => false]);

    $files = collect([
        PFD_file(File::factory()->forProject($project)->create()),
        PFD_file(File::factory()->forTask($task)->create()),
        PFD_file(File::factory()->forTask($trashedTask)->create()),
        PFD_file(File::factory()->forMessage($message)->create()),
        PFD_file(File::factory()->forMessage($taskMessage)->create()),
    ]);

    $trashedTask->delete();

    // Something that is NOT the project's survives.
    $other = Project::factory()->archived()->create();
    $keep = PFD_file(File::factory()->forProject($other)->create());

    PFD_delete($this, $project, '  Throwaway Retainer  ')
        ->assertRedirect('/admin/projects?archived=1')
        ->assertSessionHas('success', 'Project deleted permanently.');

    expect(Project::find($project->id))->toBeNull()
        ->and(Task::withTrashed()->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(File::withTrashed()->whereIn('id', $files->pluck('id'))->exists())->toBeFalse()
        ->and(Conversation::whereIn('id', [$projectChannel->id, $taskChannel->id])->exists())->toBeFalse()
        ->and(Message::whereIn('id', [$message->id, $taskMessage->id])->exists())->toBeFalse()
        ->and(TimeEntry::where('project_id', $project->id)->exists())->toBeFalse();

    foreach ($files as $file) {
        Storage::disk($this->disk)->assertMissing($file->path);
    }

    Storage::disk($this->disk)->assertExists($keep->path);
    expect(File::find($keep->id))->not->toBeNull();

    $audit = AuditLog::where('event', AuditEvent::ProjectDeleted->value)->latest('id')->firstOrFail();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe($project->id)
        ->and($audit->old_value['name'])->toBe('Throwaway Retainer')
        ->and($audit->old_value['tasks'])->toBe(2)
        ->and($audit->old_value['files'])->toBe(5);
})->group('projects-delete');

it('refuses a project that is not archived', function () {
    $active = Project::factory()->create(['name' => 'Live Work']);

    PFD_delete($this, $active, 'Live Work')
        ->assertRedirect('/admin/projects?archived=1')
        ->assertSessionHas('error', 'Archive the project before deleting it.');

    expect(Project::find($active->id))->not->toBeNull()
        ->and(AuditLog::where('event', AuditEvent::ProjectDeleted->value)->exists())->toBeFalse();
})->group('projects-delete');

it('refuses a name that is not typed exactly', function (string $typed) {
    PFD_delete($this, $this->project, $typed)
        ->assertSessionHas('error', 'Type the project name exactly to delete it.');

    expect(Project::find($this->project->id))->not->toBeNull();
})->with(['wrong name' => 'Something Else', 'wrong case' => 'throwaway retainer'])->group('projects-delete');

it('refuses a project with income recorded against it', function () {
    Income::factory()->forProject($this->project)->create();

    PFD_delete($this, $this->project, 'Throwaway Retainer')
        ->assertSessionHas('error', "Income is recorded against this project, so it can't be deleted. Keep it archived.");

    expect(Project::find($this->project->id))->not->toBeNull();
})->group('projects-delete');

it('refuses a project with paid time recorded on it', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    TimeEntry::factory()->onTask($task)->create(['counts_toward_hours' => true]);

    PFD_delete($this, $this->project, 'Throwaway Retainer')
        ->assertSessionHas('error', "Paid time is recorded on this project, so it can't be deleted. Keep it archived.");

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and(Task::find($task->id))->not->toBeNull();
})->group('projects-delete');

it('is closed to every role but the Admin', function (string $who) {
    $user = match ($who) {
        'manager' => Employee::factory()->forRole(RoleName::MANAGER)->create()->user->fresh(),
        'employee' => User::where('email', 'yaseen@goodtechies.test')->firstOrFail(),
        'remote employee' => User::where('email', 'tapu@goodtechies.test')->firstOrFail(),
        'accountant' => User::where('email', 'accountant@goodtechies.test')->firstOrFail(),
    };

    PFD_delete($this, $this->project, 'Throwaway Retainer', $user)->assertForbidden();

    expect(Project::find($this->project->id))->not->toBeNull();
})->with(['manager', 'employee', 'remote employee', 'accountant'])->group('projects-delete');

it('requires the typed name', function () {
    PFD_delete($this, $this->project, null)->assertSessionHasErrors('confirm_name');

    expect(Project::find($this->project->id))->not->toBeNull();
})->group('projects-delete');

it('offers the delete only on an archived project, and only to the Admin', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.projects.index', ['archived' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', 'Throwaway Retainer')
            ->where('projects.data.0.permissions.can_force_delete', true),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('projects.data.0.permissions.can_force_delete', false),
        );
})->group('projects-delete');
