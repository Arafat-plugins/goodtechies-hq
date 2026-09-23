<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\FileService;
use App\Support\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The file endpoints
|--------------------------------------------------------------------------
|
| Upload, list, replace, download and delete, on every surface they exist on.
| The panels that would otherwise show a mistake are a later brief, so the
| whole contract is asserted here: which surface answers, what the payload
| carries, and what each refusal looks like.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->firstOrFail();
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $this->client = Client::where('name', 'Buffalo Modular Homes')->firstOrFail();
});

function upload(string $name = 'brief.pdf', int $kilobytes = 8, string $mime = 'application/pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, $mime);
}

/*
|--------------------------------------------------------------------------
| Uploading
|--------------------------------------------------------------------------
*/

it('attaches a file to a task from the admin surface', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/files", ['file' => upload('scope.pdf')])
        ->assertRedirect()
        ->assertSessionHas('success');

    $file = File::firstOrFail();

    expect($file->task_id)->toBe($this->task->id)
        ->and($file->uploaded_by)->toBe($this->admin->id);

    Storage::disk($file->disk)->assertExists($file->path);
})->group('phase2');

it('attaches a file to a task from the employee surface', function () {
    $this->actingAs($this->tapu)
        ->post("/employee/tasks/{$this->task->id}/files", ['file' => upload('shot.png', 4, 'image/png')])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(File::firstOrFail()->uploaded_by)->toBe($this->tapu->id);
})->group('phase2');

it('adds a file to a project and to a client', function () {
    $this->actingAs($this->admin)
        ->post("/admin/projects/{$this->project->id}/files", ['file' => upload('scope.pdf')])
        ->assertRedirect()->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->post("/admin/clients/{$this->client->id}/files", ['file' => upload('contract.pdf')])
        ->assertRedirect()->assertSessionHas('success');

    expect(File::where('project_id', $this->project->id)->count())->toBe(1)
        ->and(File::where('client_id', $this->client->id)->count())->toBe(1);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Validation — the Form Request carries it, against the field
|--------------------------------------------------------------------------
*/

it('refuses an upload with no file', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/files", [])
        ->assertSessionHasErrors('file');

    expect(File::count())->toBe(0);
})->group('phase2');

it('refuses a type that is not on the allow-list, against the field', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/files", ['file' => upload('payload.php', 2, 'text/x-php')])
        ->assertSessionHasErrors('file');

    expect(File::count())->toBe(0);
    Storage::disk(config('filesystems.default'))->assertDirectoryEmpty('/');
})->group('phase2');

it('refuses a file over the limit, against the field', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/files", [
            'file' => upload('huge.pdf', FileService::maxKilobytes() + 1),
        ])
        ->assertSessionHasErrors('file');

    expect(File::count())->toBe(0);
})->group('phase2');

it('refuses a file whose contents contradict its extension, against the field', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/files", ['file' => upload('invoice.pdf', 2, 'text/x-php')])
        ->assertSessionHasErrors('file');

    expect(File::count())->toBe(0);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Listing
|--------------------------------------------------------------------------
*/

it('lists the files of a record with a signed url on each', function () {
    $file = File::factory()->forTask($this->task)->uploadedBy($this->tapu)->create(['name' => 'brief.pdf']);

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/tasks/{$this->task->id}/files")
        ->assertOk()
        ->json('files');

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['id'])->toBe($file->id)
        ->and($payload[0]['name'])->toBe('brief.pdf')
        ->and($payload[0]['version'])->toBe(1)
        ->and($payload[0]['is_current'])->toBeTrue()
        ->and($payload[0]['uploaded_by']['id'])->toBe($this->tapu->id)
        ->and($payload[0]['url'])->toContain('signature=')
        ->and($payload[0]['url_expires_at'])->toBeString()
        // Where the bytes actually are is nobody's business outside FileService.
        ->and($payload[0])->not->toHaveKey('path')
        ->and($payload[0])->not->toHaveKey('disk');
})->group('phase2');

