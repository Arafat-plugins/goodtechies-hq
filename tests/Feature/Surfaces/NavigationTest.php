<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The sidebar is a promise, and nothing was checking it
|--------------------------------------------------------------------------
|
| `resources/js/navigation/{admin,employee,accountant}.ts` is the only place
| a sidebar row is defined, and until Phase 12 **no test read those files at
| all**. Two things can go wrong there and both did:
|
|   1. A row points at a route that does not exist — a dead link in the one
|      place a person is most likely to click.
|   2. A row stays `phase: N` after phase N has shipped. `types.ts` says
|      enabling a row is "add `href` and remove `phase`", which is one line
|      and therefore one line somebody forgets. The employee shell's
|      **Notifications** row sat in the *Coming soon* disclosure labelled
|      `P2` for **ten phases** while `GET /notifications` worked perfectly —
|      so the people being notified were told the Notification Center was not
|      built yet. The client found it, not the suite.
|
| Both checks are cheap, and neither needs a browser: the nav files are
| declarative TypeScript and the hrefs are literal strings.
|
*/

/** Every `href: '…'` in the three nav files, with the file it came from. */
function navigationHrefs(): array
{
    $found = [];

    foreach (['admin', 'employee', 'accountant'] as $shell) {
        $path = resource_path("js/navigation/{$shell}.ts");

        if (! is_file($path)) {
            continue;
        }

        preg_match_all("/href: '([^']+)'/", (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $href) {
            $found[] = ['shell' => $shell, 'href' => $href];
        }
    }

    return $found;
}

/** Every `phase: N` still left in the three nav files. */
function navigationPhaseGated(): array
{
    $found = [];

    foreach (['admin', 'employee', 'accountant'] as $shell) {
        $path = resource_path("js/navigation/{$shell}.ts");

        if (! is_file($path)) {
            continue;
        }

        // The label and the phase are on one line in this repo's style:
        //   { label: 'Notifications', icon: Bell, phase: 2 },
        // Comments mentioning `phase:` in prose are not matched, because they do not carry a
        // `label:` on the same line.
        preg_match_all("/label: '([^']+)'[^\\n]*phase: (\\d+)/", (string) file_get_contents($path), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $found[] = $shell.' → '.$match[1].' (P'.$match[2].')';
        }
    }

    sort($found);

    return $found;
}

it('points every sidebar row at a route that exists', function (): void {
    $dead = [];

    foreach (navigationHrefs() as $row) {
        // **Matched by the router, not compared against a list of URI patterns.** A first
        // version of this test compared the href to `$route->uri()` as a string and reported
        // ten false failures: `/attendance` is served by `attendance/{employee?}`, and
        // `/admin/reports/task` is a deep link into `admin/reports/{report}`. Both are exactly
        // the kind of row worth having, and a test that cannot see them is a test somebody
        // deletes. Asking the router is also the same resolution the application performs, so
        // this cannot drift from it.
        //
        // The query string stays on: `/employee/tasks/calendar`'s siblings and any row that
        // deep-links with `?…` are matched as written, and `Request::create()` splits it off.
        try {
            Route::getRoutes()->match(Request::create($row['href'], 'GET'));
        } catch (Throwable) {
            $dead[] = $row['shell'].' → '.$row['href'];
        }
    }

    expect($dead)->toBe([], "Sidebar rows pointing at routes that do not exist:\n  ".implode("\n  ", $dead));
})->group('phase12', 'navigation');

it('has no row still waiting for a phase that has already shipped', function (): void {
    // **The inventory, not a rule.** A phase-gated row is legitimate while its phase is genuinely
    // unbuilt — Phase 11's extension may well add one. What is not legitimate is a row sitting
    // there unnoticed, so the list is written down: adding one, or removing one, is a deliberate
    // edit to this line rather than something that happens quietly.
    //
    // It is empty as of Phase 12. Every row in every shell points somewhere real.
    $expected = [];

    expect(navigationPhaseGated())->toBe(
        $expected,
        "The phase-gated sidebar rows changed.\n".
        "If a phase just shipped, give its row an `href` and delete its `phase` (types.ts).\n".
        'If you are adding a row for unbuilt work, add it to $expected here on purpose.',
    );
})->group('phase12', 'navigation');

it('reads the nav files it claims to read', function (): void {
    // A regex that silently matches nothing would make both tests above pass forever. This is
    // the guard on the guard: the files exist, and they contain the shapes being parsed.
    expect(navigationHrefs())->not->toBeEmpty()
        ->and(count(navigationHrefs()))->toBeGreaterThan(40);
})->group('phase12', 'navigation');
