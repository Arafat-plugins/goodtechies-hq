<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ReportCellValue, ReportFormat } from '@/Components/Reports/reports';
import { formatReportValue, reportStatusKey } from '@/Components/Reports/reports';
import StatusBadge, { labelFor } from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

/**
 * One cell of one report — the whole of what the renderer knows about what it is showing.
 *
 * Seven formats and no report keys: this component cannot tell a salary from a task count,
 * which is exactly the property that lets sixteen reports share one screen. A `switch` on a
 * report key here would be the failure the contract was written to prevent.
 *
 * `tabular-nums` on everything that is a figure — DESIGN.md §1.8: a column of numbers lines
 * up only when every one of them is tabular, and a report is mostly columns of numbers.
 */
const props = defineProps<{
    value: ReportCellValue;
    format: ReportFormat;
    /** `settings.currency`. Only `money` reads it. */
    currency: string;
    /** The column's `labels` map. Only `status` reads it. */
    labels?: Record<string, string>;
    /**
     * The server's href for this cell, when the column is a linked one.
     *
     * Built by the builder, which already knows the scope the row came from — nothing here
     * assembles a URL out of an id, so a cell cannot link somewhere the reader may not go.
     */
    href?: string | null;
}>();

/**
 * A link only when there is somewhere to go AND something to say.
 *
 * An empty cell stays an em-dash rather than becoming a link with a dash for its text: a
 * control whose accessible name is a punctuation mark is a control nobody can use.
 */
const linked = computed(() => Boolean(props.href) && !isEmpty.value);

/** Empty is a dash, in every format, the way `DataTable` renders a missing value. */
const isEmpty = computed(() => props.value === null || props.value === undefined || props.value === '');

/** Non-null only when the value really is one of the eight tones — see `reportStatusKey`. */
const tone = computed(() => (props.format === 'status' ? reportStatusKey(props.value) : null));

/**
 * What the badge says.
 *
 * The server's word first, because the server is where the app's vocabulary lives; the badge's
 * own default only when a column sent none. This component still knows nothing about which
 * report it is in — it reads a map it was handed, the same way it reads a currency symbol.
 */
const toneLabel = computed(() => (tone.value === null ? '' : (props.labels?.[tone.value] ?? labelFor(tone.value))));

const text = computed(() => formatReportValue(props.value, props.format, props.currency));

const NUMERIC: ReportFormat[] = ['number', 'money', 'minutes', 'percent', 'date'];

const numeric = computed(() => NUMERIC.includes(props.format));
</script>

<template>
    <!--
        A status is a badge, which is a tint AND its word — never the tint alone. The word is
        the column's own, falling back to the badge's default: see `toneLabel`.
    -->
    <StatusBadge v-if="tone" :status="tone" :label="toneLabel" size="sm" />

    <span v-else-if="isEmpty" class="text-muted-foreground">—</span>

    <!--
        A linked cell is a real link: the underline is the second encoding of the colour, so it
        is still a link in greyscale and still a link to somebody who cannot see the accent
        (DESIGN.md §5.6). It carries no extra words — the row around it is the context, and
        "Buffalo Modular — SEO, opens the project" read out on every row of forty is noise.
    -->
    <Link
        v-else-if="linked"
        :href="href as string"
        :class="
            cn(
                'rounded-sm underline underline-offset-4 decoration-muted-foreground/60',
                'hover:decoration-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                numeric ? 'tabular-nums' : undefined,
            )
        "
    >
        {{ text }}
    </Link>

    <span v-else :class="numeric ? 'tabular-nums' : undefined">{{ text }}</span>
</template>