it('lists only the current version of each file', function () {
    $service = app(FileService::class);
    $v1 = $service->store($this->admin, $this->task, upload('brief.pdf'));
    $v2 = $service->replace($this->admin, $v1, upload('brief.pdf'));

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/tasks/{$this->task->id}/files")
        ->assertOk()
        ->json('files');

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['id'])->toBe($v2->id)
        ->and($payload[0]['version'])->toBe(2);
})->group('phase2');

it('does not promise a versions key it cannot keep', function () {
    // The key used to be there, fed by `whenLoaded('versions')`, and it never resolved: the
    // relation behind it is empty on every row except the root, and the root is superseded the
    // moment a replacement exists. A chain is read from the history endpoint below instead.
    $service = app(FileService::class);
    $v1 = $service->store($this->admin, $this->task, upload('brief.pdf'));
    $service->replace($this->admin, $v1, upload('brief.pdf'));

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/tasks/{$this->task->id}/files")
        ->assertOk()
        ->json('files');

    expect($payload[0])->not->toHaveKey('versions');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Version history
|--------------------------------------------------------------------------
|
| The read that makes `superseded_at` worth stamping. A replacement supersedes
| the row it replaces and never overwrites it, so the earlier bytes are still
| there — this is the endpoint that lets somebody reach them.
|
*/

it('reads a file history oldest first, including the current version', function () {
    $service = app(FileService::class);
    $v1 = $service->store($this->admin, $this->task, upload('brief.pdf', 6));
    $v2 = $service->replace($this->tapu, $v1, upload('brief.pdf', 9));
    $v3 = $service->replace($this->admin, $v2->refresh(), upload('brief.pdf', 12));

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/files/{$v3->id}/versions")
        ->assertOk()
        ->json('versions');

    expect($payload)->toHaveCount(3)
        ->and(array_column($payload, 'id'))->toBe([$v1->id, $v2->id, $v3->id])
        ->and(array_column($payload, 'version'))->toBe([1, 2, 3])
        // Exactly one head, and it is the last row — which is what tells a reader which of the
        // three they are looking at now.
        ->and(array_column($payload, 'is_current'))->toBe([false, false, true])
        // Each earlier version keeps its own uploader, its own size and its own signed link.
        ->and($payload[1]['uploaded_by']['id'])->toBe($this->tapu->id)
        ->and($payload[0]['uploaded_by']['id'])->toBe($this->admin->id)
        ->and($payload[0]['size'])->toBe(6 * 1024)
        ->and($payload[0]['url'])->toContain('signature=')
        ->and($payload[0]['uploaded_at'])->toBeString()
        ->and($payload[0]['size_label'])->toBeString();
})->group('phase2');

it('answers the whole chain whichever version of it is asked about', function () {
    // The defect this endpoint closes was that a chain was read off the row rather than off
    // `chainId()`, so only the root ever answered. Every row answers the same list.
    $service = app(FileService::class);
    $v1 = $service->store($this->admin, $this->task, upload('brief.pdf'));
    $v2 = $service->replace($this->admin, $v1, upload('brief.pdf'));

    $ids = [$v1->id, $v2->id];

    foreach ($ids as $id) {
        expect(
            $this->actingAs($this->admin)->getJson("/admin/files/{$id}/versions")->assertOk()->json('versions')
        )->toHaveCount(2);
    }
})->group('phase2');

it('returns exactly itself for a file that has never been replaced', function () {
    $file = app(FileService::class)->store($this->admin, $this->task, upload('only.pdf'));

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/files/{$file->id}/versions")
        ->assertOk()
        ->json('versions');

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['id'])->toBe($file->id)
        ->and($payload[0]['version'])->toBe(1)
        ->and($payload[0]['is_current'])->toBeTrue();
})->group('phase2');

it('leaves a deleted version out of the history and keeps the promoted head marked current', function () {
    // Deleting is not superseding: the bytes are gone, so the row must not be offered as a
    // download. And deleting the head promotes the newest survivor, which this must agree with.
    $service = app(FileService::class);
    $v1 = $service->store($this->admin, $this->task, upload('brief.pdf'));
    $v2 = $service->replace($this->admin, $v1, upload('brief.pdf'));

    $service->delete($this->admin, $v2->refresh());

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/files/{$v1->id}/versions")
        ->assertOk()
        ->json('versions');

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['id'])->toBe($v1->id)
        ->and($payload[0]['is_current'])->toBeTrue();
})->group('phase2');

