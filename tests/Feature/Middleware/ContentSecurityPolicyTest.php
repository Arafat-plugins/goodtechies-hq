<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Models\Task;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Content-Security-Policy (Phase 12 — the security pass)
|--------------------------------------------------------------------------
|
| Part E, Phase 12 Backend: "security review pass (headers, CSP, …)". The
| policy itself, and why each directive is the shape it is, is documented on
| App\Http\Middleware\ContentSecurityPolicy. This file is the part a comment
| cannot do.
|
| **The test that matters most is the hash one.** `script-src` carries the
| sha256 of the inline theme script in `resources/views/app.blade.php` — the
| one that puts `dark` on <html> before first paint. A hash in a header and a
| script in a Blade file are two things that have to stay byte-identical, and
| nothing in PHP couples them. So this file renders the login page, pulls the
| inline script out of the HTML, hashes it and asserts the policy allows THAT.
| Edit the script by one space and this fails — which is the whole reason the
| policy is allowed to use a hash at all. Without it the theme would simply
| stop applying in production, with a console message nobody reads.
|
| Every constant and helper is prefixed CSP_ / csp*, because Pest declares both
| globally across the whole suite (AGENTS.md).
|
*/

beforeEach(function (): void {
    $this->seed();

    $this->cspAdmin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->cspEmployee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->cspAccountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/** The header, split into directive => the whole directive line. */
function cspDirectives(string $header): array
{
    $out = [];

    foreach (explode(';', $header) as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        $name = explode(' ', $part)[0];
        $out[$name] = $part;
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| It is on every response, including the ones no route answers
|--------------------------------------------------------------------------
*/

it('sets the policy on a guest page, a signed-in page on each surface, and a 404', function (string $actor, string $url): void {
    $response = $actor === 'guest'
        ? $this->get($url)
        : $this->actingAs($this->{$actor})->get($url);

    expect($response->headers->get('Content-Security-Policy'))->toBe(ContentSecurityPolicy::policy());
})->with([
    'the login page' => ['guest', '/login'],
    'the admin dashboard' => ['cspAdmin', '/admin/dashboard'],
    'the employee dashboard' => ['cspEmployee', '/employee/dashboard'],
    'the accountant dashboard' => ['cspAccountant', '/accountant/dashboard'],
    // A path no route matches throws before the `web` group is entered. The middleware is
    // registered GLOBALLY in bootstrap/app.php for exactly this row: a 404 is the one page
    // somebody can reach on any URL they like, and it used to be the only HTML this
    // application served with no policy on it.
    'a path no route answers' => ['guest', '/no-such-page-exists-here'],
    'a 404 while signed in' => ['cspAdmin', '/admin/no-such-screen'],
]);

/*
|--------------------------------------------------------------------------
| The hash and the script it is the hash of
|--------------------------------------------------------------------------
*/

it('allows exactly the inline script the root view renders, by hash', function (): void {
    $html = $this->get('/login')->getContent();

    // Every <script> with no `type` and no `src` — i.e. every EXECUTABLE inline script in the
    // document. Inertia's own `<script type="application/json">` data blocks are excluded by the
    // `type=` test, which is the point: they are not executable and Chromium does not check them
    // against script-src (verified in a browser, not assumed).
    preg_match_all('/<script(?![^>]*\b(?:type|src)=)[^>]*>(.*?)<\/script>/s', $html, $matches);

    expect($matches[1])->toHaveCount(1, 'The root view should render exactly one executable inline script (the theme one).');

    $hash = 'sha256-'.base64_encode(hash('sha256', $matches[1][0], true));

    expect($hash)->toBe(
        ContentSecurityPolicy::THEME_SCRIPT_HASH,
        'The inline theme script in resources/views/app.blade.php has changed. Update '
        .'ContentSecurityPolicy::THEME_SCRIPT_HASH to '.$hash.' — until you do, the theme will '
        .'be blocked in production and the page will flash the wrong colours on every load.',
    );

    expect(cspDirectives(ContentSecurityPolicy::policy())['script-src'])->toContain($hash);
});

/*
|--------------------------------------------------------------------------
| The directives that are the point of having a policy at all
|--------------------------------------------------------------------------
*/

it('never allows inline or eval SCRIPT', function (): void {
    $scriptSrc = cspDirectives(ContentSecurityPolicy::policy())['script-src'];

    // A hash in script-src DISABLES 'unsafe-inline' for scripts even when both are present, so
    // this is belt and braces — and it is the assertion that fails if somebody "fixes" a blocked
    // script by widening the directive instead of adding its hash.
    expect($scriptSrc)->not->toContain("'unsafe-inline'")
        ->and($scriptSrc)->not->toContain("'unsafe-eval'")
        ->and($scriptSrc)->not->toContain('*');
});

it('closes the routes that need no script at all', function (string $directive, string $expected): void {
    expect(cspDirectives(ContentSecurityPolicy::policy())[$directive] ?? null)->toBe($expected);
})->with([
    'a default of self' => ['default-src', "default-src 'self'"],
    'no plugins' => ['object-src', "object-src 'none'"],
    'no reachable base rewrite' => ['base-uri', "base-uri 'self'"],
    'no framing by anybody else' => ['frame-ancestors', "frame-ancestors 'self'"],
    'no off-site form post' => ['form-action', "form-action 'self'"],
    'nothing framed' => ['frame-src', "frame-src 'none'"],
]);

/**
 * `style-src` carries `'unsafe-inline'`, and this test exists so that is a recorded decision
 * rather than an oversight somebody tightens and then reverts.
 *
 * Two things force it, both measured against the built assets: Vue's `:style` bindings (Gantt bar
 * geometry, chart CSS variables, every reka-ui popover position) are inline style ATTRIBUTES,
 * which `style-src` governs and for which no hash is possible; and `@unovis/ts` — every chart on
 * every dashboard and report — injects `<style>` elements at runtime from its bundled CSS-in-JS
 * sheet. Removing it means dropping @unovis and hand-writing the charts' SVG.
 */
it('allows inline STYLE, deliberately, and says what forces it', function (): void {
    expect(cspDirectives(ContentSecurityPolicy::policy())['style-src'])->toContain("'unsafe-inline'");
});

it('names the webfont origin on both directives that need it', function (): void {
    $directives = cspDirectives(ContentSecurityPolicy::policy());

    // resources/views/app.blade.php loads Inter from Bunny's CDN, and the stylesheet it answers
    // with points its @font-face rules back at the same host — so the origin has to be on
    // style-src AND font-src. Measured in Chromium: six woff2 responses, all 200, with the
    // policy enforcing.
    expect($directives['style-src'])->toContain('https://fonts.bunny.net')
        ->and($directives['font-src'])->toContain('https://fonts.bunny.net');
});

it('allows the object URLs the voice recorder and the recovery-code download create', function (): void {
    $directives = cspDirectives(ContentSecurityPolicy::policy());

    expect($directives['img-src'])->toContain('blob:')
        ->and($directives['media-src'])->toContain('blob:')
        ->and($directives['worker-src'])->toContain('blob:');
});

/*
|--------------------------------------------------------------------------
| The download route keeps its own, tighter policy
|--------------------------------------------------------------------------
*/

it('does not overwrite the much stricter policy FileService puts on streamed bytes', function (): void {
    Storage::fake('local');

    $task = Task::query()->whereNotNull('project_id')->firstOrFail();
    $files = app(FileService::class);

    $file = $files->store(
        $this->cspAdmin,
        $task,
        UploadedFile::fake()->create('brief.pdf', 4, 'application/pdf'),
    );

    $header = $this->actingAs($this->cspAdmin)
        ->get($files->url($file))
        ->assertOk()
        ->headers->get('Content-Security-Policy');

    // FileService::download()'s own policy, not this middleware's. Two Content-Security-Policy
    // headers are enforced as the INTERSECTION of both, so a middleware that appended its own
    // here would not loosen this one — it would narrow it, silently, and an inline PDF would
    // stop rendering for a reason nothing in either file mentions.
    expect($header)->toContain("default-src 'none'")
        ->and($header)->toContain('sandbox')
        ->and($header)->not->toBe(ContentSecurityPolicy::policy());
});
