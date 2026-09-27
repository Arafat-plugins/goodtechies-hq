<?php

use App\Exceptions\FileStateException;
use App\Models\File;
use App\Models\Task;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Upload "scanning": the extension against the actual BYTES (Phase 12)
|--------------------------------------------------------------------------
|
| Part E, Phase 12 Backend: "security review pass (… upload scanning)", and
| Part C §3: "Uploads: server-side type/extension validation (virus scan if
| available)". There is no antivirus on this box and adding a dependency is out
| of scope, so what is achievable is the rest of that sentence: refuse anything
| whose contents are not what its name claims, and never serve anything in a way
| a browser would execute.
|
| `FileService::assertAcceptable()` already does that, and has since Phase 2. It
| reads `UploadedFile::getMimeType()`, which in production is **finfo over the
| temp file's bytes** — not the `Content-Type` the browser sent.
|
| ## Why this file exists anyway
|
| Every existing test of that rule builds its upload with
| `UploadedFile::fake()->create($name, $kb, $mime)`. And
| `Illuminate\Http\Testing\File::getMimeType()` is overridden to return
| **`$mimeTypeToReport ?: MimeType::from($this->name)`** — the string the test
| passed in, or a guess from the file NAME. finfo never runs. So
| `FileServiceTest`'s "refuses a file whose contents are not what its extension
| claims" proves that its own two ARGUMENTS have to agree, and the thing it is
| named after — bytes versus extension — had never been executed. Swap
| `getMimeType()` for `getClientMimeType()` in FileService and the whole suite
| stays green while every rename-and-upload attack starts working.
|
| So every upload below is a REAL `Illuminate\Http\UploadedFile` over a real temp
| file with real bytes in it, and the client-supplied MIME type is set to a LIE
| on purpose — which is the only construction under which the assertion means
| what it says.
|
| Every constant and helper is prefixed SNIFF_ / sniff*, because Pest declares
| both globally across the whole suite (AGENTS.md).
|
*/

/** Bytes that finfo recognises, and what it recognises them as on this box. */
const SNIFF_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";
const SNIFF_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
const SNIFF_PHP = "<?php\n// A web shell, for the avoidance of doubt.\necho shell_exec(\$_GET['c']);\n";
const SNIFF_HTML = "<!doctype html>\n<html><body><script>alert(document.cookie)</script></body></html>\n";

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed();

    $this->sniffAdmin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->sniffTask = Task::query()->whereNotNull('project_id')->firstOrFail();
    $this->sniffFiles = app(FileService::class);
});

/**
 * A real upload: real bytes on disk, a name of our choosing, and a CLIENT-supplied MIME type
 * that is whatever we want it to be.
 *
 * `$clientMimeType` is the header a browser sends and an attacker sets freely. Passing a
 * flattering lie here is the whole point: a check that reads it instead of the bytes passes every
 * test below for the wrong reason, and this is the only way to tell the two apart.
 */
function sniffUpload(string $name, string $bytes, string $clientMimeType): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'sniff');
    file_put_contents($path, $bytes);

    // `$test = true` is what lets an UploadedFile exist outside a real POST. It does NOT change
    // how getMimeType() works: that still goes through Symfony's finfo guesser on $path.
    return new UploadedFile($path, $name, $clientMimeType, null, true);
}

/*
|--------------------------------------------------------------------------
| The bytes decide, not the header
|--------------------------------------------------------------------------
*/