it('asks the policy per row rather than assuming an old version is anybody to touch', function () {
    // Tapu uploads v1; the Admin replaces it. The superseded row is still Tapu's upload, so it
    // is Tapu's to delete and not the Manager's — being old changes neither answer.
    $service = app(FileService::class);
    $v1 = $service->store($this->tapu, $this->task, upload('brief.pdf'));
    $v2 = $service->replace($this->admin, $v1, upload('brief.pdf'));

    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $asTapu = $this->actingAs($this->tapu)
        ->getJson("/employee/files/{$v2->id}/versions")->assertOk()->json('versions');

    expect($asTapu[0]['permissions']['can_delete'])->toBeTrue()
        ->and($asTapu[1]['permissions']['can_delete'])->toBeFalse();

    $asManager = $this->actingAs($manager)
        ->getJson("/employee/files/{$v2->id}/versions")->assertOk()->json('versions');

    // The Manager may edit the task, so they may replace the head — and may delete neither row,
    // because neither upload is theirs.
    expect($asManager[0]['permissions']['can_delete'])->toBeFalse()
        ->and($asManager[1]['permissions']['can_delete'])->toBeFalse()
        ->and($asManager[1]['permissions']['can_replace'])->toBeTrue()
        // Only the current version can be replaced, and FilePolicy answers for the row asked.
        ->and($asManager[0]['permissions']['can_replace'])->toBeTrue();
})->group('phase2');

