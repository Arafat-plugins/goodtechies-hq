<?php

use App\Support\TagColour;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A tag's colour is a status token name
|--------------------------------------------------------------------------
|
| DESIGN.md §5.1 forbids a raw colour value anywhere but the logo's own SVG,
| and §5.3 says the whole palette is the brand colour, the neutral ramp and
| the eight `--status-*` sets. A tag therefore stores the NAME of one of those
| eight, and the browser resolves it against app.css on the machine that is
| drawing it — which is what makes a tag chip follow the theme instead of
| being a light-mode value shipped into dark mode.
|
| The front end's half of that contract is the `StatusKey` union in
| StatusBadge.vue and the `tagTone()` guard in TaskList.vue. This file reads
| both out of the actual .vue files, so the day somebody adds a ninth tone to
| one side and not the other, this fails rather than a chip going invisible.
|
*/

/**
 * The repository root. `base_path()` is unavailable here: tests/Pest.php binds the
 * application to the Feature and Permissions folders only, and this is a unit test.
 */
function repoPath(string $relative): string
{
    return dirname(__DIR__, 2).'/'.$relative;
}

/** The `StatusKey` union, read out of the component that declares it. */
function statusKeys(): array
{
    $source = file_get_contents(repoPath('resources/js/Components/StatusBadge.vue'));
    // `before`, not `between`: Str::between() runs to the LAST match, which would swallow the
    // whole component.
    $union = Str::before(Str::after($source, 'export type StatusKey ='), ';');

    preg_match_all("/'([a-z]+)'/", $union, $matches);

    return $matches[1];
}

it('is exactly the StatusKey union the badge draws', function () {
    expect(TagColour::values())->toEqualCanonicalizing(statusKeys())
        ->and(TagColour::values())->toHaveCount(8);
})->group('phase2');

it('never carries a colour value, only a name', function () {
    foreach (TagColour::values() as $value) {
        expect($value)->not->toStartWith('#')
            ->not->toStartWith('rgb')
            ->not->toStartWith('hsl')
            ->not->toStartWith('oklch')
            // A token name, so the badge's class lookup finds a token behind it.
            ->toMatch('/^[a-z]+$/');
    }
})->group('phase2');

it('names a colour rather than a status', function () {
    // The person choosing a label colour is choosing a colour. Calling `progress` "In
    // progress" in the picker would be the picker lying about what it does.
    expect(TagColour::Progress->label())->toBe('Blue')
        ->and(TagColour::Cancelled->label())->toBe('Red')
        ->and(TagColour::Todo->label())->toBe('Grey');
})->group('phase2');

it('covers every tone the front end would otherwise have to fall back from', function () {
    // tagTone() in TaskList.vue neutralises an unknown colour to `todo` so a bad value cannot
    // render an unstyled pill. Because the enum IS the full union, that fallback is unreachable
    // for anything this application stored — which is what turns it into a safety net rather
    // than a silent recolouring of perfectly valid data.
    $source = file_get_contents(repoPath('resources/js/Components/Tasks/TaskList.vue'));

    expect($source)->toContain('export function tagTone(colour: string): StatusKey');

    foreach (statusKeys() as $key) {
        expect(TagColour::tryFrom($key))->not->toBeNull();
    }
})->group('phase2');
