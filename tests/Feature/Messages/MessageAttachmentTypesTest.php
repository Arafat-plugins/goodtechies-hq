<?php

use App\Models\File;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\FileService;
use App\Services\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Message attachments: no application size cap, more types (decision 12-82)
|--------------------------------------------------------------------------
|
| Messages accept any size the transport allows (nginx/PHP: 256 MB) and svg / mp3 / mp4 / zip /
| apk on top of FileService::TYPES. Task, project and client files keep the 25 MB cap and the
| narrower list. A scripted SVG is refused, and an SVG is served sandboxed.
|
| Constants and helpers are global in Pest, so every one here is prefixed MATT_.
|
*/

const MATT_URL = '/messages';

function matt_path(int $conversationId): string
{
    return MATT_URL.'/'.$conversationId;
}

function matt_svg(string $name = 'logo.svg', string $extra = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<rect width="10" height="10" fill="red"/>'.$extra.'</svg>',
    );
}

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);
    $this->files = app(FileService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->dm = $this->conversations->dmBetween($this->tapu, $this->yaseen);
});

it('accepts a 30 MB file on a DM: messages carry no application size cap', function () {
    $this->actingAs($this->tapu)
        ->from(MATT_URL)
        ->post(matt_path($this->dm->id), [
            'file' => UploadedFile::fake()->create('big.zip', 30 * 1024, 'application/zip'),
        ])
        ->assertRedirect(MATT_URL)
        ->assertSessionHasNoErrors();

    $file = File::query()->where('name', 'big.zip')->firstOrFail();

    expect($file->size)->toBeGreaterThan(FileService::MAX_BYTES)
        ->and(Message::query()->where('conversation_id', $this->dm->id)->count())->toBe(1);
});

it('still refuses the same 30 MB file on a task upload', function () {
    $task = Task::query()->orderBy('id')->firstOrFail();

    $this->actingAs($this->admin)
        ->from('/admin/tasks/'.$task->id)
        ->post('/admin/tasks/'.$task->id.'/files', [
            'file' => UploadedFile::fake()->create('big.zip', 30 * 1024, 'application/zip'),
        ])
        ->assertSessionHasErrors('file');

    expect(File::count())->toBe(0);
});

it('accepts the new types on a message', function (string $name, string $mime) {
    $upload = $name === 'logo.svg'
        ? matt_svg()
        : UploadedFile::fake()->create($name, 64, $mime);

    $this->actingAs($this->tapu)
        ->postJson(matt_path($this->dm->id), ['file' => $upload])
        ->assertCreated()
        ->assertJsonPath('message.attachments.0.name', $name);
})->with([
    'svg' => ['logo.svg', 'image/svg+xml'],
    'mp3' => ['song.mp3', 'audio/mpeg'],
    'mp4' => ['clip.mp4', 'video/mp4'],
    'zip' => ['bundle.zip', 'application/zip'],
    'apk' => ['app.apk', 'application/vnd.android.package-archive'],
]);

it('keeps the new types off a task upload', function () {
    $task = Task::query()->orderBy('id')->firstOrFail();

    $this->actingAs($this->admin)
        ->from('/admin/tasks/'.$task->id)
        ->post('/admin/tasks/'.$task->id.'/files', ['file' => matt_svg()])
        ->assertSessionHasErrors('file');
});

it('refuses an SVG that carries script', function (string $payload) {
    $this->actingAs($this->tapu)
        ->postJson(matt_path($this->dm->id), ['file' => matt_svg('evil.svg', $payload)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(File::count())->toBe(0);
})->with([
    'script element' => ['<script>alert(1)</script>'],
    'event handler' => ['<rect onload="alert(1)"/>'],
    'javascript url' => ['<a href="JavaScript:alert(1)"><text>x</text></a>'],
    'foreignObject' => ['<foreignObject><div>x</div></foreignObject>'],
]);

it('still refuses an executable on a message', function () {
    $this->actingAs($this->tapu)
        ->postJson(matt_path($this->dm->id), [
            'file' => UploadedFile::fake()->create('setup.exe', 64, 'application/x-dosexec'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('serves an SVG sandboxed, as image/svg+xml, with nosniff', function () {
    $message = $this->messages->post($this->tapu, $this->dm, null, matt_svg());
    $file = $message->attachments->first();

    $response = $this->actingAs($this->yaseen)->get($this->files->url($file))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))
        ->toBe("default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});
