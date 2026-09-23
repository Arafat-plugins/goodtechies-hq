<?php

use App\Models\Client;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Files: what each role can and cannot reach
|--------------------------------------------------------------------------
|
| The negative half of the slice, and the half the plan names: file URLs are
| signed and expire, an employee sees only the files of tasks they are
| assigned to, and deleting is the uploader or an Admin.
|
| The question this file really answers is the one a signed URL raises. A link
| that works for whoever holds it is a different security model from one that
| re-checks the viewer, and this application chose the second. So every
| assertion below is made with a PERFECTLY VALID signature: the refusals are
| the policy's, not the signature's.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->files = app(FileService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // A task Tapu is on and Yaseen is not — the same pair every task privacy test uses.
    $this->tapusTask = Task::query()->forEmployee($this->tapu->employee)->notArchived()->firstOrFail();
    $this->onTapusTask = File::factory()->forTask($this->tapusTask)->uploadedBy($this->tapu)->withContents()->create();
});

/*
|--------------------------------------------------------------------------
| A file is as invisible as the record it hangs off
|--------------------------------------------------------------------------
*/

it('answers 404 when an employee asks for the files of a task they are not on', function () {
    $this->actingAs($this->yaseen)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/files")
        ->assertNotFound();
})->group('phase2');

it('answers 404 when an employee downloads a file from a task they are not on', function () {
    // A real, unexpired, untampered link. The signature is fine; the person is not.
    $url = $this->files->url($this->onTapusTask);

    $this->actingAs($this->yaseen)->get($url)->assertNotFound();

    // And the same link works for somebody who IS on the task, so the 404 above is about the
    // viewer and not about a broken URL.
    $this->actingAs($this->tapu)->get($url)->assertOk();
})->group('phase2');

it('does not let a forwarded link become a way round the policy', function () {
    // This is the decision, asserted: the URL is not a bearer token. Handing it to the
    // Accountant — who may see no task at all — buys them nothing, for as long as it lives.
    $url = $this->files->url($this->onTapusTask);

    $this->actingAs($this->accountant)->get($url)->assertNotFound();
    $this->actingAs($this->yaseen)->get($url)->assertNotFound();
})->group('phase2');

it('refuses a download to a guest even with a valid signature', function () {
    // `auth` runs before `signed`, so an anonymous holder of a perfectly good link is sent to
    // log in rather than served the file.
    $this->get($this->files->url($this->onTapusTask))->assertRedirect('/login');
})->group('phase2');

it('expires the link', function () {
    $url = $this->files->url($this->onTapusTask);

    $this->actingAs($this->tapu)->get($url)->assertOk();

    $this->travel(FileService::URL_TTL_MINUTES + 1)->minutes();

    // 403, not 404: the signature is what failed, and the middleware says so before the
    // controller ever looks the file up.
    $this->actingAs($this->tapu)->get($url)->assertForbidden();
})->group('phase2');

it('refuses an unsigned download however legitimate the viewer', function () {
    $this->actingAs($this->admin)
        ->get("/files/{$this->onTapusTask->id}")
        ->assertForbidden();
})->group('phase2');

it('answers 404 when an employee tries to delete a file on a task they are not on', function () {
    $this->actingAs($this->yaseen)
        ->delete("/employee/files/{$this->onTapusTask->id}")
        ->assertNotFound();

    expect(File::whereKey($this->onTapusTask->id)->exists())->toBeTrue();
})->group('phase2');

it('answers 404 when an employee asks for the history of a file on a task they are not on', function () {
    // 404 and never 403, and the count is behind it too. "This file has four versions" is a
    // sentence about a task Yaseen may not know exists, so the history endpoint has to be as
    // silent about it as the task endpoint is — an absent record cannot have a version count.
    $this->actingAs($this->yaseen)
        ->getJson("/employee/files/{$this->onTapusTask->id}/versions")
        ->assertNotFound();

    // The same id on the same surface answers the chain for somebody who IS on the task, so the
    // 404 above is about the viewer and not about a file that is not there.
    $this->actingAs($this->tapu)
        ->getJson("/employee/files/{$this->onTapusTask->id}/versions")
        ->assertOk()
        ->assertJsonCount(1, 'versions');
})->group('phase2');

it('does not turn an older version into a way round the policy', function () {
    // An older version is a file like any other: its `url` is signed by the same minter and the
    // download route re-runs FilePolicy::view on the fetch. Reaching one through the history of
    // a file you may see does not make it a bearer link for somebody who may not.
    $v1 = $this->files->store($this->tapu, $this->tapusTask, UploadedFile::fake()->create('brief.pdf', 4, 'application/pdf'));
    $v2 = $this->files->replace($this->tapu, $v1, UploadedFile::fake()->create('brief.pdf', 6, 'application/pdf'));

    $chain = $this->actingAs($this->tapu)
        ->getJson("/employee/files/{$v2->id}/versions")
        ->assertOk()
        ->json('versions');

    $supersededUrl = $chain[0]['url'];

    // The superseded row still has its bytes, so this is a live link — for the right person.
    $this->actingAs($this->tapu)->get($supersededUrl)->assertOk();
    $this->actingAs($this->yaseen)->get($supersededUrl)->assertNotFound();
    $this->actingAs($this->accountant)->get($supersededUrl)->assertNotFound();
})->group('phase2');

it('answers 404 when an employee tries to version a file on a task they are not on', function () {
    $this->actingAs($this->yaseen)
        ->post("/employee/files/{$this->onTapusTask->id}/versions", [
            'file' => UploadedFile::fake()->create('brief.pdf', 4, 'application/pdf'),
        ])
        ->assertNotFound();

    expect(File::count())->toBe(1);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Project and client files
|--------------------------------------------------------------------------
*/

it('hides a project file from an employee who is not on the project', function () {
    $project = Project::factory()->create();
    $file = File::factory()->forProject($project)->withContents()->create();

    $this->actingAs($this->tapu)->get($this->files->url($file))->assertNotFound();
    $this->actingAs($this->admin)->get($this->files->url($file))->assertOk();
})->group('phase2');

it('hides a client file from everybody without clients view full', function () {
    $client = Client::factory()->create();
    $file = File::factory()->forClient($client)->withContents()->create();

    $this->actingAs($this->tapu)->get($this->files->url($file))->assertNotFound();
    $this->actingAs($this->accountant)->get($this->files->url($file))->assertNotFound();
    $this->actingAs($this->admin)->get($this->files->url($file))->assertOk();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The task payload
|--------------------------------------------------------------------------
*/

it('does not leak an invisible task file through the attachment count', function () {
    // Yaseen cannot see the task at all, so there is nothing for a count to be about.
    $this->actingAs($this->yaseen)
        ->get("/employee/tasks/{$this->tapusTask->id}")
        ->assertNotFound();
})->group('phase2');
