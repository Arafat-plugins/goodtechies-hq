<script lang="ts">
/** How a filter's value is picked, and how it lands in the query string. */
export type FilterKind = 'select' | 'multi-select' | 'date-range';

export interface FilterOption {
    value: string;
    label: string;
}

/**
 * One filter a screen offers.
 *
 * `key` is the query parameter the controller reads. A `date-range` filter uses two —
 * `<key>_from` and `<key>_to` — and a `multi-select` comma-joins its values into one.
 */
export interface FilterDef {
    key: string;
    label: string;
    kind: FilterKind;
    /** Required for `select` and `multi-select`. */
    options?: FilterOption[];
    /** Placeholder for the value picker's search box. */
    searchPlaceholder?: string;
}
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft, Filter, Plus, Search, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FilterChip from '@/Components/FilterChip.vue';
import { Button } from '@/Components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/Components/ui/command';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { pushQuery, queryOf, resetQuery } from '@/lib/tableState';
import { cn } from '@/lib/utils';

/**
 * Search, then one removable chip per active filter.
 *
 * **Chip mode** switches on as soon as `filters` is given: the bar then reads its state
 * from the query string and writes every change back to it, so a filtered list is a URL
 * somebody can paste to a colleague. Without `filters` it stays the plain search-plus-slot
 * bar the earlier screens use, and the page keeps owning navigation.
 */
const props = withDefaults(
    defineProps<{
        /** The search term the server echoed back. */
        search?: string | null;
        /** Plain mode only: true when the page considers a filter set. */
        active?: boolean;
        placeholder?: string;
        inputId?: string;
        /** The filters offered as chips. Passing any of them turns chip mode on. */
        filters?: FilterDef[];
        /** Chip mode: true when a control in `#extra` is set, so "Clear all" appears. */
        extraActive?: boolean;
    }>(),
    {
        search: null,
        active: false,
        placeholder: 'Search…',
        inputId: 'filter-bar-search',
        extraActive: false,
    },
);

const emit = defineEmits<{
    /** Fires 300 ms after the last keystroke. */
    update: [search: string];
    /** Chip mode clears the query string itself before this fires. */
    clear: [];
}>();

const chipMode = computed(() => (props.filters?.length ?? 0) > 0);
const defs = computed<FilterDef[]>(() => props.filters ?? []);

/* ------------------------------------------------------------------ search */

const term = ref(props.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

function cancel(): void {
    if (timer !== undefined) {
        clearTimeout(timer);
        timer = undefined;
    }
}

// Re-sync when the server sends a different term back (a Clear, or the back button).
watch(
    () => props.search,
    (value) => {
        const next = value ?? '';

        if (next !== term.value) {
            cancel();
            term.value = next;
        }
    },
);

watch(term, (value) => {
    cancel();

    if (value === (props.search ?? '')) {
        return;
    }

    timer = setTimeout(() => {
        emit('update', value);

        if (chipMode.value) {
            pushQuery({ search: value });
        }
    }, 300);
});

onBeforeUnmount(cancel);

/* ------------------------------------------------------- state from the URL */

const page = usePage();
const query = computed(() => queryOf(page.url));

function rawValue(def: FilterDef): string[] {
    if (def.kind === 'date-range') {
        return [query.value[`${def.key}_from`] ?? '', query.value[`${def.key}_to`] ?? ''];
    }

    const raw = query.value[def.key];

    if (raw === undefined || raw === '') {
        return [];
    }

    return def.kind === 'multi-select' ? raw.split(',').filter(Boolean) : [raw];
}

function isSet(def: FilterDef): boolean {
    return rawValue(def).some((entry) => entry !== '');
}

function labelForOption(def: FilterDef, value: string): string {
    return def.options?.find((option) => option.value === value)?.label ?? value;
}

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });

