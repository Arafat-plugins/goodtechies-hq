<?php

/*
|--------------------------------------------------------------------------
| The class vocabulary DESIGN.md names — POLISH-BACKLOG §E.1, §E.3, §E.17
|--------------------------------------------------------------------------
|
| Two rules in DESIGN.md were true of the document and false of the repo for eleven phases, both
| because nothing checked:
|
|  - §2.2's focus-indicator ratios measured `--ring` opaque while the code wrote
|    `focus-visible:ring-ring/50` in 84 places (§E.1).
|  - §5.13 names `shadow-flat` / `shadow-raised` / `shadow-overlay` as the whole elevation
|    vocabulary, and 85 call sites wrote `shadow-xs`, which is Tailwind's default scale and not
|    something `app.css` defines at all (§E.3, §E.17 — flagged by three agents in three slices).
|
| Both are now swept. This file is what stops the third recurrence: it greps the source the way a
| reviewer would and names the file and line, so a reintroduction fails here rather than in a
| browser eleven phases later.
*/

/**
 * A focus indicator may not be composited: see FocusRingContrastTest for the arithmetic.
 *
 * `aria-invalid:` counts. Those classes set only the ring COLOUR — the width comes from the
 * control's `focus-visible:ring-3` — so an `aria-invalid:ring-destructive/20` paints nothing until
 * the field is focused, and what it paints then is the focus indicator, recoloured and washed out
 * to 1.43:1.
 */
const VOCAB_TRANSLUCENT_FOCUS_RING = '/(?:(?:focus|focus-visible|focus-within|aria-invalid)(?::[a-z-]+)*:)+ring-(?:ring|sidebar-ring|primary|primary-foreground|destructive)\/\d+/';

/** `outline-ring/50` in app.css is §E.1 itself. */
const VOCAB_TRANSLUCENT_OUTLINE = '/\boutline-ring\/\d+/';

/** Tailwind's default shadow scale. `app.css` defines three elevation tokens and no more. */
const VOCAB_OFF_SCALE_SHADOW = '/\bshadow-(?:2xs|xs|sm|md|lg|xl|2xl|inner|none)\b/';

/**
 * **Empty, and it should stay that way.**
 *
 * It held one file for exactly as long as it took two concurrent slices to finish: the live-sync
 * agent owned `LiveUpdateNotice.vue` while this sweep ran, so its `shadow-sm` was named here
 * rather than overwritten under them. The main session swept it the moment both landed.
 *
 * An exemption list is how a vocabulary rule dies — one file, then three, then the rule means
 * nothing. If you are about to add an entry, sweep the file instead.
 */
const VOCAB_SHADOW_EXEMPT = [];

/**
 * @return array<string, string> relative path => contents
 */
function vocabSourceFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root.'/resources/css/app.css'];

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        if ($entry->isFile() && in_array($entry->getExtension(), ['vue', 'ts'], true)) {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    $out = [];
    foreach ($files as $path) {
        $out[ltrim(str_replace($root, '', $path), '/')] = (string) file_get_contents($path);
    }

    return $out;
}

/**
 * @return array<int, string> "path:line  offending text"
 */
function vocabOffences(string $pattern, array $exempt = []): array
{
    $hits = [];

    foreach (vocabSourceFiles() as $path => $contents) {
        if (in_array($path, $exempt, true)) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            // Prose in a comment is allowed to name the class it is explaining.
            $bare = trim($line);
            if (str_starts_with($bare, '*') || str_starts_with($bare, '//') || str_starts_with($bare, '/*')) {
                continue;
            }

            if (preg_match_all($pattern, $line, $found) > 0) {
                foreach ($found[0] as $text) {
                    $hits[] = sprintf('%s:%d  %s', $path, $index + 1, $text);
                }
            }
        }
    }

    return $hits;
}

it('writes no focus ring at partial opacity', function () {
    expect(vocabOffences(VOCAB_TRANSLUCENT_FOCUS_RING))->toBe([]);
});

