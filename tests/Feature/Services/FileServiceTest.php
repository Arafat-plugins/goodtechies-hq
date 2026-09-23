<?php

use App\Exceptions\FileStateException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\FileService;
use App\Support\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| FileService — the one thing that touches the disk
|--------------------------------------------------------------------------
|
| Every rule stated where it lives, because the panels that would otherwise
| expose a mistake are a later brief and do not exist yet: the size and type
| limits, the metadata, the version chain that must not orphan its
| predecessor, the delete that takes the bytes but keeps the row, and the
| signed URL with its expiry.
|
| Nothing here asserts a driver. Storage::fake swaps the disk out from under
| the service and every assertion is about the abstraction — `Storage::disk()
| ->exists()`, a route, a policy — so the same tests pass when
| FILESYSTEM_DISK becomes s3 (decision 2-2).
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->service = app(FileService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->firstOrFail();
});

function pdfUpload(string $name = 'brief.pdf', int $kilobytes = 12): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
}

/*
|--------------------------------------------------------------------------
| Storing
|--------------------------------------------------------------------------
*/

it('stores the bytes and records the metadata', function () {
    $file = $this->service->store($this->admin, $this->task, pdfUpload('Q3 brief.pdf', 12));

    Storage::disk($file->disk)->assertExists($file->path);

    expect($file->name)->toBe('Q3 brief.pdf')
        ->and($file->extension)->toBe('pdf')
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->size)->toBe(12 * 1024)
        ->and($file->checksum)->toHaveLength(64)
        ->and($file->uploaded_by)->toBe($this->admin->id)
        ->and($file->task_id)->toBe($this->task->id)
        ->and($file->project_id)->toBeNull()
        ->and($file->client_id)->toBeNull()
        ->and($file->version)->toBe(1)
        ->and($file->version_of)->toBeNull()
        ->and($file->isCurrent())->toBeTrue();
})->group('phase2');

it('never uses the uploader name as the storage path', function () {
    $file = $this->service->store($this->admin, $this->task, pdfUpload('../../etc/passwd.pdf'));

    expect($file->path)->toStartWith('files/tasks/'.$this->task->id.'/')
        ->and($file->path)->not->toContain('..')
        ->and($file->path)->not->toContain('passwd')
        // The original name survives as a column, stripped of the directory part, because it
        // is what the download is served under.
        ->and($file->name)->toBe('passwd.pdf');
})->group('phase2');

it('gives two uploads of the same filename two paths', function () {
    $first = $this->service->store($this->admin, $this->task, pdfUpload('report.pdf'));
    $second = $this->service->store($this->tapu, $this->task, pdfUpload('report.pdf'));

    expect($first->path)->not->toBe($second->path)
        ->and($first->name)->toBe($second->name);

    Storage::disk($first->disk)->assertExists($first->path);
    Storage::disk($second->disk)->assertExists($second->path);
})->group('phase2');

it('stores files against a project and against a client', function () {
    $project = Project::factory()->create();
    $client = Client::factory()->create();

    $onProject = $this->service->store($this->admin, $project, pdfUpload('scope.pdf'));
    $onClient = $this->service->store($this->admin, $client, pdfUpload('contract.pdf'));

    expect($onProject->project_id)->toBe($project->id)
        ->and($onProject->task_id)->toBeNull()
        ->and($onClient->client_id)->toBe($client->id)
        ->and($onClient->task_id)->toBeNull()
        ->and($this->service->for($project)->pluck('id')->all())->toBe([$onProject->id])
        ->and($this->service->for($client)->pluck('id')->all())->toBe([$onClient->id]);
})->group('phase2');

it('refuses a record that cannot own files', function () {
    $this->service->store($this->admin, $this->admin, pdfUpload());
})->throws(FileStateException::class)->group('phase2');

/*
|--------------------------------------------------------------------------
| Validation — type and size
|--------------------------------------------------------------------------
*/

it('refuses a file over the size limit', function () {
    $tooBig = UploadedFile::fake()->create('huge.pdf', FileService::maxKilobytes() + 1, 'application/pdf');

    expect(fn () => $this->service->store($this->admin, $this->task, $tooBig))
        ->toThrow(FileStateException::class);

    expect(File::count())->toBe(0);
})->group('phase2');

