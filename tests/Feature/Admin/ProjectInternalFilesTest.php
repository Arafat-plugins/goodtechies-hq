<?php

use App\Exceptions\FileStateException;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Attachments on a project's internal notes
|--------------------------------------------------------------------------
|
| Visible only to whoever may see the internal notes (ProjectPolicy::viewCommercial):
| never in the project's Files tab, never to an Employee, never to the Accountant.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // A project Tapu can see: one of his tasks is on it, so he is in its channel too.
    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $this->project = Project::findOrFail($this->task->project_id);
    $this->project->members()->syncWithoutDetaching([$this->tapu->employee->id]);
});

function internal_upload(string $name = 'contract.pdf', int $kilobytes = 8, string $mime = 'application/pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, $mime);
}

/** Upload a PDF and a PNG to the internal notes as the Admin. */
function attach_internal_pair(object $test): void
{
    $test->actingAs($test->admin)
        ->post("/admin/projects/{$test->project->id}/internal-files", ['file' => internal_upload('secret-contract.pdf')])
        ->assertRedirect()
        ->assertSessionHas('success', 'File added to the internal notes.');

    $test->actingAs($test->admin)
        ->post("/admin/projects/{$test->project->id}/internal-files", [
            'file' => UploadedFile::fake()->image('secret-screenshot.png', 20, 20),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
}

it('lists internal uploads in the internal index and never in the Files tab', function () {
    attach_internal_pair($this);

    $internal = $this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/internal-files")
        ->assertOk()
        ->json('files');

    expect(collect($internal)->pluck('name')->sort()->values()->all())
        ->toBe(['secret-contract.pdf', 'secret-screenshot.png'])
        ->and(File::where('project_id', $this->project->id)->where('internal', true)->count())->toBe(2);

    $tab = $this->actingAs($this->admin)
        ->getJson("/admin/projects/{$this->project->id}/files")
        ->assertOk()
        ->json('files');

    expect(collect($tab)->pluck('name')->all())
        ->not->toContain('secret-contract.pdf')
        ->not->toContain('secret-screenshot.png');

    // And an ordinary project file stays out of the internal index.
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/files", ['file' => internal_upload('public-brief.pdf')])
        ->assertSessionHas('success');

    expect(collect($this->actingAs($this->admin)->getJson("/admin/projects/{$this->project->id}/internal-files")->json('files'))->pluck('name')->all())
        ->not->toContain('public-brief.pdf');
})->group('phase2');

it('hides internal attachments from an employee on the project', function () {
    attach_internal_pair($this);
    $file = File::where('internal', true)->where('name', 'secret-contract.pdf')->firstOrFail();

    $this->actingAs($this->tapu)
        ->getJson("/admin/projects/{$this->project->id}/internal-files")
        ->assertForbidden();

    $status = $this->actingAs($this->tapu)
        ->get(app(FileService::class)->url($file))
        ->getStatusCode();
    expect($status)->toBeIn([403, 404]);

    // The same employee DOES get an ordinary file on the same project, so the refusal above is
    // the internal rule and not merely "employees download nothing".
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/files", ['file' => internal_upload('public-brief.pdf')]);
    $public = File::where('internal', false)->where('name', 'public-brief.pdf')->firstOrFail();
    $this->actingAs($this->tapu)->get(app(FileService::class)->url($public))->assertOk();

    // The per-file routes answer 404 for a file they may not see.
    $this->actingAs($this->tapu)->getJson("/employee/files/{$file->id}/versions")->assertNotFound();

    // The project page.
    $page = $this->actingAs($this->tapu)->get("/employee/projects/{$this->project->id}")->assertOk();
    expect($page->getContent())
        ->not->toContain('secret-contract.pdf')
        ->not->toContain('secret-screenshot.png');

    // The project channel's attachments.
    $channel = app(ConversationService::class)->forProject($this->project);
    $context = $this->actingAs($this->tapu)->getJson("/messages/{$channel->id}/context")->assertOk();
    expect(collect($context->json('files'))->pluck('name')->all())
        ->not->toContain('secret-contract.pdf')
        ->not->toContain('secret-screenshot.png');

    // The Files tab, through the service every Files tab reads.
    expect(app(FileService::class)->for($this->project)->pluck('name')->all())
        ->not->toContain('secret-contract.pdf');
})->group('phase2');

it('refuses the accountant both internal routes', function () {
    attach_internal_pair($this);

    $this->actingAs($this->accountant)
        ->getJson("/admin/projects/{$this->project->id}/internal-files")
        ->assertForbidden();

    $this->actingAs($this->accountant)
        ->post("/admin/projects/{$this->project->id}/internal-files", ['file' => internal_upload()])
        ->assertForbidden();

    expect(File::where('internal', true)->count())->toBe(2);
})->group('phase2');

it('lets the admin delete an internal attachment through the per-file route', function () {
    attach_internal_pair($this);
    $file = File::where('internal', true)->where('name', 'secret-contract.pdf')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete("/admin/files/{$file->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(File::find($file->id))->toBeNull();
    Storage::disk($file->disk)->assertMissing($file->path);

    expect(collect($this->actingAs($this->admin)->getJson("/admin/projects/{$this->project->id}/internal-files")->json('files'))->pluck('name')->all())
        ->toBe(['secret-screenshot.png']);
})->group('phase2');

it('keeps a replacement version internal', function () {
    attach_internal_pair($this);
    $file = File::where('internal', true)->where('name', 'secret-contract.pdf')->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/admin/files/{$file->id}/versions", ['file' => internal_upload('secret-contract-v2.pdf')])
        ->assertSessionHas('success');

    $new = File::where('version_of', $file->id)->firstOrFail();

    expect($new->internal)->toBeTrue()
        ->and(app(FileService::class)->for($this->project)->pluck('id')->all())->not->toContain($new->id);
})->group('phase2');

it('refuses an upload to an archived project', function () {
    $archived = Project::factory()->archived()->create();

    $this->actingAs($this->admin)
        ->post("/admin/projects/{$archived->id}/internal-files", ['file' => internal_upload()])
        ->assertForbidden();

    expect(File::where('project_id', $archived->id)->count())->toBe(0);
})->group('phase2');

it('refuses an executable like any project file', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/internal-files", [
            'file' => internal_upload('installer.exe', 4, 'application/x-msdownload'),
        ])
        ->assertSessionHasErrors('file');

    expect(File::where('internal', true)->count())->toBe(0);
})->group('phase2');

it('refuses an internal file on anything but a project', function () {
    expect(fn () => app(FileService::class)->store($this->admin, $this->task, internal_upload(), internal: true))
        ->toThrow(FileStateException::class);

    expect(File::count())->toBe(0);
})->group('phase2');