it('applies the global focus outline at full opacity', function () {
    expect(vocabOffences(VOCAB_TRANSLUCENT_OUTLINE))->toBe([]);
});

it('uses only the three elevation tokens app.css defines', function () {
    // `app.css` never clears Tailwind's own `--shadow-*` namespace, which is why `shadow-xs`
    // compiled quietly for eleven phases: the class worked, so nothing said it was off-vocabulary.
    expect(vocabOffences(VOCAB_OFF_SCALE_SHADOW, VOCAB_SHADOW_EXEMPT))->toBe([]);
});

it('keeps the three elevation tokens defined', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

    foreach (['--shadow-flat', '--shadow-raised', '--shadow-overlay'] as $token) {
        expect($css)->toContain($token.': var(');
    }
});

it('keeps the DM bubble on its own opaque ring', function () {
    // The one place --ring cannot work: over the own-bubble fill. Since 12-77 that fill is
    // --bubble-own (oklch 0.42 0 0), and the ring on it is --bubble-own-foreground (~9:1).
    $body = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/Messages/MessageBody.vue');

    expect($body)->toContain('focus-visible:ring-bubble-own-foreground');
});

it('keeps every elevation token composable, so a shadow cannot delete the focus ring', function () {
    // The second way the ring disappears, and it is not an opacity. Tailwind v4 composes ONE
    // `box-shadow` out of five custom properties:
    //
    //   box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow),
    //               var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
    //
    // `none` is legal only as the SOLE value of `box-shadow`, so `--elevation-flat: none` made that
    // whole list invalid, the declaration was dropped, and the computed value fell back to `none` —
    // taking `--tw-ring-shadow` with it. Every control that carries `shadow-flat` (input, textarea,
    // select, native select, checkbox, radio, switch, toggle, outline button, pin-input slot) then
    // painted NO focus ring at all, while `:focus-visible` matched and the ring classes sat on the
    // element. Measured in Chromium on 2026-09-26: `getComputedStyle(el).boxShadow === 'none'`.
    //
    // A transparent shadow (`0 0 #0000`, which is what Tailwind's own `shadow-none` sets) composes
    // and is invisible. This test is cheaper than the browser run that found it.
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

    preg_match_all('/^\s*(--elevation-[a-z]+)\s*:\s*([^;]+);/m', $css, $found, PREG_SET_ORDER);

    expect($found)->not->toBeEmpty();

    foreach ($found as [, $token, $value]) {
        expect(strtolower(trim($value)))->not->toBe(
            'none',
            "{$token} is the keyword `none`. Inside Tailwind's composed box-shadow list that is "
            .'invalid CSS and it deletes the focus ring on every control carrying that shadow. '
            .'Use `0 0 #0000`.',
        );
    }
});

it('gives every menu, command and select item a ring and not just a highlight', function () {
    // shadcn says where the keyboard is in a menu with `focus:bg-accent` alone, and `--accent` on
    // `--popover` is 1.05:1 in both themes. §E.1's sweep could not see it, because the defect is
    // the ABSENCE of a ring class rather than a washed-out one. Each of these paints an opaque
    // inset ring over its own highlight fill (3.30:1 light, 5.39:1 dark — DESIGN.md §2.2); inset,
    // because the items are flush inside a `p-1` container and an outside ring would overlap the
    // next item and clip at the edge.
    $items = [
        'dropdown-menu/DropdownMenuItem.vue',
        'dropdown-menu/DropdownMenuCheckboxItem.vue',
        'dropdown-menu/DropdownMenuRadioItem.vue',
        'dropdown-menu/DropdownMenuSubTrigger.vue',
        'select/SelectItem.vue',
        'command/CommandItem.vue',
        'combobox/ComboboxItem.vue',
    ];

    foreach ($items as $item) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/ui/'.$item);

        // `toContain` takes a list of needles, not a message, so the failure is spelled out here.
        expect(str_contains($source, 'ring-inset') && str_contains($source, 'ring-ring'))
            ->toBeTrue("{$item} no longer paints an opaque inset focus ring on its highlighted state");
    }
});
