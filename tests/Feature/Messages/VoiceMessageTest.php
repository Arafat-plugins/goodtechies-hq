<?php

use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\FileService;
use App\Services\MessageService;
use App\Support\AttachmentKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Voice messages — the server half
|--------------------------------------------------------------------------
|
| A voice note is an ordinary message with an ordinary file on it. The only
| two things that make it a voice note are the `kind` and the
| `duration_seconds` on `message_attachments`, and the only two things this
| slice added are a way to send them and a narrow allow-list for the bytes.
|
| Three rules are asserted here more than once, because they are the ones a
| later "tidy-up" would quietly undo:
|
|   - **The voice allow-list is its own list.** `FileService::TYPES` gained
|     no audio, so an audio file is uploadable as a voice note and NOWHERE
|     else — not on a task, not on a project, not as a plain attachment on
|     a message. `it('refuses an audio file attached through the ordinary
|     Files panel')` is the guard on that; somebody merging the two lists
|     has to delete it on purpose.
|   - **`duration` is a claim, not a measurement.** It came off a
|     `MediaRecorder` in somebody's browser. Outside 1…300 it is a 422, and
|     it is clamped again at the write so no non-HTTP caller can store a
|     bubble that says it is nine thousand seconds long.
|   - **Nothing about who may post changed.** `ConversationPolicy::post` is
|     still the only thing that decides it — 403 for the Accountant, 404 for
|     somebody else's DM, exactly as for a text message.
|
| The MIME pairings below are MEASURED, not remembered. finfo reports the
| CONTAINER, so an audio-only WebM is `video/webm` and an audio-only MP4 is
| `video/mp4` — which is precisely what Chrome and Safari hand over. Taken
| 2026-09-24 from files ffmpeg produced:
|
|     ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus rec.webm
|     php -r 'echo (new finfo(FILEINFO_MIME_TYPE))->file("rec.webm");'
|
|     rec.webm → video/webm    rec.m4a → audio/x-m4a    rec.ogg → audio/ogg
|     rec.mp4  → video/mp4     rec.mp3 → audio/mpeg     rec.wav → audio/x-wav
|
| Constants and helpers are global in Pest, so every one here is prefixed
| VOICE_MESSAGE_.
|
*/

const VOICE_MESSAGE_URL = '/messages';

/** A length that is comfortably inside the accepted range, so it is never the thing under test. */
const VOICE_MESSAGE_SECONDS = 12;

function voice_message_path(int $conversationId): string
{
    return VOICE_MESSAGE_URL.'/'.$conversationId;
}

/**
 * A recording as it arrives from a browser: the extension the recorder chose, and the content
 * type finfo really reports for that container. Both halves are checked by the server.
 */
function voice_message_recording(string $name = 'voice-note.webm', string $mime = 'video/webm'): UploadedFile
{
    return UploadedFile::fake()->create($name, 64)->mimeType($mime);
}

/**
 * The pivot row a message's one attachment wrote.
 *
 * Read straight out of `message_attachments` rather than through a relation, because what is
 * under test IS the two columns on that table.
 *
 * @return array{kind: string, duration_seconds: int|null}
 */
function voice_message_pivot(int $messageId): array
{
    $row = DB::table('message_attachments')->where('message_id', $messageId)->first();

    expect($row)->not->toBeNull();

    return [
        'kind' => (string) $row->kind,
        'duration_seconds' => $row->duration_seconds === null ? null : (int) $row->duration_seconds,
    ];
}

/** The newest message in a conversation — the one the test just posted. */
function voice_message_latest(int $conversationId): Message
{
    return Message::query()
        ->where('conversation_id', $conversationId)
        ->reorder('id', 'desc')
        ->firstOrFail();
}

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
    $this->projectChannel = $this->conversations->forProject($this->tapusProject);
    $this->dm = $this->conversations->dmBetween($this->tapu, $this->yaseen);
});

/*
|--------------------------------------------------------------------------
| It is stored, in every kind of room
|--------------------------------------------------------------------------
*/

it('stores a voice note in a DM with its kind and its length, and prints both', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->dm->id), [
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => VOICE_MESSAGE_SECONDS,
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasNoErrors();

    $message = voice_message_latest($this->dm->id);

    expect(voice_message_pivot((int) $message->id))->toBe([
        'kind' => 'voice',
        'duration_seconds' => VOICE_MESSAGE_SECONDS,
    ]);

    // And the way the thread actually reads it: MessageResource, over the wire.
    $payload = $this->actingAs($this->tapu)
        ->getJson(voice_message_path($this->dm->id))
        ->assertOk()
        ->json('messages');

    $attachment = collect($payload)->last()['attachments'][0];

    expect($attachment['kind'])->toBe('voice')
        ->and($attachment['duration_seconds'])->toBe(VOICE_MESSAGE_SECONDS);
});

it('stores a voice note in the team channel', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => 45,
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasNoErrors();

    expect(voice_message_pivot((int) voice_message_latest($this->team->id)->id))->toBe([
        'kind' => 'voice',
        'duration_seconds' => 45,
    ]);
});