it('refuses a web shell renamed to a document, however the browser labels it', function (string $name, string $claimed): void {
    expect(fn () => $this->sniffFiles->store($this->sniffAdmin, $this->sniffTask, sniffUpload($name, SNIFF_PHP, $claimed)))
        ->toThrow(FileStateException::class);

    expect(File::count())->toBe(0);
})->with([
    // The attack: the extension is on the allow-list and the Content-Type agrees with it. Only
    // the bytes disagree, and only finfo can see them.
    'php bytes called .pdf, labelled application/pdf' => ['invoice.pdf', 'application/pdf'],
    'php bytes called .png, labelled image/png' => ['logo.png', 'image/png'],
    'php bytes called .docx, labelled the OOXML type' => ['brief.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'php bytes called .zip, labelled application/zip' => ['deliverable.zip', 'application/zip'],
]);

it('refuses a script-bearing HTML file renamed to an image', function (): void {
    expect(fn () => $this->sniffFiles->store(
        $this->sniffAdmin,
        $this->sniffTask,
        sniffUpload('screenshot.png', SNIFF_HTML, 'image/png'),
    ))->toThrow(FileStateException::class);
});

it('refuses a genuine document whose extension is not on the allow-list', function (string $name): void {
    expect(fn () => $this->sniffFiles->store($this->sniffAdmin, $this->sniffTask, sniffUpload($name, SNIFF_PDF, 'application/pdf')))
        ->toThrow(FileStateException::class);
})->with([
    // The other direction from the test above: real PDF bytes, honestly labelled, under a name
    // this application will not store. The extension decides what the file is SERVED as, so it
    // is checked on its own and not excused by the contents being harmless.
    'a .php' => ['report.php'],
    'an .html' => ['report.html'],
    'an .svg' => ['report.svg'],
    'an .exe' => ['report.exe'],
    'no extension at all' => ['report'],
]);

it('accepts real bytes under the right name', function (string $name, string $bytes): void {
    // The honest case, and the reason the two refusals above are not just "everything is
    // refused": the same code path, with the bytes and the extension agreeing, stores the file.
    // The client MIME type is still a lie — `application/octet-stream`, which is what a browser
    // sends when it has no idea — to show that a correct upload does not depend on it either.
    $file = $this->sniffFiles->store($this->sniffAdmin, $this->sniffTask, sniffUpload($name, $bytes, 'application/octet-stream'));

    expect($file->exists)->toBeTrue()
        // The stored mime_type is finfo's answer, never the client's.
        ->and($file->mime_type)->not->toBe('application/octet-stream')
        ->and(FileService::TYPES[$file->extension])->toContain($file->mime_type);
})->with([
    'a png' => ['shot.png', SNIFF_PNG],
    'a pdf' => ['brief.pdf', SNIFF_PDF],
]);

/**
 * The regression guard, stated as its own test because it is the one that would have caught the
 * hole this file was written to close.
 *
 * If `assertAcceptable()` ever reads `getClientMimeType()` instead of `getMimeType()`, this
 * upload — PHP bytes, a `.png` name, and a client header that says `image/png` — becomes
 * acceptable and this is the assertion that fails.
 */
it('reads the CONTENTS and not the client Content-Type header', function (): void {
    $upload = sniffUpload('logo.png', SNIFF_PHP, 'image/png');

    expect($upload->getClientMimeType())->toBe('image/png')
        ->and($upload->getMimeType())->not->toBe('image/png');

    expect(fn () => FileService::assertAcceptable($upload))->toThrow(FileStateException::class);
});

/*
|--------------------------------------------------------------------------
| And nothing is ever served in a way a browser would execute
|--------------------------------------------------------------------------
*/

it('refuses even a .txt whose bytes are a script, because finfo does not call that text/plain', function (): void {
    // Worth its own row: `.txt` accepts only `text/plain`, and finfo answers `text/x-php` for
    // anything opening `<?php`. So the allow-list is narrower than "is it text" — which is the
    // behaviour you want and not the behaviour the pairing table obviously promises.
    expect(fn () => FileService::assertAcceptable(sniffUpload('notes.txt', SNIFF_PHP, 'text/plain')))
        ->toThrow(FileStateException::class);
});

it('hands over anything not on the inline list as an attachment, with the row\'s own type', function (): void {
    $file = $this->sniffFiles->store(
        $this->sniffAdmin,
        $this->sniffTask,
        // An honest .txt. What must be true of it is that the browser is never invited to
        // interpret it: the type comes off the row, `nosniff` is beside it, and the disposition
        // is `attachment` because text/plain is not on File::INLINE_TYPES.
        sniffUpload('notes.txt', "Handover notes.\nNothing clever in here.\n", 'text/plain'),
    );

    $response = $this->actingAs($this->sniffAdmin)->get($this->sniffFiles->url($file))->assertOk();

    expect($file->mime_type)->toStartWith('text/')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toStartWith($file->mime_type)
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('states the link TTL in exactly one place', function (): void {
    // Part E asks whether the signed-URL TTL is right and whether it is said once. `expiry()` is
    // the only place `now()` meets the constant, and `url()` is the only caller — so changing the
    // window is one edit. `FileResource` sends `url_expires_at` from the same call, which is how
    // a tab that has been open all morning knows its links are dead without clicking one.
    $this->travelTo(now()->startOfMinute());

    expect((int) now()->diffInMinutes(app(FileService::class)->expiry()))
        ->toBe(FileService::URL_TTL_MINUTES)
        ->and(FileService::URL_TTL_MINUTES)->toBe(15);
});
