<script setup lang="ts">
import { computed } from 'vue';
import type { MessagePerson } from '@/Components/Messages/messages';
import { cn } from '@/lib/utils';

/**
 * What somebody actually wrote: their line breaks, their links as plain links, and the names
 * they used marked as names.
 *
 * ## Why this is a component and not a `v-html`
 *
 * A message is text a colleague typed. Rendering it as HTML would make every message an XSS
 * hole in the one screen everybody has open all day. So the body is split into SEGMENTS here
 * and Vue interpolates each one as text — an `<a>` gets a real `href` and nothing else about it
 * comes from the message.
 *
 * ## Links
 *
 * The plan says *"links shown as plain links"* — not previews, not cards, not unfurls. A URL
 * becomes an anchor with the URL as its text, `break-all` so a 300-character tracking link
 * wraps instead of widening the thread (which is the accessibility floor's hardest case on this
 * screen), and `rel="noopener noreferrer"` because it opens in a new tab and a message is
 * untrusted input by definition.
 *
 * Only `http:` and `https:` are linked. A `javascript:` or `data:` URL in somebody's message is
 * left as text, where it can do nothing.
 *
 * ## Names
 *
 * `@Name` is marked only when the server recorded a mention of that person on this message —
 * `message_mentions`, which is the record of who was ADDRESSED. Typing "@nobody" marks nothing,
 * so the emphasis means what the notification means. The mark is `font-medium` plus an
 * `sr-only` "mentioned", never a tint alone (DESIGN.md §5.6).
 *
 * ## `onAccent` — why a body needs to know what it is sitting on
 *
 * A DM draws the viewer's own messages in a solid `bg-primary` bubble. A mention inside one is
 * `text-primary`: **brand on brand, 1.00:1, invisible.** So on an accent surface both the
 * mention and the link drop to `text-primary-foreground` (4.99:1 light / 7.31:1 dark, measured)
 * and separate themselves by WEIGHT and UNDERLINE STYLE rather than by hue — a mention is
 * semibold with a dotted underline, a link is a solid underline at normal weight.
 *
 * That is not a compromise forced by the bubble, it is the rule arriving early: §5.6 already
 * says a mention may never be carried by colour alone, and the `sr-only` "mentioned" is there
 * in both cases. The accent variant just makes the sighted reading honest too.
 */

const props = withDefaults(
    defineProps<{
        body: string;
        mentions: MessagePerson[];
        /** This body sits on a `--primary` fill: hue is unavailable, so weight carries. */
        onAccent?: boolean;
    }>(),
    { onAccent: false },
);

/**
 * A link is an underline in both variants; only its colour and weight move.
 *
 * The focus ring has to move too. `ring-ring/50` is `--brand` at 50 %, which over `--primary`
 * composites to 1.19:1 — a ring nobody can see. On accent it becomes an opaque
 * `--primary-foreground` ring, 4.99:1 / 7.31:1. Everywhere else it is untouched.
 */
const linkClass = computed(() =>
    cn(
        'rounded-sm break-all underline underline-offset-2 focus-visible:ring-3 focus-visible:outline-none',
        props.onAccent
            ? 'text-primary-foreground decoration-primary-foreground focus-visible:ring-primary-foreground'
            : 'focus-visible:ring-ring/50',
    ),
);

const mentionClass = computed(() =>
    cn(
        'break-words',
        props.onAccent
            ? 'font-semibold text-primary-foreground underline decoration-dotted underline-offset-2'
            : 'font-medium text-primary',
    ),
);

type Segment =
    | { kind: 'text'; value: string }
    | { kind: 'link'; value: string }
    | { kind: 'mention'; value: string };

const LINK = /https?:\/\/[^\s<]+/giu;

const segments = computed<Segment[]>(() => {
    const names = props.mentions
        .map((person) => (person.name ?? '').trim())
        .filter((name) => name !== '')
        // Longest first, so "@Tapu Ahmed" is one mention rather than "@Tapu" plus " Ahmed".
        .sort((a, b) => b.length - a.length)
        .flatMap((name) => [name, name.split(/\s+/)[0] ?? ''])
        .filter((name, index, all) => name !== '' && all.indexOf(name) === index);

    const escaped = names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const mention = escaped.length === 0 ? null : new RegExp(`@(?:${escaped.join('|')})`, 'giu');

    const out: Segment[] = [];

    // Links first: a URL can contain an `@`, and a mention can never contain a `://`.
    for (const piece of split(props.body, LINK, 'link')) {
        if (piece.kind !== 'text' || mention === null) {
            out.push(piece);

            continue;
        }

        out.push(...split(piece.value, mention, 'mention'));
    }

    return out;
});

function split(value: string, pattern: RegExp, kind: 'link' | 'mention'): Segment[] {
    const out: Segment[] = [];
    const scanner = new RegExp(pattern.source, pattern.flags.includes('g') ? pattern.flags : `${pattern.flags}g`);

    let last = 0;
    let match: RegExpExecArray | null;

    while ((match = scanner.exec(value)) !== null) {
        if (match.index > last) {
            out.push({ kind: 'text', value: value.slice(last, match.index) });
        }

        out.push({ kind, value: match[0] });
        last = match.index + match[0].length;

        // A zero-length match would loop forever; no pattern here can produce one, and this is
        // what makes that true rather than assumed.
        if (match[0] === '') {
            scanner.lastIndex += 1;
        }
    }

    if (last < value.length) {
        out.push({ kind: 'text', value: value.slice(last) });
    }

    return out;
}
</script>

<template>
    <!--
        `whitespace-pre-line` keeps the line breaks somebody typed; `break-words` wraps a long
        word; `min-w-0` is what stops the whole thread growing a horizontal scrollbar when one
        of them is 300 characters of URL.
    -->
    <p class="min-w-0 text-sm break-words whitespace-pre-line">
        <template v-for="(segment, index) in segments" :key="index">
            <a
                v-if="segment.kind === 'link'"
                :href="segment.value"
                target="_blank"
                rel="noopener noreferrer"
                :class="linkClass"
            >{{ segment.value }}<span class="sr-only"> (opens in a new tab)</span></a>

            <strong
                v-else-if="segment.kind === 'mention'"
                :class="mentionClass"
            >{{ segment.value }}<span class="sr-only"> (mentioned)</span></strong>

            <template v-else>{{ segment.value }}</template>
        </template>
    </p>
</template>
