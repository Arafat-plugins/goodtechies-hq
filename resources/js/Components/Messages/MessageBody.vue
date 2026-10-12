<script setup lang="ts">
import { computed } from 'vue';
import type { MessagePerson } from '@/Components/Messages/messages';
import MessageInline, { type RenderNode } from '@/Components/Messages/MessageInline.vue';
import { type Block, type InlineNode, parseMessage } from '@/Components/Messages/markdown';
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
 * A DM draws the viewer's own messages in a solid `bg-bubble-own` bubble (bubble-own, 12-77).
 * A brand-coloured mention on that grey says nothing by hue. So on an accent surface both the
 * mention and the link use `text-bubble-own-foreground` (~9:1, bubble-own, 12-77)
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
        /** This body sits on the `--bubble-own` fill (bubble-own, 12-77): weight carries, not hue. */
        onAccent?: boolean;
    }>(),
    { onAccent: false },
);

/**
 * A link is an underline in both variants; only its colour and weight move.
 *
 * The focus ring has to move too: on the own bubble the ring becomes
 * `--bubble-own-foreground` (~9:1, bubble-own, 12-77). Everywhere else it is the app's own `--ring`.
 */
const linkClass = computed(() =>
    cn(
        'rounded-sm break-all underline underline-offset-2 focus-visible:ring-3 focus-visible:outline-none',
        props.onAccent
            ? 'text-bubble-own-foreground decoration-bubble-own-foreground focus-visible:ring-bubble-own-foreground'
            : 'focus-visible:ring-ring',
    ),
);

const mentionClass = computed(() =>
    cn(
        'break-words',
        props.onAccent
            ? 'font-semibold text-bubble-own-foreground underline decoration-dotted underline-offset-2'
            : 'font-medium text-primary',
    ),
);

type Segment =
    | { kind: 'text'; value: string }
    | { kind: 'link'; value: string }
    | { kind: 'mention'; value: string };

const LINK = /https?:\/\/[^\s<]+/giu;

/** The mention pattern for this message's recorded mentions, or null. */
const mention = computed<RegExp | null>(() => {
    const names = props.mentions
        .map((person) => (person.name ?? '').trim())
        .filter((name) => name !== '')
        // Longest first, so "@Tapu Ahmed" is one mention rather than "@Tapu" plus " Ahmed".
        .sort((a, b) => b.length - a.length)
        .flatMap((name) => [name, name.split(/\s+/)[0] ?? ''])
        .filter((name, index, all) => name !== '' && all.indexOf(name) === index);

    const escaped = names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

    return escaped.length === 0 ? null : new RegExp(`@(?:${escaped.join('|')})`, 'giu');
});

/** Plain words → text, links and mentions. Links first: a URL can contain an `@`. */
function leaves(value: string): Segment[] {
    const out: Segment[] = [];

    for (const piece of split(value, LINK, 'link')) {
        if (piece.kind !== 'text' || mention.value === null) {
            out.push(piece);

            continue;
        }

        out.push(...split(piece.value, mention.value, 'mention'));
    }

    return out;
}

/**
 * Polish 047: Discord-style formatting (markdown.ts). Formatting marks become elements; the
 * words inside them still get their links and mentions. Code is drawn exactly as typed.
 */
function render(nodes: InlineNode[]): RenderNode[] {
    return nodes.flatMap<RenderNode>((node) => {
        if (node.kind === 'text') {
            return leaves(node.value);
        }

        if (node.kind === 'code') {
            return [{ kind: 'code', value: node.value }];
        }

        return [{ kind: node.kind, children: render(node.children) }];
    });
}

interface RenderBlock {
    kind: 'paragraph' | 'heading' | 'quote' | 'code';
    level?: 1 | 2 | 3;
    nodes?: RenderNode[];
    blocks?: RenderBlock[];
    value?: string;
}

function renderBlocks(blocks: Block[]): RenderBlock[] {
    return blocks.map((block) => {
        switch (block.kind) {
            case 'code':
                return { kind: 'code', value: block.value };
            case 'quote':
                return { kind: 'quote', blocks: renderBlocks(block.blocks) };
            case 'heading':
                return { kind: 'heading', level: block.level, nodes: render(block.inline) };
            default:
                return { kind: 'paragraph', nodes: render(block.inline) };
        }
    });
}

const blocks = computed(() => renderBlocks(parseMessage(props.body)));

/** One paragraph and nothing else draws as before — one `<p>` that can sit inline with the time. */
const single = computed(() => (blocks.value.length === 1 && blocks.value[0].kind === 'paragraph' ? blocks.value[0] : null));

const HEADING_CLASS: Record<1 | 2 | 3, string> = {
    1: 'text-xl font-bold',
    2: 'text-lg font-bold',
    3: 'text-base font-semibold',
};

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
    <p v-if="single" class="min-w-0 text-sm break-words whitespace-pre-line">
        <MessageInline :nodes="single.nodes ?? []" :link-class="linkClass" :mention-class="mentionClass" />
    </p>

    <!-- Polish 047: headings, quotes and code blocks, one element each. -->
    <div v-else class="flex min-w-0 flex-col gap-1 text-sm break-words">
        <template v-for="(block, index) in blocks" :key="index">
            <pre
                v-if="block.kind === 'code'"
                class="max-w-full overflow-x-auto rounded-md border bg-background px-3 py-2 font-mono text-xs whitespace-pre text-foreground"
            ><code>{{ block.value }}</code></pre>

            <p
                v-else-if="block.kind === 'heading'"
                role="heading"
                :aria-level="(block.level ?? 3) + 2"
                :class="cn('whitespace-pre-line', HEADING_CLASS[block.level ?? 3])"
            >
                <MessageInline :nodes="block.nodes ?? []" :link-class="linkClass" :mention-class="mentionClass" />
            </p>

            <blockquote
                v-else-if="block.kind === 'quote'"
                class="flex min-w-0 flex-col gap-1 border-l-4 border-muted-foreground/40 pl-3"
            >
                <template v-for="(inner, innerIndex) in block.blocks" :key="innerIndex">
                    <pre
                        v-if="inner.kind === 'code'"
                        class="max-w-full overflow-x-auto rounded-md border bg-background px-3 py-2 font-mono text-xs whitespace-pre text-foreground"
                    ><code>{{ inner.value }}</code></pre>
                    <p
                        v-else
                        :role="inner.kind === 'heading' ? 'heading' : undefined"
                        :aria-level="inner.kind === 'heading' ? (inner.level ?? 3) + 2 : undefined"
                        :class="cn('whitespace-pre-line', inner.kind === 'heading' && HEADING_CLASS[inner.level ?? 3])"
                    >
                        <MessageInline :nodes="inner.nodes ?? []" :link-class="linkClass" :mention-class="mentionClass" />
                    </p>
                </template>
            </blockquote>

            <p v-else class="whitespace-pre-line">
                <MessageInline :nodes="block.nodes ?? []" :link-class="linkClass" :mention-class="mentionClass" />
            </p>
        </template>
    </div>
</template>
