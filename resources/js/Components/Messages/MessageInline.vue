<script lang="ts">
/**
 * Polish 047: one line of a message with its formatting — bold, italic, underline, strike,
 * spoiler, inline code — drawn as Vue elements (never HTML), recursively for nested styles.
 * Words arrive already split into text, links and mentions by MessageBody.
 */
export type RenderNode =
    | { kind: 'text'; value: string }
    | { kind: 'link'; value: string }
    | { kind: 'mention'; value: string }
    | { kind: 'code'; value: string }
    | { kind: 'bold' | 'italic' | 'underline' | 'strike' | 'spoiler'; children: RenderNode[] };
</script>

<script setup lang="ts">
import { ref } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ name: 'MessageInline' });

defineProps<{
    nodes: RenderNode[];
    linkClass: string;
    mentionClass: string;
}>();

/** Spoilers this reader has opened, by position. Closed again only by reloading the thread. */
const revealed = ref(new Set<number>());

/** A closed spoiler's words, as plain text — only its size shows, and nothing in it is a link. */
function textOf(nodes: RenderNode[]): string {
    return nodes.map((node) => ('value' in node ? node.value : textOf(node.children))).join('');
}

function reveal(index: number): void {
    revealed.value = new Set(revealed.value).add(index);
}
</script>

<template>
    <template v-for="(node, index) in nodes" :key="index">
        <template v-if="node.kind === 'text'">{{ node.value }}</template>

        <a
            v-else-if="node.kind === 'link'"
            :href="node.value"
            target="_blank"
            rel="noopener noreferrer"
            :class="linkClass"
        >{{ node.value }}<span class="sr-only"> (opens in a new tab)</span></a>

        <strong
            v-else-if="node.kind === 'mention'"
            :class="mentionClass"
        >{{ node.value }}<span class="sr-only"> (mentioned)</span></strong>

        <code
            v-else-if="node.kind === 'code'"
            class="rounded border bg-background px-1 py-0.5 font-mono text-xs break-all text-foreground"
        >{{ node.value }}</code>

        <strong v-else-if="node.kind === 'bold'" class="font-bold"><MessageInline :nodes="node.children" :link-class="linkClass" :mention-class="mentionClass" /></strong>

        <em v-else-if="node.kind === 'italic'" class="italic"><MessageInline :nodes="node.children" :link-class="linkClass" :mention-class="mentionClass" /></em>

        <u v-else-if="node.kind === 'underline'" class="underline underline-offset-2"><MessageInline :nodes="node.children" :link-class="linkClass" :mention-class="mentionClass" /></u>

        <s v-else-if="node.kind === 'strike'" class="line-through"><MessageInline :nodes="node.children" :link-class="linkClass" :mention-class="mentionClass" /></s>

        <!--
            A spoiler is a button until it is opened, so it can be reached and opened from the
            keyboard; its words are kept out of the accessibility tree until then too.
        -->
        <span
            v-else-if="node.kind === 'spoiler' && revealed.has(index)"
            class="rounded-sm bg-foreground/10 px-0.5"
        ><MessageInline :nodes="node.children" :link-class="linkClass" :mention-class="mentionClass" /></span>
        <button
            v-else-if="node.kind === 'spoiler'"
            type="button"
            :class="
                cn(
                    'cursor-pointer rounded-sm bg-foreground px-0.5 text-transparent select-none',
                    'outline-none focus-visible:ring-3 focus-visible:ring-ring',
                )
            "
            data-testid="message-spoiler"
            aria-label="Spoiler, hidden. Press to show it."
            @click.stop="reveal(index)"
        ><span aria-hidden="true">{{ textOf(node.children) }}</span></button>
    </template>
</template>
