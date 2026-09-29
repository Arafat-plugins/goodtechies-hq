/**
 * One colour per person — messaging polish.
 *
 * A team channel where every avatar is the same grey makes the reader read every name to see
 * who is talking. So each person gets one of eight tints (`--person-1…8` in `app.css`, measured
 * ≥ 6:1 fg-on-fill and ≥ 7:1 fg-on-card in both themes) and keeps it everywhere: the avatar in a
 * thread, the author's name above their message, the members list, the new-message picker.
 *
 * **Chosen from the user id, never at random**, so the same person is the same colour on every
 * screen, after every reload and for everybody looking. Consecutive ids — which is how a small
 * agency's accounts were created — land on different colours, so two people who talk back and
 * forth are never the same tint; with more than eight people the palette cycles.
 *
 * The classes are written out in full rather than built from the number: Tailwind only
 * generates a class it can see in the source.
 */
const PALETTE = [
    { avatar: 'bg-person-1 text-person-1-fg', name: 'text-person-1-fg' },
    { avatar: 'bg-person-2 text-person-2-fg', name: 'text-person-2-fg' },
    { avatar: 'bg-person-3 text-person-3-fg', name: 'text-person-3-fg' },
    { avatar: 'bg-person-4 text-person-4-fg', name: 'text-person-4-fg' },
    { avatar: 'bg-person-5 text-person-5-fg', name: 'text-person-5-fg' },
    { avatar: 'bg-person-6 text-person-6-fg', name: 'text-person-6-fg' },
    { avatar: 'bg-person-7 text-person-7-fg', name: 'text-person-7-fg' },
    { avatar: 'bg-person-8 text-person-8-fg', name: 'text-person-8-fg' },
] as const;

/** Somebody who has left (no id) keeps the neutral avatar rather than borrowing a colour. */
const NEUTRAL = { avatar: 'bg-muted text-muted-foreground', name: 'text-foreground' } as const;

export interface PersonTone {
    /** Fill and initials, for `AvatarFallback`. */
    avatar: string;
    /** The same person's name, written in their colour. */
    name: string;
}

export function personTone(userId: number | null | undefined): PersonTone {
    if (userId === null || userId === undefined || !Number.isFinite(userId)) {
        return NEUTRAL;
    }

    const index = ((Math.trunc(userId) - 1) % PALETTE.length + PALETTE.length) % PALETTE.length;

    return PALETTE[index];
}
