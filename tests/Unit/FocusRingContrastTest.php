<?php

use Tests\Support\Colour;
use Tests\Support\TokenSheet;

/*
|--------------------------------------------------------------------------
| The focus indicator, measured rather than asserted — POLISH-BACKLOG §E.1
|--------------------------------------------------------------------------
|
| WCAG 2.2 §1.4.11 wants a focus indicator at 3:1 against what is behind it. DESIGN.md §2.2 has
| carried `--ring` at 3.45:1 ✅ since Phase 0.5 — the token measured OPAQUE, while `app.css` set
| `outline-ring/50` and every control wrote `focus-visible:ring-ring/50`. Composited at 50 % the
| real figures were 1.90 / 1.93 / 1.87 light and 2.44 / 2.42 / 2.31 dark: a failure on every
| button, link, input, menu item and row in the application, invisible for eleven phases because
| the number lived in a markdown table nobody recomputed.
|
| So the arithmetic lives here. These tests parse the oklch values straight out of
| `resources/css/app.css` — the source of truth DESIGN.md is generated from — resolve `var()`
| chains, read the alpha modifier off the global `@apply … outline-ring` rule, composite, and fail
| under 3:1. Putting `/50` back fails this file; adding a translucent focus ring to a component
| fails this file.
|
| It is deliberately a Unit test: it touches no database and boots no application, so it runs in
| the first and cheapest part of the suite.
*/

const FOCUS_RING_FLOOR = 3.0;

/** The surfaces a focus ring is actually painted over, per theme. */
const FOCUS_RING_SURFACES = [
    '--background',     // the page canvas
    '--card',           // = --popover: a panel, a dropdown, a dialog
    '--muted',          // = --secondary, --accent: a table header strip, a hovered row
    '--sidebar',        // the shell
    '--brand-tint',     // = --sidebar-accent: the active or selected row's fill
];

dataset('focus-ring-themes', [
    'light' => [fn () => TokenSheet::light()],
    'dark' => [fn () => TokenSheet::dark()],
]);

it('renders the global focus outline opaque', function () {
    // `outline-ring/50` is the bug in one character class. Anything under 1.0 here means the
    // browser default outline on every element is composited, and the ratios below stop
    // describing what is on screen.
    expect(TokenSheet::globalOutlineAlpha())->toBe(1.0);
});

it('paints --ring at 3:1 or better over every surface a control sits on', function (Closure $sheet) {
    $tokens = $sheet();
    $ring = $tokens->colour('--ring');

    foreach (FOCUS_RING_SURFACES as $surface) {
        $behind = $tokens->colour($surface)->over($tokens->colour('--background'));
        $ratio = Colour::contrast($ring->over($behind), $behind);

        expect($ratio)->toBeGreaterThanOrEqual(
            FOCUS_RING_FLOOR,
            sprintf(
                '%s: --ring %s over %s %s is %.2f:1, under the %.1f:1 focus-indicator floor',
                $tokens->theme, $ring->hex(), $surface, $behind->hex(), $ratio, FOCUS_RING_FLOOR,
            ),
        );
    }
})->with('focus-ring-themes');

it('would fail the same surfaces if the ring went back to 50 per cent', function (Closure $sheet) {
    // The counter-test. Without it, a future pass could "simplify" the ring back to `/50` and the
    // test above would still be measuring an opaque token that nothing renders.
    $tokens = $sheet();
    $half = $tokens->colour('--ring')->withAlpha(0.5);

    foreach (['--background', '--card', '--muted'] as $surface) {
        $behind = $tokens->colour($surface);

        expect(Colour::contrast($half->over($behind), $behind))->toBeLessThan(FOCUS_RING_FLOOR);
    }
})->with('focus-ring-themes');

it('paints the destructive focus ring at 3:1 or better', function (Closure $sheet) {
    // shadcn's destructive button and badge override the ring to --destructive. They shipped it at
    // /20 light and /40 dark, which is 1.43:1 and 1.96:1 on the canvas — worse than the /50 this
    // slice removed. Opaque, --destructive clears the floor in both themes.
    $tokens = $sheet();
    $ring = $tokens->colour('--destructive');

    foreach (['--background', '--card', '--muted'] as $surface) {
        $behind = $tokens->colour($surface);
        $ratio = Colour::contrast($ring->over($behind), $behind);

        expect($ratio)->toBeGreaterThanOrEqual(
            FOCUS_RING_FLOOR,
            sprintf('%s: --destructive over %s is %.2f:1', $tokens->theme, $surface, $ratio),
        );
    }
})->with('focus-ring-themes');

it('cannot paint --ring on a --primary fill, which is why the DM bubble has its own', function (Closure $sheet) {
    // A DM draws the viewer's own messages in a solid --primary bubble, so a link inside one has
    // --primary behind its focus ring. --ring is the same hue two lightness steps up: it is 1.43:1
    // there in light and 1.08:1 in dark, invisible rather than merely weak, and no opacity change
    // rescues it. The bubble paints --primary-foreground instead. This test is the reason that
    // exception exists, kept as a measurement so nobody "unifies" it away.
    $tokens = $sheet();
    $primary = $tokens->colour('--primary');

    expect(Colour::contrast($tokens->colour('--ring')->over($primary), $primary))
        ->toBeLessThan(FOCUS_RING_FLOOR);

    expect(Colour::contrast($tokens->colour('--primary-foreground')->over($primary), $primary))
        ->toBeGreaterThanOrEqual(FOCUS_RING_FLOOR);
})->with('focus-ring-themes');

it('records the one surface the ring is measured against and never painted on', function (Closure $sheet) {
    // --brand-tint-strong is hover on a row that is ALREADY selected, and the opaque ring is
    // 2.92:1 over it in light mode. It never reaches a screen: a Tailwind ring is drawn outside
    // the element's box, so a focused-and-hovered selected row paints its ring over --sidebar
    // (3.45:1), not over its own fill. Recorded as a number rather than left for the next agent
    // to re-derive — the same reason §2.2 keeps its ❌ rows.
    $tokens = $sheet();
    $behind = $tokens->colour('--brand-tint-strong');
    $ratio = Colour::contrast($tokens->colour('--ring')->over($behind), $behind);

    expect(round($ratio, 2))->toBe(
        $tokens->theme === 'light' ? 2.92 : 4.50,
        sprintf(
            '%s: --ring over --brand-tint-strong now measures %.2f:1. If a token moved, recompute '
            .'it here and in DESIGN.md §2.2 rather than deleting the row.',
            $tokens->theme, $ratio,
        ),
    );
})->with('focus-ring-themes');