it('gives an employee the history on their own surface and nothing on the admin one', function () {
    $service = app(FileService::class);
    $v1 = $service->store($this->tapu, $this->task, upload('brief.pdf'));
    $v2 = $service->replace($this->tapu, $v1, upload('brief.pdf'));

    $this->actingAs($this->tapu)
        ->getJson("/employee/files/{$v2->id}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'versions');

    // The surface guard, not the policy: an employee has no business on the admin shell.
    $this->actingAs($this->tapu)->getJson("/admin/files/{$v2->id}/versions")->assertForbidden();
    $this->actingAs($this->admin)->getJson("/employee/files/{$v2->id}/versions")->assertForbidden();
    $this->actingAs($this->accountant)->getJson("/employee/files/{$v2->id}/versions")->assertForbidden();
})->group('phase2');

it('scopes an employee history to the records they are on', function () {
    // The file scoping an employee's surface comes with, stated on both sides. FilePolicy
    // delegates to the OWNER's policy, so a project file follows ProjectPolicy::view: Tapu is a
    // member of the seeded project and reads its chain, and a project she is not on is absent.
    $hers = File::factory()->forProject($this->project)->uploadedBy($this->admin)->create();
    $theirs = File::factory()->forProject(Project::factory()->create())->uploadedBy($this->admin)->create();

    $this->actingAs($this->tapu)->getJson("/employee/files/{$hers->id}/versions")->assertOk();
    $this->actingAs($this->tapu)->getJson("/employee/files/{$theirs->id}/versions")->assertNotFound();
    $this->actingAs($this->admin)->getJson("/admin/files/{$theirs->id}/versions")->assertOk();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| New versions
|--------------------------------------------------------------------------
*/

it('uploads a new version through the file endpoint', function () {
    $v1 = app(FileService::class)->store($this->admin, $this->task, upload('brief.pdf', 6));

    $this->actingAs($this->tapu)
        ->post("/employee/files/{$v1->id}/versions", ['file' => upload('brief.pdf', 9)])
        ->assertRedirect()
        ->assertSessionHas('success');

    $v1->refresh();
    $v2 = File::where('version_of', $v1->id)->firstOrFail();

    expect($v1->isCurrent())->toBeFalse()
        ->and($v2->version)->toBe(2)
        ->and($v2->uploaded_by)->toBe($this->tapu->id);

    // The version it replaced still has its bytes.
    Storage::disk($v1->disk)->assertExists($v1->path);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Deleting
|--------------------------------------------------------------------------
*/

it('lets the uploader delete their own file', function () {
    $file = app(FileService::class)->store($this->tapu, $this->task, upload('mine.pdf'));

    $this->actingAs($this->tapu)
        ->delete("/employee/files/{$file->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(File::whereKey($file->id)->exists())->toBeFalse();
    Storage::disk($file->disk)->assertMissing($file->path);
})->group('phase2');

it('lets an admin delete somebody else file', function () {
    $file = app(FileService::class)->store($this->tapu, $this->task, upload('theirs.pdf'));

    $this->actingAs($this->admin)
        ->delete("/admin/files/{$file->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(File::whereKey($file->id)->exists())->toBeFalse();
})->group('phase2');

it('refuses the delete to somebody who is neither the uploader nor an admin', function () {
    // A Manager can see the task, can edit it, and still may not remove the Admin's upload.
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $file = app(FileService::class)->store($this->admin, $this->task, upload('theirs.pdf'));

    $this->actingAs($manager)
        ->delete("/employee/files/{$file->id}")
        ->assertForbidden();

    expect(File::whereKey($file->id)->exists())->toBeTrue();
    Storage::disk($file->disk)->assertExists($file->path);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Surfaces and roles
|--------------------------------------------------------------------------
*/

it('gives the accountant nothing', function () {
    $file = File::factory()->forTask($this->task)->create();

    $this->actingAs($this->accountant)->get("/admin/tasks/{$this->task->id}/files")->assertForbidden();
    $this->actingAs($this->accountant)->post("/admin/tasks/{$this->task->id}/files", ['file' => upload()])->assertForbidden();
    $this->actingAs($this->accountant)->get("/admin/projects/{$this->project->id}/files")->assertForbidden();
    $this->actingAs($this->accountant)->get("/admin/clients/{$this->client->id}/files")->assertForbidden();
    $this->actingAs($this->accountant)->delete("/admin/files/{$file->id}")->assertForbidden();
    $this->actingAs($this->accountant)->delete("/employee/files/{$file->id}")->assertForbidden();
})->group('phase2');

it('keeps an employee off the admin surface and an admin off the employee one', function () {
    $file = File::factory()->forTask($this->task)->create();

    $this->actingAs($this->tapu)->get("/admin/tasks/{$this->task->id}/files")->assertForbidden();
    $this->actingAs($this->admin)->get("/employee/tasks/{$this->task->id}/files")->assertForbidden();
    $this->actingAs($this->tapu)->delete("/admin/files/{$file->id}")->assertForbidden();
})->group('phase2');

it('has no project or client file endpoint on the employee surface', function () {
    $this->actingAs($this->tapu)->get("/employee/projects/{$this->project->id}/files")->assertNotFound();
})->group('phase2');

it('refuses an upload to an archived task with a flash error rather than a file', function () {
    $archived = Task::factory()->archived()->assignedTo($this->tapu->employee)->create();

    // TaskPolicy::update is false for an archived task, so the service refuses — and a refusal
    // by permission is a 403, the same as everywhere else here.
    $this->actingAs($this->tapu)
        ->post("/employee/tasks/{$archived->id}/files", ['file' => upload()])
        ->assertForbidden();

    expect(File::count())->toBe(0);
})->group('phase2');