it('accepts a file exactly on the size limit', function () {
    $atLimit = UploadedFile::fake()->create('big.pdf', FileService::maxKilobytes(), 'application/pdf');

    expect($this->service->store($this->admin, $this->task, $atLimit)->size)
        ->toBe(FileService::MAX_BYTES);
})->group('phase2');

it('refuses an empty file', function () {
    $empty = UploadedFile::fake()->create('nothing.pdf', 0, 'application/pdf');

    expect(fn () => $this->service->store($this->admin, $this->task, $empty))
        ->toThrow(FileStateException::class);
})->group('phase2');

it('refuses an extension that is not on the allow-list', function (string $name) {
    $upload = UploadedFile::fake()->create($name, 4, 'application/pdf');

    expect(fn () => $this->service->store($this->admin, $this->task, $upload))
        ->toThrow(FileStateException::class);

    expect(File::count())->toBe(0);
})->with([
    'a script' => ['payload.php'],
    'an executable' => ['setup.exe'],
    'a page' => ['index.html'],
    // SVG is an image everywhere except where it matters: it is XML that can carry script, and
    // this application serves files from its own origin.
    'a vector image' => ['logo.svg'],
    'no extension at all' => ['README'],
])->group('phase2');

it('refuses a file whose contents are not what its extension claims', function () {
    // The rule that a bare `extensions:` or `mimetypes:` rule cannot express, because neither
    // looks at the other: the pair has to agree.
    $disguised = UploadedFile::fake()->create('invoice.pdf', 4, 'text/x-php');

    expect(fn () => $this->service->store($this->admin, $this->task, $disguised))
        ->toThrow(FileStateException::class);

    expect(File::count())->toBe(0);
})->group('phase2');