function readableDate(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

/** What the chip shows after the label. */
function chipValue(def: FilterDef): string {
    const values = rawValue(def);

    if (def.kind === 'date-range') {
        const [from, to] = values;

        if (from && to) {
            return `${readableDate(from)} – ${readableDate(to)}`;
        }

        return from ? `from ${readableDate(from)}` : `until ${readableDate(to ?? '')}`;
    }

    if (values.length > 2) {
        return `${values.length} selected`;
    }

    return values.map((value) => labelForOption(def, value)).join(', ');
}

const activeDefs = computed(() => defs.value.filter(isSet));

const anyActive = computed(() => {
    if (!chipMode.value) {
        return props.active;
    }

    return activeDefs.value.length > 0 || Boolean(props.search) || props.extraActive;
});

/* ------------------------------------------------------------ writing back */

function setValue(def: FilterDef, values: string[]): void {
    if (def.kind === 'date-range') {
        pushQuery({ [`${def.key}_from`]: values[0] ?? null, [`${def.key}_to`]: values[1] ?? null });

        return;
    }

    pushQuery({ [def.key]: values.filter(Boolean).join(',') || null });
}

function remove(def: FilterDef): void {
    setValue(def, []);
}

function choose(def: FilterDef, value: string): void {
    if (def.kind === 'multi-select') {
        const current = rawValue(def);

        setValue(def, current.includes(value) ? current.filter((entry) => entry !== value) : [...current, value]);

        return;
    }

    setValue(def, [value]);
    close();
}

function setRangeEnd(def: FilterDef, index: 0 | 1, value: string): void {
    const next = rawValue(def);

    next[index] = value;
    setValue(def, next);
}

function clearAll(): void {
    if (chipMode.value) {
        resetQuery();
    }

    cancel();
    term.value = '';
    emit('clear');
}

/* ----------------------------------------------------------------- picker */

const open = ref(false);
/** null = the list of filters; otherwise the filter whose values are being picked. */
const pickerKey = ref<string | null>(null);

const picker = computed<FilterDef | null>(() => defs.value.find((def) => def.key === pickerKey.value) ?? null);

function openPicker(key: string | null): void {
    pickerKey.value = key;
    open.value = true;
}

function close(): void {
    open.value = false;
}

// Start back at the filter list the next time the popover opens.
watch(open, (value) => {
    if (!value) {
        pickerKey.value = null;
    }
});

function isChosen(def: FilterDef, value: string): boolean {
    return rawValue(def).includes(value);
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="relative w-full sm:w-64">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    :id="inputId"
                    v-model="term"
                    type="search"
                    :placeholder="placeholder"
                    :aria-label="placeholder"
                    class="pl-9"
                />
            </div>

            <div v-if="$slots.extra || $slots.default" class="flex flex-wrap items-center gap-2">
                <slot name="extra">
                    <slot />
                </slot>
            </div>

            <Button
                v-if="!chipMode && anyActive"
                type="button"
                variant="ghost"
                size="sm"
                class="self-start"
                @click="clearAll"
            >
                <X aria-hidden="true" />
                Clear
            </Button>
        </div>

        <div v-if="chipMode" class="flex min-w-0 flex-wrap items-center gap-2">
            <FilterChip
                v-for="def in activeDefs"
                :key="def.key"
                :label="def.label"
                :value="chipValue(def)"
                @edit="openPicker(def.key)"
                @remove="remove(def)"
            />

            <Popover v-model:open="open">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8 border-dashed"
                        @click="pickerKey = null"
                    >
                        <Plus aria-hidden="true" />
                        Add filter
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-64 p-0">
                    <!-- Level 1: which filter. -->
                    <Command v-if="picker === null">
                        <CommandList>
                            <CommandGroup heading="Filter by">
                                <CommandItem
                                    v-for="def in defs"
                                    :key="def.key"
                                    :value="def.key"
                                    class="justify-between"
                                    @select="openPicker(def.key)"
                                >
                                    <span class="flex items-center gap-2">
                                        <Filter class="size-3.5 text-muted-foreground" aria-hidden="true" />
                                        {{ def.label }}
                                    </span>
                                    <span v-if="isSet(def)" class="truncate text-xs text-muted-foreground">
                                        {{ chipValue(def) }}
                                    </span>
                                </CommandItem>
                            </CommandGroup>
                        </CommandList>
                    </Command>

                    <!-- Level 2: which value. -->
                    <div v-else class="flex flex-col">
                        <div class="flex items-center gap-1 border-b px-2 py-1.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Back to the filter list"
                                @click="pickerKey = null"
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Button>
                            <p class="text-sm font-medium">{{ picker.label }}</p>
                        </div>

                        <div v-if="picker.kind === 'date-range'" class="flex flex-col gap-3 p-3">
                            <div class="flex flex-col gap-1.5">
                                <Label :for="`${inputId}-${picker.key}-from`" class="text-xs">From</Label>
                                <Input
                                    :id="`${inputId}-${picker.key}-from`"
                                    type="date"
                                    :model-value="rawValue(picker)[0] ?? ''"
                                    @update:model-value="
                                        (value) => picker && setRangeEnd(picker, 0, String(value ?? ''))
                                    "
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <Label :for="`${inputId}-${picker.key}-to`" class="text-xs">To</Label>
                                <Input
                                    :id="`${inputId}-${picker.key}-to`"
                                    type="date"
                                    :model-value="rawValue(picker)[1] ?? ''"
                                    @update:model-value="
                                        (value) => picker && setRangeEnd(picker, 1, String(value ?? ''))
                                    "
                                />
                            </div>
                        </div>

                        <Command v-else>
                            <CommandInput :placeholder="picker.searchPlaceholder ?? `Search ${picker.label}…`" />
                            <CommandList>
                                <CommandEmpty>Nothing matches.</CommandEmpty>
                                <CommandGroup>
                                    <CommandItem
                                        v-for="option in picker.options ?? []"
                                        :key="option.value"
                                        :value="option.value"
                                        class="justify-between"
                                        @select="picker && choose(picker, option.value)"
                                    >
                                        <span class="truncate">{{ option.label }}</span>
                                        <span
                                            :class="
                                                cn(
                                                    'size-4 shrink-0 rounded-[4px] border',
                                                    isChosen(picker, option.value)
                                                        ? 'border-primary bg-primary'
                                                        : 'border-input',
                                                )
                                            "
                                            aria-hidden="true"
                                        />
                                    </CommandItem>
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    </div>
                </PopoverContent>
            </Popover>

            <Button v-if="anyActive" type="button" variant="ghost" size="sm" class="h-8" @click="clearAll">
                <X aria-hidden="true" />
                Clear all
            </Button>
        </div>
    </div>
</template>
