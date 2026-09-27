<?php

/*
|--------------------------------------------------------------------------
| The HTTPS guarantee (Phase 12 — the security pass, POLISH-BACKLOG §E.13)
|--------------------------------------------------------------------------
|
| §E.13, decision 12-5: the Back-button protection on a first-sign-in password
| relies on `Inertia::encryptHistory()`, whose `encryptData` uses
| `crypto.subtle` — which browsers expose **only on a secure origin**, and which
| falls back to **plaintext with a console warning** when it is missing rather
| than failing. So the day the VPS is reached over plain `http://`, Back can
| restore a generated password from history and nothing announces it.
|
| §E.13 says the fix is a deploy-time guarantee — "HTTPS enforced,
| SESSION_SECURE_COOKIE, an HSTS header — and a check that fails loudly rather
| than warning quietly". This file is that check. There is no nginx on a
| developer's machine or in CI, so the only thing that can fail loudly is an
| assertion about the shipped configuration, and the alternative — a comment in
| deploy/nginx.conf saying it matters — is precisely the quiet warning §E.13 is
| about.
|
| The guarantee has three parts and they are not interchangeable:
|
|   1. **`SESSION_SECURE_COOKIE=true`** is the one that cannot be skipped
|      quietly. A secure cookie is simply not sent over plain HTTP, so nobody
|      can hold a session on a non-secure origin at all and the degraded
|      `crypto.subtle` path is never reached by a signed-in person.
|   2. **Certbot's HTTP→HTTPS redirect** (`install.sh` passes `--redirect`) means
|      a person who types the bare hostname arrives on HTTPS.
|   3. **HSTS** removes the one plain-HTTP request that redirect still makes,
|      from the second visit onwards.
|
| Every constant and helper is prefixed TLS_ / tls*, because Pest declares both
| globally across the whole suite (AGENTS.md).
|
*/

/** Read one of the shipped deploy files. */
function tlsDeployFile(string $name): string
{
    $path = base_path('deploy/'.$name);

    expect(file_exists($path))->toBeTrue('deploy/'.$name.' is missing.');

    return (string) file_get_contents($path);
}

/*
|--------------------------------------------------------------------------
| 1. The secure cookie
|--------------------------------------------------------------------------
*/

it('ships SESSION_SECURE_COOKIE=true and an https APP_URL in the production env template', function (): void {
    $env = tlsDeployFile('.env.production.example');

    expect($env)->toContain("\nSESSION_SECURE_COOKIE=true")
        ->and($env)->toMatch('/\nAPP_URL=https:\/\//')
        ->and($env)->toContain("\nAPP_ENV=production")
        ->and($env)->toContain("\nAPP_DEBUG=false");
});

it('marks the session cookie secure when the env says so', function (): void {
    // The env template is a template; this is the config actually reading it, so a future
    // config/session.php that hard-coded `false` would fail here rather than in production.
    config(['session.secure' => true]);

    $cookies = collect($this->get('/login')->headers->getCookies())->keyBy(fn ($c) => $c->getName());

    $session = $cookies->get((string) config('session.cookie'));
    $csrf = $cookies->get('XSRF-TOKEN');

    expect($session)->not->toBeNull()
        ->and($session->isSecure())->toBeTrue()
        ->and($session->isHttpOnly())->toBeTrue();

    // The CSRF cookie is secure too, and deliberately NOT httpOnly — the Inertia client reads it
    // to put the token on every mutating request. Asserted so the pair cannot be "fixed" into
    // matching and break every form.
    expect($csrf)->not->toBeNull()
        ->and($csrf->isSecure())->toBeTrue()
        ->and($csrf->isHttpOnly())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 2. The redirect
|--------------------------------------------------------------------------
*/

it('asks certbot to install the HTTP to HTTPS redirect', function (): void {
    $install = tlsDeployFile('install.sh');

    // Both spellings: the non-interactive run the script performs, and the command it prints for
    // an operator to run by hand when it cannot. A `--redirect` on one and not the other is how a
    // manually finished install ends up serving both schemes forever.
    expect(substr_count($install, '--redirect'))->toBeGreaterThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| 3. HSTS, and only over HTTPS
|--------------------------------------------------------------------------
*/

it('sends HSTS, for a year, and only on a secure scheme', function (): void {
    $nginx = tlsDeployFile('nginx.conf');

    // A map rather than a literal: this file as shipped is the port-80 server, because certbot
    // has not run yet, and a UA must ignore HSTS on a non-secure transport (RFC 6797 §7.2). An
    // empty value makes nginx omit the header, so the same line is silent on 80 and correct on
    // 443 with nothing to come back and change.
    expect($nginx)->toContain('map $scheme $hq_hsts')
        ->and($nginx)->toContain('https   "max-age=31536000"')
        ->and($nginx)->toContain('add_header Strict-Transport-Security $hq_hsts always;');

    // No `preload`: it is close to irreversible and is the client's decision, not a default.
    // No `includeSubDomains`: this application does not own its siblings. Asserted against the
    // header VALUE and not the file, which discusses both in a comment.
    preg_match('/https\s+"([^"]*)"/', $nginx, $value);

    expect($value[1] ?? '')->toBe('max-age=31536000');
});

it('keeps the three transport headers that were already there', function (string $header): void {
    expect(tlsDeployFile('nginx.conf'))->toContain($header);
})->with([
    'framing' => ['add_header X-Frame-Options "SAMEORIGIN" always;'],
    'sniffing' => ['add_header X-Content-Type-Options "nosniff" always;'],
    'referrer' => ['add_header Referrer-Policy "strict-origin-when-cross-origin" always;'],
]);

it('does not set a Content-Security-Policy at the nginx level', function (): void {
    // Two Content-Security-Policy headers are enforced as the INTERSECTION of both. The
    // application sets one (App\Http\Middleware\ContentSecurityPolicy) and
    // FileService::download() sets a much tighter one on streamed bytes; a third added here would
    // silently narrow that one and an inline PDF would stop rendering for a reason written down
    // in neither file.
    expect(tlsDeployFile('nginx.conf'))->not->toContain('add_header Content-Security-Policy');
});

it('tells PHP when a request arrived over TLS', function (): void {
    // Laravel decides `$request->isSecure()` from this, and that decides whether the `secure`
    // session cookie is honoured and whether a file download's temporarySignedRoute is signed as
    // https. Debian's fastcgi_params ships the line; a guarantee should not rest on a
    // distribution default.
    expect(tlsDeployFile('nginx.conf'))->toContain('fastcgi_param HTTPS $https if_not_empty;');
});
