<script setup lang="ts">
import { ChevronDown, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AuditDiff from '@/Components/Audit/AuditDiff.vue';
import type { AuditEntry } from '@/Components/Audit/audit';
import { actorName, targetName } from '@/Components/Audit/audit';
import DetailDrawer from '@/Components/DetailDrawer.vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';

/**
 * One audit entry, read beside the list.
 *
 * It is a drawer rather than a page because there is no second route: the list already sent the
 * whole row (the diff included), so opening one costs nothing and closing it puts the reader
 * back where they were in a log they were scanning. `DetailDrawer` writes `?detail=<id>` while
 * it is open, so the entry somebody is reading is an entry they can send — and the controller
 * resolves that parameter on a cold load, because `syncQuery` cannot fetch what it names.
 *
 * **Nothing here writes.** There is no correct, no annotate, no hide: `audit_logs` is
 * append-only at the database and the model refuses `updating` and `deleting` (Part B §3 rule 3).
 * The footer is a close button and that is the whole of the interaction.
 *
 * The stored pair is repeated verbatim at the bottom, behind a closed disclosure. The diff above
 * it is an *interpretation* — a useful one — and an auditor is entitled to the bytes.
 */
const props = defineProps<{
    entry: AuditEntry | null;
    /** Named once, because every timestamp in here is in it. */
    timezone: string;
}>();

const emit = defineEmits<{ close: [] }>();

const open = computed(() => props.entry !== null);

const rawOpen = ref(false);

// A different entry is a different record: the disclosure state belongs to the one being read,
// not to the drawer.
watch(
    () => props.entry?.id ?? null,
    () => {
        rawOpen.value = false;
    },
);

function stored(value: unknown): string {
    if (value === null || value === undefined) {
        return 'null';
    }

    try {
        return JSON.stringify(value, null, 2) ?? String(value);
    } catch {
        return String(value);
    }
}
</script>

<template>
    <DetailDrawer
        :open="open"
        :title="entry?.event_label ?? 'Audit entry'"
        :subtitle="entry === null ? undefined : `${entry.recorded_at ?? ''} · ${timezone}`"
        width="lg"
        :deep-link-id="entry?.id ?? null"
        @update:open="!$event && emit('close')"
    >
        <div v-if="entry" class="flex min-w-0 flex-col gap-5">
            <!--
                An event string this build has no definition for is a fact about the row worth
                saying out loud, not an error: `audit_logs.event` is a plain indexed string with
                no CHECK, so a row written before a rename is still a valid row. The word carries
                it; the icon is decoration.
            -->
            <p
                v-if="!entry.event_known"
                class="flex items-start gap-2 rounded-md border border-status-review-border bg-status-review-bg p-3 text-sm text-status-review-fg"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    This build has no definition for the event <code class="font-mono">{{ entry.event }}</code
                    >, so it is shown as recorded. That normally means the row was written by an earlier version of
                    the application.
                </span>
            </p>

            <dl class="grid min-w-0 gap-3 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Event</dt>
                    <dd class="mt-0.5 min-w-0 text-sm">
                        {{ entry.event_label }}
                        <span class="block font-mono text-xs break-all text-muted-foreground">{{ entry.event }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Area</dt>
                    <dd class="mt-0.5 text-sm">{{ entry.event_group }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Who</dt>
                    <dd class="mt-0.5 min-w-0 text-sm">
                        {{ actorName(entry) }}
                        <span v-if="entry.actor" class="block text-xs break-all text-muted-foreground">
                            {{ entry.actor.email }}
                        </span>
                        <span v-else class="block text-xs text-muted-foreground">
                            No signed-in actor — a command, a queue job or the scheduler.
                        </span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Record</dt>
                    <dd class="mt-0.5 min-w-0 text-sm">
                        {{ targetName(entry) }}
                        <span v-if="entry.target" class="block font-mono text-xs break-all text-muted-foreground">
                            {{ entry.target.type }}
                        </span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Recorded</dt>
                    <dd class="mt-0.5 text-sm">
                        {{ entry.recorded_at ?? 'Unknown' }}
                        <span class="block text-xs text-muted-foreground">{{ timezone }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">From</dt>
                    <dd class="mt-0.5 min-w-0 text-sm">
                        {{ entry.ip ?? 'Not recorded' }}
                        <span v-if="entry.user_agent" class="block text-xs break-words text-muted-foreground">
                            {{ entry.user_agent }}
                        </span>
                    </dd>
                </div>
            </dl>

            <AuditDiff :entry="entry" />

            <Collapsible v-model:open="rawOpen" class="min-w-0">
                <CollapsibleTrigger
                    class="group flex items-center gap-2 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <ChevronDown
                        class="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                        aria-hidden="true"
                    />
                    <span>Values exactly as stored</span>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div class="mt-2 flex min-w-0 flex-col gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">old_value</p>
                            <pre
                                class="mt-1 max-w-full overflow-x-auto rounded-md bg-muted p-2 font-mono text-xs break-words whitespace-pre-wrap"
                                >{{ stored(entry.old_value) }}</pre
                            >
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">new_value</p>
                            <pre
                                class="mt-1 max-w-full overflow-x-auto rounded-md bg-muted p-2 font-mono text-xs break-words whitespace-pre-wrap"
                                >{{ stored(entry.new_value) }}</pre
                            >
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </div>
    </DetailDrawer>
</template>