it('stores a voice note in a project channel', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->projectChannel->id), [
            'file' => voice_message_recording('note.m4a', 'audio/x-m4a'),
            'kind' => 'voice',
            'duration' => 7,
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasNoErrors();

    expect(voice_message_pivot((int) voice_message_latest($this->projectChannel->id)->id))->toBe([
        'kind' => 'voice',
        'duration_seconds' => 7,
    ]);
});

it('stores a voice note posted into a task discussion through its own endpoint', function () {
    // The plan's "Task detail Discussion tab gains mentions + voice" — the employee surface's
    // own route, not the Messages page's.
    $url = '/employee/tasks/'.$this->tapusTask->id.'/discussion';

    $this->actingAs($this->tapu)
        ->from('/employee/tasks/'.$this->tapusTask->id)
        ->post($url, [
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => 30,
        ])
        ->assertRedirect('/employee/tasks/'.$this->tapusTask->id)
        ->assertSessionHasNoErrors();

    $conversation = $this->conversations->forTask($this->tapusTask);

    expect(voice_message_pivot((int) voice_message_latest((int) $conversation->id)->id))->toBe([
        'kind' => 'voice',
        'duration_seconds' => 30,
    ]);
});

/*
|--------------------------------------------------------------------------
| The measured pairing table
|--------------------------------------------------------------------------
|
| One case per row of FileService::VOICE_TYPES, with the content type finfo
| really reports for a file of that kind. `video/webm` and `video/mp4` are
| the two that look wrong and are not: finfo names the container.
|
*/

it('accepts the container each browser actually records', function (string $name, string $mime) {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => voice_message_recording($name, $mime),
            'kind' => 'voice',
            'duration' => 5,
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasNoErrors();

    $message = voice_message_latest($this->team->id);

    expect(voice_message_pivot((int) $message->id)['kind'])->toBe('voice')
        ->and(File::where('message_id', $message->id)->firstOrFail()->mime_type)->toBe($mime);
})->with([
    'chrome / firefox, opus in webm' => ['note.webm', 'video/webm'],
    'a webm that finfo does call audio' => ['note.webm', 'audio/webm'],
    'safari, aac in m4a' => ['note.m4a', 'audio/x-m4a'],
    'an m4a read as mp4' => ['note.m4a', 'audio/mp4'],
    'safari, aac in mp4' => ['note.mp4', 'video/mp4'],
    'ogg opus' => ['note.ogg', 'audio/ogg'],
    'ogg read by an older finfo' => ['note.oga', 'application/ogg'],
    'mp3' => ['note.mp3', 'audio/mpeg'],
    'wav, as finfo spells it here' => ['note.wav', 'audio/x-wav'],
    'wav, as another finfo spells it' => ['note.wav', 'audio/wav'],
]);

/*
|--------------------------------------------------------------------------
| The refusals
|--------------------------------------------------------------------------
*/

it('refuses a PDF sent as a voice note, against the file field', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => UploadedFile::fake()->create('brief.pdf', 64)->mimeType('application/pdf'),
            'kind' => 'voice',
            'duration' => 10,
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))
        ->toContain('PDF files are not accepted')
        // The copy names the voice list, not the application-wide one, so the sender is told
        // what a recording may be rather than that they could have sent a spreadsheet.
        ->toContain('webm');

    expect(Message::query()->where('conversation_id', $this->team->id)->exists())->toBeFalse();
});

it('refuses a voice note with no file at all', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'body' => 'I promise there is a recording',
            'kind' => 'voice',
            'duration' => 10,
        ])
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))->toBe('A voice note needs its recording.');
});

it('refuses a voice note with no duration', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => voice_message_recording(),
            'kind' => 'voice',
        ])
        ->assertSessionHasErrors('duration');

    expect(session('errors')->first('duration'))->toBe('A voice note needs its length in seconds.');
});

it('refuses a duration outside the range a message may be', function (int $duration) {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => $duration,
        ])
        ->assertSessionHasErrors('duration');

    expect(session('errors')->first('duration'))
        ->toContain('between '.MessageService::MIN_VOICE_SECONDS.' and '.MessageService::MAX_VOICE_SECONDS);

    // Nothing was written. A rejected claim is not a clamped one at this boundary.
    expect(Message::query()->where('conversation_id', $this->team->id)->exists())->toBeFalse();
})->with([
    'a mis-press' => 0,
    'a negative' => -5,
    'one second over five minutes' => MessageService::MAX_VOICE_SECONDS + 1,
]);

it('refuses a duration sent without kind=voice', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => UploadedFile::fake()->create('brief.pdf', 64)->mimeType('application/pdf'),
            'duration' => 10,
        ])
        ->assertSessionHasErrors('duration');

    expect(session('errors')->first('duration'))
        ->toBe('A length belongs to a voice note. Leave it off an ordinary attachment.');
});

it('refuses a recording over the twenty-five megabyte limit', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => UploadedFile::fake()
                ->create('long.webm', FileService::maxKilobytes() + 1)
                ->mimeType('video/webm'),
            'kind' => 'voice',
            'duration' => 60,
        ])
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))->toContain('25 MB');
});

