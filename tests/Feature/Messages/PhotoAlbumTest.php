<?php

use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Several photos sent together land as ONE message, so the thread can show them side by side.
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $this->conversation = app(ConversationService::class)->forTask($this->task);
});

it('stores photos sent together on one message', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", [
            'file' => UploadedFile::fake()->image('one.png'),
            'files' => [
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.png'),
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->conversation->messages()->count())->toBe(1);

    $message = $this->conversation->messages()->latest('id')->firstOrFail();

    expect($message->attachments)->toHaveCount(3);
});

it('refuses a non-photo among the photos sent together', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", [
            'file' => UploadedFile::fake()->image('one.png'),
            'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ])
        ->assertSessionHasErrors('files.0');

    expect($this->conversation->messages()->count())->toBe(0);
});

it('refuses extra photos with no first file', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", [
            'files' => [UploadedFile::fake()->image('two.png')],
        ])
        ->assertSessionHasErrors('file');

    expect($this->conversation->messages()->count())->toBe(0);
});