it('accepts each type on the allow-list', function (string $name, string $mime) {
    expect($this->service->store($this->admin, $this->task, UploadedFile::fake()->create($name, 4, $mime))->id)
        ->toBeInt();
})->with([
    'png' => ['shot.png', 'image/png'],
    'jpeg' => ['photo.jpg', 'image/jpeg'],
    'webp' => ['hero.webp', 'image/webp'],
    'pdf' => ['brief.pdf', 'application/pdf'],
    'docx' => ['copy.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xlsx' => ['budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'csv' => ['keywords.csv', 'text/csv'],
    'zip' => ['handover.zip', 'application/zip'],
])->group('phase2');

/*
|--------------------------------------------------------------------------
| Version history
|--------------------------------------------------------------------------
*/

it('keeps the old version when a file is replaced', function () {
    $first = $this->service->store($this->admin, $this->task, pdfUpload('brief.pdf', 10));
    $firstPath = $first->path;

    $second = $this->service->replace($this->tapu, $first, pdfUpload('brief v2.pdf', 20));

    $first->refresh();

    // The old row is still there, still pointing at its own bytes, still crediting its own
    // uploader. A replacement that overwrote any of that would be a worse store(), not a
    // version.
    expect($first->exists)->toBeTrue()
        ->and($first->path)->toBe($firstPath)
        ->and($first->uploaded_by)->toBe($this->admin->id)
        ->and($first->size)->toBe(10 * 1024)
        ->and($first->isCurrent())->toBeFalse()
        ->and($first->superseded_at)->not->toBeNull();

    Storage::disk($first->disk)->assertExists($firstPath);

    expect($second->version)->toBe(2)
        ->and($second->version_of)->toBe($first->id)
        ->and($second->uploaded_by)->toBe($this->tapu->id)
        ->and($second->path)->not->toBe($firstPath)
        ->and($second->isCurrent())->toBeTrue();

    Storage::disk($second->disk)->assertExists($second->path);
})->group('phase2');

it('flattens the chain so every version points at the root', function () {
    $v1 = $this->service->store($this->admin, $this->task, pdfUpload('brief.pdf'));
    $v2 = $this->service->replace($this->admin, $v1, pdfUpload('brief.pdf'));
    $v3 = $this->service->replace($this->admin, $v2->refresh(), pdfUpload('brief.pdf'));

    expect($v2->version_of)->toBe($v1->id)
        ->and($v3->version_of)->toBe($v1->id)
        ->and($v3->version)->toBe(3)
        ->and($this->service->history($v3)->pluck('id')->all())->toBe([$v1->id, $v2->id, $v3->id])
        // History is asked for per file; a Files tab lists the current version of each file.
        ->and($this->service->for($this->task)->pluck('id')->all())->toBe([$v3->id]);
})->group('phase2');

it('reads the same chain from every row of it', function () {
    // The defect this guards against: a chain used to be read off a relation, and `version_of`
    // holds the ROOT's id on every row — so the relation answered on the root and nowhere else,
    // and the root is superseded the instant a replacement exists. `history()` resolves the
    // chain from chainId(), so the answer does not depend on which version was in hand.
    $v1 = $this->service->store($this->admin, $this->task, pdfUpload());
    $v2 = $this->service->replace($this->admin, $v1, pdfUpload());
    $v3 = $this->service->replace($this->admin, $v2->refresh(), pdfUpload());

    $expected = [$v1->id, $v2->id, $v3->id];

    foreach ([$v1, $v2, $v3] as $row) {
        expect($this->service->history($row->refresh())->pluck('id')->all())->toBe($expected);
    }
})->group('phase2');

it('keeps the chains of two files on one record apart', function () {
    $a = $this->service->store($this->admin, $this->task, pdfUpload('a.pdf'));
    $b = $this->service->store($this->admin, $this->task, pdfUpload('b.pdf'));
    $a2 = $this->service->replace($this->admin, $a, pdfUpload('a.pdf'));

    expect($this->service->history($a2)->pluck('id')->all())->toBe([$a->id, $a2->id])
        ->and($this->service->history($b)->pluck('id')->all())->toBe([$b->id]);
})->group('phase2');

it('shows one attachment on the task however many versions it has', function () {
    $file = $this->service->store($this->admin, $this->task, pdfUpload());
    $this->service->replace($this->admin, $file, pdfUpload());

    expect($this->task->files()->count())->toBe(1)
        ->and(File::count())->toBe(2);
})->group('phase2');

it('refuses to branch a chain from an old version', function () {
    $v1 = $this->service->store($this->admin, $this->task, pdfUpload());
    $this->service->replace($this->admin, $v1, pdfUpload());

    $this->service->replace($this->admin, $v1->refresh(), pdfUpload());
})->throws(FileStateException::class)->group('phase2');

it('will not let a chain have two current versions', function () {
    $v1 = $this->service->store($this->admin, $this->task, pdfUpload());
    $this->service->replace($this->admin, $v1, pdfUpload());

    // The partial unique index is the database's half of the rule, so a row written round the
    // service still cannot produce a second head. Nothing is asserted after it: the violation
    // aborts the surrounding transaction, which is exactly what it should do.
    $v1->refresh()->forceFill(['superseded_at' => null])->save();
})->throws(QueryException::class)->group('phase2');

/*
|--------------------------------------------------------------------------
| Deleting
|--------------------------------------------------------------------------
*/

it('removes the bytes but keeps the row', function () {
    $file = $this->service->store($this->admin, $this->task, pdfUpload('gone.pdf'));
    $path = $file->path;

    $this->service->delete($this->admin, $file);

    Storage::disk($file->disk)->assertMissing($path);

    expect(File::whereKey($file->id)->exists())->toBeFalse()
        ->and(File::withTrashed()->whereKey($file->id)->exists())->toBeTrue()
        ->and($this->service->for($this->task))->toHaveCount(0);
})->group('phase2');

it('writes an audit row naming the file it removed', function () {
    $file = $this->service->store($this->admin, $this->task, pdfUpload('contract.pdf'));

    $this->service->delete($this->admin, $file);

    $log = AuditLog::where('event', AuditEvent::FileDeleted->value)->latest('id')->firstOrFail();

    expect($log->old_value['name'])->toBe('contract.pdf')
        ->and($log->old_value['owner'])->toBe('task_id')
        ->and($log->old_value['owner_id'])->toBe($this->task->id)
        ->and($log->new_value)->toBeNull()
        ->and($log->actor_id)->toBe($this->admin->id);
})->group('phase2');

it('promotes the previous version when the current one is deleted', function () {
    $v1 = $this->service->store($this->admin, $this->task, pdfUpload('brief.pdf'));
    $v2 = $this->service->replace($this->admin, $v1, pdfUpload('brief.pdf'));

    $this->service->delete($this->admin, $v2);

    // History is never left headless: the newest surviving version is the file again.
    expect($v1->refresh()->isCurrent())->toBeTrue()
        ->and($this->service->for($this->task)->pluck('id')->all())->toBe([$v1->id]);
})->group('phase2');

it('leaves the current version alone when an old one is deleted', function () {
    $v1 = $this->service->store($this->admin, $this->task, pdfUpload());
    $v2 = $this->service->replace($this->admin, $v1, pdfUpload());

    $this->service->delete($this->admin, $v1->refresh());

    expect($v2->refresh()->isCurrent())->toBeTrue()
        ->and($this->service->for($this->task)->pluck('id')->all())->toBe([$v2->id]);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may write
|--------------------------------------------------------------------------
*/

it('refuses an upload to a task the actor may not edit', function () {
    $someoneElsesTask = Task::factory()->create();

    $this->service->store($this->tapu, $someoneElsesTask, pdfUpload());
})->throws(AuthorizationException::class)->group('phase2');

it('refuses an upload to an archived task', function () {
    $archived = Task::factory()->archived()->assignedTo($this->tapu->employee)->create();

    $this->service->store($this->tapu, $archived, pdfUpload());
})->throws(AuthorizationException::class)->group('phase2');

it('records the upload on the owning record timeline', function () {
    $file = $this->service->store($this->admin, $this->task, pdfUpload('notes.pdf'));

    expect(ActivityLog::where('object_type', $this->task->getMorphClass())
        ->where('object_id', $this->task->id)
        ->where('description', 'File attached: notes.pdf')
        ->exists())->toBeTrue()
        ->and($file->id)->toBeInt();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The signed URL
|--------------------------------------------------------------------------
*/

it('signs the download url and gives it an expiry', function () {
    $file = File::factory()->forTask($this->task)->create();

    $url = $this->service->url($file);

    expect($url)->toContain('/files/'.$file->id)
        ->toContain('signature=')
        ->toContain('expires=');
})->group('phase2');

it('expires the url after the configured window', function () {
    $file = File::factory()->forTask($this->task)->withContents()->create();

    $url = $this->service->url($file);

    $this->actingAs($this->admin);

    $this->get($url)->assertOk();

    $this->travel(FileService::URL_TTL_MINUTES + 1)->minutes();

    $this->get($url)->assertForbidden();
})->group('phase2');

it('refuses a url whose file id has been edited', function () {
    $mine = File::factory()->forTask($this->task)->withContents()->create();
    $other = File::factory()->forTask($this->task)->withContents()->create();

    $tampered = str_replace('/files/'.$mine->id, '/files/'.$other->id, $this->service->url($mine));

    $this->actingAs($this->admin)->get($tampered)->assertForbidden();
})->group('phase2');

it('serves an image and a pdf inline and everything else as a download', function (string $name, string $mime, string $disposition) {
    $file = File::factory()
        ->forTask($this->task)
        ->name($name)
        ->mimeType($mime)
        ->withContents()
        ->create();

    $response = $this->actingAs($this->admin)->get($this->service->url($file));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toStartWith($disposition)
        // Symfony appends a charset to a text type; the type itself is the row's, not the
        // request's, and that is what `nosniff` above makes binding.
        ->and($response->headers->get('Content-Type'))->toStartWith($mime);
})->with([
    'a screenshot' => ['shot.png', 'image/png', 'inline'],
    'a pdf' => ['brief.pdf', 'application/pdf', 'inline'],
    // A zip is handed over, never rendered — and so is anything else that is not on the short
    // inline list, whatever it claims to be.
    'a zip' => ['handover.zip', 'application/zip', 'attachment'],
    'a spreadsheet' => ['budget.csv', 'text/csv', 'attachment'],
])->group('phase2');

it('answers 404 when the row says there are bytes and the disk disagrees', function () {
    // No withContents(): the row exists, the blob does not.
    $file = File::factory()->forTask($this->task)->create();

    $this->actingAs($this->admin)->get($this->service->url($file))->assertNotFound();
})->group('phase2');