/*
|--------------------------------------------------------------------------
| The blast radius: audio is a voice note and nothing else
|--------------------------------------------------------------------------
|
| This is the guard on the decision that FileService::VOICE_TYPES is a
| SECOND list rather than five more rows in FileService::TYPES. Merging them
| would make both of these pass, which is the point of writing them down.
|
*/

it('refuses an audio file attached through the ordinary Files panel', function () {
    $this->actingAs($this->admin)
        ->from('/admin/tasks/'.$this->tapusTask->id)
        ->post('/admin/tasks/'.$this->tapusTask->id.'/files', [
            'file' => voice_message_recording('sneaky.webm'),
        ])
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))->toContain('WEBM files are not accepted');
});

it('refuses an audio file posted as an ordinary message attachment', function () {
    // Same bytes, same endpoint as a voice note, but no `kind`. Without the word, the upload is
    // measured against the application-wide list, which has no audio in it.
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => voice_message_recording('sneaky.webm'),
        ])
        ->assertSessionHasErrors('file');

    expect(session('errors')->first('file'))->toContain('WEBM files are not accepted');
});

it('refuses a kind the composer has no business asserting', function (string $kind) {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => UploadedFile::fake()->create('shot.png', 64)->mimeType('image/png'),
            'kind' => $kind,
        ])
        ->assertSessionHasErrors('kind');
})->with(['image', 'file', 'audio']);

/*
|--------------------------------------------------------------------------
| Everything else about posting is unchanged
|--------------------------------------------------------------------------
*/

it('still stores an ordinary upload with no kind exactly as before', function (string $name, string $mime, string $expected) {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'file' => UploadedFile::fake()->create($name, 64)->mimeType($mime),
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasNoErrors();

    expect(voice_message_pivot((int) voice_message_latest($this->team->id)->id))->toBe([
        'kind' => $expected,
        // No duration on anything that is not a recording, ever.
        'duration_seconds' => null,
    ]);
})->with([
    'a screenshot is an image' => ['shot.png', 'image/png', AttachmentKind::Image->value],
    'a brief is a file' => ['brief.pdf', 'application/pdf', AttachmentKind::File->value],
]);

it('keeps the body and the mention beside the recording', function () {
    $this->actingAs($this->tapu)
        ->from(VOICE_MESSAGE_URL)
        ->post(voice_message_path($this->team->id), [
            'body' => 'Listen to this, @'.$this->yaseen->name,
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => 20,
            'mentions' => [$this->yaseen->id],
        ])
        ->assertRedirect(VOICE_MESSAGE_URL)
        ->assertSessionHasNoErrors();

    $message = voice_message_latest($this->team->id);

    expect($message->body)->toContain('Listen to this')
        ->and(voice_message_pivot((int) $message->id))->toBe([
            'kind' => 'voice',
            'duration_seconds' => 20,
        ])
        ->and($message->mentions()->pluck('users.id')->all())->toBe([(int) $this->yaseen->id]);
});

it('refuses the Accountant a voice note with the 403 they get for any message', function () {
    $this->actingAs($this->accountant)
        ->post(voice_message_path($this->team->id), [
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => 10,
        ])
        ->assertForbidden();
});

it('answers 404 for a voice note aimed at somebody else\'s conversation', function () {
    $other = $this->conversations->dmBetween($this->admin, $this->yaseen);

    $this->actingAs($this->tapu)
        ->post(voice_message_path((int) $other->id), [
            'file' => voice_message_recording(),
            'kind' => 'voice',
            'duration' => 10,
        ])
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The claim, at the write rather than at the boundary
|--------------------------------------------------------------------------
*/

it('clamps a length no Form Request ever saw, rather than storing an impossible one', function (int $claimed, ?int $stored) {
    // A job, a console command or an importer never meets StoreMessageRequest. The row still
    // cannot say something impossible.
    $message = app(MessageService::class)->post(
        $this->tapu,
        $this->team,
        null,
        voice_message_recording(),
        [],
        AttachmentKind::Voice,
        $claimed,
    );

    expect(voice_message_pivot((int) $message->id)['duration_seconds'])->toBe($stored);
})->with([
    'a zero becomes the floor' => [0, MessageService::MIN_VOICE_SECONDS],
    'a negative becomes the floor' => [-5, MessageService::MIN_VOICE_SECONDS],
    'a wild claim becomes the ceiling' => [9999, MessageService::MAX_VOICE_SECONDS],
    'an honest one is left alone' => [42, 42],
]);

it('writes no duration onto an attachment that is not a recording', function () {
    $message = app(MessageService::class)->post(
        $this->tapu,
        $this->team,
        null,
        UploadedFile::fake()->create('brief.pdf', 64)->mimeType('application/pdf'),
        [],
        null,
        60,
    );

    expect(voice_message_pivot((int) $message->id))->toBe([
        'kind' => AttachmentKind::File->value,
        'duration_seconds' => null,
    ]);
});
