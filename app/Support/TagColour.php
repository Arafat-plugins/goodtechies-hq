<?php

namespace App\Support;

/**
 * What a tag's colour actually is: the NAME of one of the eight status tones, never a value.
 *
 * ## Why a token name and not a hex
 *
 * DESIGN.md §5.1 forbids a raw hex, `rgb()` or `hsl()` anywhere but inside the logo's own SVG,
 * and §5.3 says the entire palette is `--brand` plus the neutral ramp plus the eight
 * `--status-*` sets — there is no "pick any colour" in this application. A hex stored on a row
 * would be a colour that cannot follow the theme: it is chosen in light mode, written to the
 * database, and is then a light-mode bug shipped into dark mode on every screen that draws it,
 * with no token change able to reach it.
 *
 * A token NAME follows the theme for free, because the browser resolves it against `app.css` on
 * the machine that is rendering it. The tag chip is already drawn by `StatusBadge`, whose
 * `StatusKey` union is exactly these eight keys, and whose class lookup is
 * `bg-status-<key>-bg text-status-<key>-fg border-status-<key>-border`. So the cases below are
 * not a parallel list that has to be kept in step with the front end — they ARE that union,
 * written on the server, and the contract test in tests/Unit/TagColourTest.php fails if the two
 * ever stop matching.
 *
 * ## Why all eight, including `cancelled` and `todo`
 *
 * The temptation is to keep the "bad" tones out of a label picker. It is the wrong instinct
 * twice over: a red label ("Blocked", "Client escalation") is a thing agencies genuinely need,
 * and a palette that is a subset of the badge's would make `tagTone()`'s neutral fallback in
 * TaskList.vue reachable for a perfectly valid stored value. With all eight here that fallback
 * becomes unreachable for anything this application stored, which is what makes it a safety net
 * rather than a silent recolouring.
 *
 * ## Why an enum AND a CHECK constraint
 *
 * The brief's rule is that an invalid colour must be impossible rather than discouraged, so it
 * is stated three times, at three different distances from the database:
 *
 *   - here, so PHP has one list and a `Rule::enum` to build the validation message from;
 *   - in StoreTagRequest / UpdateTagRequest, so a bad value is a sentence under a field;
 *   - in `tags_colour_is_a_status_token` (migration 2026_09_22_000006), so a seeder, a console
 *     command, a raw `DB::table()->insert()` or a psql session is refused by PostgreSQL itself.
 *
 * Only the last one is enforcement. The first two are error messages.
 */
enum TagColour: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case Progress = 'progress';
    case Review = 'review';
    case Changes = 'changes';
    case Done = 'done';
    case Waiting = 'waiting';
    case Cancelled = 'cancelled';

    /**
     * The default a tag is born with when the caller does not choose.
     *
     * The neutral one. A label whose colour nobody picked should not be shouting.
     */
    public const DEFAULT = self::Todo;

    /**
     * Every value, as the CHECK constraint and the validation rule want them.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * What the colour is called in a picker.
     *
     * Deliberately NOT the status label: a tag coloured `progress` is not "In progress", it is
     * blue. The person choosing a label colour is choosing a colour, and naming it after a
     * status the tag has nothing to do with would be the picker lying about what it does.
     */
    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Cyan',
            self::Todo => 'Grey',
            self::Progress => 'Blue',
            self::Review => 'Amber',
            self::Changes => 'Magenta',
            self::Done => 'Green',
            self::Waiting => 'Orange',
            self::Cancelled => 'Red',
        };
    }
}
