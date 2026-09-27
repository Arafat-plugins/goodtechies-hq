<?php

use RecursiveDirectoryIterator as MenuDirectory;
use RecursiveIteratorIterator as MenuWalk;

/*
|--------------------------------------------------------------------------
| "Menu item opens a dialog" goes through one helper — decision 5-20
|--------------------------------------------------------------------------
|
| reka's Dialog restores focus to whatever held it when the dialog opened. Opened
| from a `DropdownMenuItem`, that is an element the `⋯` menu has already
| unmounted — so the restore lands on `<body>` and a keyboard user is dropped at
| the top of the document, mid-table, having spent every Tab it took to get there.
|
| It was fixed on the Holidays screen, then again on Income, then again on
| Expenses, each time with a local `setTimeout(…, 0)` and a local lookup of
| `button[aria-label^="Actions for "]`. Three copies of a fix is a fix that is
| missing somewhere, and it was missing on Projects, Clients, Employees, the
| Recurring panel, the project status menu and the task status menu — every other
| row menu in the application.
|
| `resources/js/lib/menuFocus.ts` is now the one copy. This file is what stops the
| fourth: it greps the source the way a reviewer would and names the file.
|
| **A browser is still the proof.** A focus trap is not provable by reading code,
| and this asserts a wiring rather than a behaviour — real Tab and Enter presses
| are what confirmed the fix, per the brief. What this catches is the NEXT screen,
| written by somebody who never read decision 5-20.
|
| Constants and functions in a Pest file are global, so everything here is
| prefixed MENU_.
|
*/

/** The helper every menu-opened overlay is expected to use. */
const MENU_HELPER = 'resources/js/lib/menuFocus.ts';

/**
 * @return array<string, string> relative path => contents
 */
function menuVueFiles(): array
{
    $root = dirname(__DIR__, 2);
    $out = [];

    $walk = new MenuWalk(new MenuDirectory($root.'/resources/js', FilesystemIterator::SKIP_DOTS));

    foreach ($walk as $entry) {
        if (! $entry->isFile() || $entry->getExtension() !== 'vue') {
            continue;
        }

        $path = ltrim(str_replace($root, '', $entry->getPathname()), '/');

        // `Components/ui/` is generated shadcn-vue and is never hand-edited (AGENTS.md).
        if (str_contains($path, 'resources/js/Components/ui/')) {
            continue;
        }

        $out[$path] = (string) file_get_contents($entry->getPathname());
    }

    ksort($out);

    return $out;
}

/** Does this file IMPORT the thing, as opposed to mentioning it in a comment? */
function menuImports(string $source, string $symbol, string $module): bool
{
    return preg_match(
        '/import\s*\{[^}]*\b'.preg_quote($symbol, '/').'\b[^}]*\}\s*from\s*\''.preg_quote($module, '/').'\'/s',
        $source,
    ) === 1;
}

/** Does it mount an overlay — the ui dialog primitives, or a `*Dialog.vue` of its own? */
function menuOpensAnOverlay(string $source): bool
{
    return str_contains($source, "from '@/Components/ui/dialog'")
        || preg_match('/import\s+\w*Dialog\s+from\s+\'@\//', $source) === 1;
}

it('routes every menu-opened overlay through lib/menuFocus.ts', function () {
    $missing = [];

    foreach (menuVueFiles() as $path => $source) {
        $fromAMenu = menuImports($source, 'DropdownMenuItem', '@/Components/ui/dropdown-menu');

        if (! $fromAMenu || ! menuOpensAnOverlay($source)) {
            continue;
        }

        if (! menuImports($source, 'useMenuDialog', '@/lib/menuFocus')) {
            $missing[] = $path;
        }
    }

    // **Empty, and with no exemption list**, which is the point: `Shared/Payroll/Show.vue` opens
    // three dialogs and does NOT appear here, because it opens them from ordinary buttons that stay
    // mounted and imports no menu item — its own id-based restore is about a different problem
    // (decision 9-27: a transition deletes its own trigger). A file that both carries a menu item
    // and mounts a dialog has decision 5-20's bug unless it uses the helper.
    expect($missing)->toBe([]);
});

it('keeps the accessible-name focus lookup in one place', function () {
    // The fingerprint of the three local copies: find the row's `⋯` by rebuilding the accessible
    // name `DataTable` gave it and matching the visible one. The helper captures the ELEMENT
    // instead — a reference cannot be spelled wrong, and it cannot pick the hidden twin of the two
    // triggers `DataTable` renders for the table and the stacked card list.
    $offences = [];

    foreach (menuVueFiles() as $path => $source) {
        if (str_contains($source, 'aria-label^="Actions for ')) {
            $offences[] = $path;
        }
    }

    expect($offences)->toBe([]);
});

it('keeps the one-tick deferral in one place', function () {
    // The other half of the copied fix: `setTimeout(…, 0)` so the menu can finish dismissing before
    // the dialog claims the focus trap. Deferring without restoring leaves the bug and restoring
    // without deferring is a race, so the pair travels together — inside the helper.
    $offences = [];

    foreach (menuVueFiles() as $path => $source) {
        if (! menuImports($source, 'DropdownMenuItem', '@/Components/ui/dropdown-menu')) {
            continue;
        }

        foreach (explode("\n", $source) as $index => $line) {
            $bare = trim($line);

            // Prose explaining the pattern is allowed to name it.
            if (str_starts_with($bare, '*') || str_starts_with($bare, '//') || str_starts_with($bare, '/*')) {
                continue;
            }

            if (str_contains($line, 'setTimeout')) {
                $offences[] = sprintf('%s:%d', $path, $index + 1);
            }
        }
    }

    expect($offences)->toBe([]);
});

it('states the helper contract it is checking for', function () {
    // The helper itself, named rather than assumed: a rename that left the files above importing
    // something that no longer exists would otherwise fail in the build and not here.
    $helper = (string) file_get_contents(dirname(__DIR__, 2).'/'.MENU_HELPER);

    expect($helper)->toContain('export function useMenuDialog')
        // Both halves, and the selector the capture depends on — reka writes `aria-expanded` on an
        // open menu's trigger, and that attribute is the whole mechanism.
        ->and($helper)->toContain('openFromMenu')
        ->and($helper)->toContain('returnFocus')
        ->and($helper)->toContain('[aria-haspopup="menu"][aria-expanded="true"]');
});
