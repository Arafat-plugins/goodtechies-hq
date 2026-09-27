<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import AuditValue from '@/Components/Audit/AuditValue.vue';
import type { AuditDiffField, AuditEntry } from '@/Components/Audit/audit';
import { STATE_TONE, STATE_WORD, diffColumns, diffHeadline } from '@/Components/Audit/audit';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';

/**
 * The old/new diff — the reason this screen exists.
 *
 * Two blobs of JSON side by side is not a diff: the reader has to find which of eleven keys
 * moved, and on a row with eleven keys and one change they will find it by reading all eleven
 * twice. So this answers the three questions a reader actually has, in that order:
 *
 *  1. **What kind of change is this?** A sentence, first, before any field. A created record, a
 *     deleted one and an update in which nothing moved all look identical if you only list
 *     fields — see `diffHeadline()`.
 *  2. **Which field moved, from what, to what?** The changed fields, alone, at the top. Each one
 *     names itself, carries the WORD for its state beside the tint, and shows its before and
 *     after under the words *Was* and *Now* — words rather than an arrow, so the block reads the
 *     same stacked on a phone as it does in two columns on a desktop.
 *  3. **What else was on the record?** The unchanged fields, behind one closed disclosure with
 *     its count. They are present, because an auditor may need to see what a record said as a
 *     whole; they are closed, because a field that did not move must not compete for attention
 *     with one that did.
 *
 * The three ways of having no value — never recorded, recorded as null, recorded as an empty
 * string — are three different words, and `AuditValue` is where that is decided.
 */
const props = defineProps<{ entry: AuditEntry }>();

const diff = computed(() => props.entry.diff);

const columns = computed(() => diffColumns(diff.value.kind));

const moved = computed<AuditDiffField[]>(() => diff.value.fields.filter((field) => field.state !== 'unchanged'));
const still = computed<AuditDiffField[]>(() => diff.value.fields.filter((field) => field.state === 'unchanged'));

/** Closed by default. The point of the split is that the unchanged half stays quiet. */
const unchangedOpen = ref(false);

/** What the before column is called depends on whether there is an after column. */
const beforeLabel = computed(() => (columns.value.after ? 'Was' : 'Last recorded as'));
const afterLabel = computed(() => (columns.value.before ? 'Now' : 'Recorded as'));
</script>

<template>
    <section class="flex min-w-0 flex-col gap-3" aria-label="What changed">
        <p class="text-sm text-muted-foreground">{{ diffHeadline(diff) }}</p>

        <!--
            The two kinds with no fields to compare. Both values are shown whole rather than
            summarised: an audit viewer that hid part of a stored value would be the one thing
            this screen may not do.
        -->
        <div v-if="diff.kind === 'opaque'" class="flex min-w-0 flex-col gap-3 sm:flex-row">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Was</p>
                <AuditValue :value="entry.old_value" :present="entry.old_value !== null" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Now</p>
                <AuditValue :value="entry.new_value" :present="entry.new_value !== null" />
            </div>
        </div>

        <ul v-if="moved.length > 0" class="flex min-w-0 flex-col gap-2">
            <li
                v-for="field in moved"
                :key="field.key"
                class="min-w-0 rounded-md border bg-card p-3 shadow-flat"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <span class="text-sm font-medium break-words">{{ field.label }}</span>
                    <StatusBadge :status="STATE_TONE[field.state]" :label="STATE_WORD[field.state]" size="sm" />
                    <!-- The stored key, so an auditor can quote the column and not the label. -->
                    <code class="font-mono text-xs break-all text-muted-foreground">{{ field.key }}</code>
                </div>

                <dl class="mt-2 grid min-w-0 gap-2" :class="columns.before && columns.after ? 'sm:grid-cols-2' : ''">
                    <div v-if="columns.before" class="min-w-0">
                        <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {{ beforeLabel }}
                        </dt>
                        <dd class="mt-0.5 min-w-0">
                            <AuditValue :value="field.old" :present="field.in_old" />
                        </dd>
                    </div>
                    <div v-if="columns.after" class="min-w-0">
                        <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {{ afterLabel }}
                        </dt>
                        <dd class="mt-0.5 min-w-0">
                            <AuditValue :value="field.new" :present="field.in_new" />
                        </dd>
                    </div>
                </dl>
            </li>
        </ul>

        <!--
            reka-ui's collapsible, so the trigger is a real button with aria-expanded and
            aria-controls and Enter/Space work — the same pattern FilePanel's version history
            uses, rather than a second one.
        -->
        <Collapsible v-if="still.length > 0" v-model:open="unchangedOpen" class="min-w-0">
            <CollapsibleTrigger
                class="group flex items-center gap-2 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
            >
                <ChevronDown
                    class="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                    aria-hidden="true"
                />
                <span>
                    {{ still.length }} unchanged
                    {{ still.length === 1 ? 'field' : 'fields' }} on this record
                </span>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <dl class="mt-2 min-w-0 divide-y border-l pl-3">
                    <div
                        v-for="field in still"
                        :key="field.key"
                        class="flex min-w-0 flex-col gap-0.5 py-2 sm:flex-row sm:items-baseline sm:gap-3"
                    >
                        <dt class="text-sm text-muted-foreground sm:w-40 sm:shrink-0">{{ field.label }}</dt>
                        <dd class="min-w-0">
                            <AuditValue :value="field.new" :present="field.in_new" />
                        </dd>
                    </div>
                </dl>
            </CollapsibleContent>
        </Collapsible>
    </section>
</template>
