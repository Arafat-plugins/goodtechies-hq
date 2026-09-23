<?php

use App\Http\Resources\FileResource;
use App\Http\Resources\TaskResource;
use App\Models\File;
use App\Models\Task;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| FileResource — the one way a file leaves the server
|--------------------------------------------------------------------------
|
| And the one change to TaskResource: the attachment count. Slice 3's board
| card printed a paperclip with nothing beside it because the server sent
| nothing; a zero that looks like a count is a lie, so the count is asserted
| here against real files and real versions.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->files = app(FileService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->firstOrFail();
});

function resourceRequest(User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('carries the metadata a files panel needs and nothing about the disk', function () {
    $file = $this->files->store($this->tapu, $this->task, UploadedFile::fake()->create('Q3 brief.pdf', 12, 'application/pdf'));

    $payload = (new FileResource($file->load('uploader')))->resolve(resourceRequest($this->admin));

    expect($payload['name'])->toBe('Q3 brief.pdf')
        ->and($payload['extension'])->toBe('pdf')
        ->and($payload['mime_type'])->toBe('application/pdf')
        ->and($payload['size'])->toBe(12 * 1024)
        ->and($payload['size_label'])->toBe('12 KB')
        ->and($payload['is_pdf'])->toBeTrue()
        ->and($payload['is_image'])->toBeFalse()
        ->and($payload['is_previewable'])->toBeTrue()
        ->and($payload['version'])->toBe(1)
        ->and($payload['is_current'])->toBeTrue()
        ->and($payload['uploaded_by'])->toBe(['id' => $this->tapu->id, 'name' => $this->tapu->name])
        ->and($payload)->not->toHaveKey('path')
        ->and($payload)->not->toHaveKey('disk')
        ->and($payload)->not->toHaveKey('checksum');
})->group('phase2');

it('mints a signed url with the expiry beside it', function () {
    $file = File::factory()->forTask($this->task)->create();

    $payload = (new FileResource($file))->resolve(resourceRequest($this->admin));

    expect($payload['url'])->toContain('/files/'.$file->id)
        ->toContain('signature=')
        ->and($payload['url_expires_at'])->toBeString();

    // The expiry in the payload is the one in the URL, not a decoration: a panel that has been
    // open past it can say the links are stale instead of finding out one click at a time.
    expect(strtotime($payload['url_expires_at']))
        ->toBeGreaterThan(now()->addMinutes(FileService::URL_TTL_MINUTES - 1)->timestamp);
})->group('phase2');

it('marks an image as previewable and a zip as not', function () {
    $image = File::factory()->forTask($this->task)->name('shot.png')->mimeType('image/png')->create();
    $zip = File::factory()->forTask($this->task)->name('handover.zip')->mimeType('application/zip')->create();

    $request = resourceRequest($this->admin);

    expect((new FileResource($image))->resolve($request)['is_image'])->toBeTrue()
        ->and((new FileResource($image))->resolve($request)['is_previewable'])->toBeTrue()
        ->and((new FileResource($zip))->resolve($request)['is_previewable'])->toBeFalse();
})->group('phase2');

it('reports the delete permission per requester', function () {
    $tapusFile = File::factory()->forTask($this->task)->uploadedBy($this->tapu)->create();

    expect((new FileResource($tapusFile))->resolve(resourceRequest($this->tapu))['permissions'])
        ->toBe(['can_delete' => true, 'can_replace' => true]);

    $adminsFile = File::factory()->forTask($this->task)->uploadedBy($this->admin)->create();

    expect((new FileResource($adminsFile))->resolve(resourceRequest($this->tapu))['permissions'])
        ->toBe(['can_delete' => false, 'can_replace' => true]);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| TaskResource
|--------------------------------------------------------------------------
*/

it('sends the attachment count on the task payload', function () {
    $this->files->store($this->admin, $this->task, UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'));
    $this->files->store($this->admin, $this->task, UploadedFile::fake()->create('b.pdf', 4, 'application/pdf'));

    $payload = $this->actingAs($this->admin)
        ->get("/admin/tasks/{$this->task->id}")
        ->assertOk()
        ->viewData('page')['props']['task'];

    expect($payload['attachment_count'])->toBe(2)
        ->and($payload['attachments'])->toHaveCount(2);
})->group('phase2');

it('counts a file once however many versions it has', function () {
    $first = $this->files->store($this->admin, $this->task, UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'));
    $this->files->replace($this->admin, $first, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'));

    $payload = $this->actingAs($this->admin)
        ->get("/admin/tasks/{$this->task->id}")
        ->assertOk()
        ->viewData('page')['props']['task'];

    expect($payload['attachment_count'])->toBe(1)
        ->and($payload['attachments'][0]['version'])->toBe(2);
})->group('phase2');

it('sends the count on every list view and no attachments with it', function (string $path, string $prop) {
    $this->files->store($this->admin, $this->task, UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'));

    $props = $this->actingAs($this->admin)->get($path)->assertOk()->viewData('page')['props'];

    $tasks = collect(data_get($props, $prop))->flatMap(fn (array $group): array => $group['tasks']);
    $mine = $tasks->firstWhere('id', $this->task->id);

    expect($mine['attachment_count'])->toBe(1)
        // A board of two hundred cards must not sign two hundred URLs to draw a paperclip.
        ->and($mine)->not->toHaveKey('attachments');
})->with([
    'the list' => ['/admin/tasks', 'tasks.groups'],
    'the board' => ['/admin/tasks/board', 'board.columns'],
])->group('phase2');

it('reads zero when a task has no files', function () {
    $bare = Task::factory()->create();

    $payload = (new TaskResource($bare))->resolve(resourceRequest($this->admin));

    expect($payload['attachment_count'])->toBe(0);
})->group('phase2');
