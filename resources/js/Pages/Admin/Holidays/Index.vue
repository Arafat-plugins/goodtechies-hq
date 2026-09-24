<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { PartyPopper, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, useId } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import type { Holiday } from '@/Components/Holidays/holidays';
import {
    formatHolidayDate,
    holidayDeletePrompt,
    holidayRoutes,
    holidayWhen,
} from '@/Components/Holidays/holidays';
import HolidayFormDialog from '@/Components/Holidays/HolidayFormDialog.vue';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { DropdownMenuItem } from '@/Components/ui/dropdown-menu';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Every page needs one. Two shipped last phase without it and rendered bare — no sidebar, no
 * top bar, no skip link — and every accessibility measurement on them passed, because a page
 * with no shell has nothing to overflow (decision 4-26).
 */
defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → Leave → Holidays (Part D §9).
 *
 * **This is the screen the client types their own calendar into.** `HolidaySeeder` puts the
 * Bangladesh public-holiday list for the current year in as a starting point, and roughly two
 * thirds of those dates are lunar or lunisolar estimates rather than gazetted dates — so the
 * list arrives wrong in places by construction. The page says that out loud above the table,
 * because an Admin who does not know it has no reason to check a single row.
 *
 * ## What a row here does
 *
 * A holiday is **derived at read time, never stamped onto an attendance row** (decision 4-9,
 * the same rule Off Day and Remote follow). So adding one changes what every employee's
 * attendance reads for that day — including a day already past — and removing one changes it
 * back. The header copy and the dialog both say so, because it is the opposite of what a
 * calendar screen usually implies.
 *
 * ## One year at a time
 *
 * The year is `?year=` and a server round trip, not a client filter: the list is the server's
 * answer to "which holidays are in this year", and a filter here would be a second statement of
 * a range `HolidayService` already owns. The switcher offers every year that has rows plus the
 * current one, which is always offered even when it is empty — the following January there is
 * no seeded list at all and the empty state is where an Admin is told to type the gazette in.
 *
 * Permissions are per record and the server's (`HolidayResource.permissions`), never derived
 * here from a role (decisions 2-28, 2-31). `canManage` decides whether there is an Add control
 * at all.
 */
const props = defineProps<{
    year: number;
    years: number[];
    currentYear: number;
    holidays: Holiday[];
    canManage: boolean;
}>();

const yearSelectId = useId();

/**
 * The table's columns, as data.
 *
 * Nothing is `sortable`: the controller orders by date and reads no `sort` parameter, and a
 * header that pushed one the server ignores would be a lie in the UI (decisions 0.5-16,
 * 0.5-19). The list is a calendar year — about twenty rows — so there is no paging either.
 */
const columns = computed<ColumnDef<Holiday>[]>(() => [
    { key: 'date', header: 'Date', nowrap: true, hideable: false },
    { key: 'weekday', header: 'Day', nowrap: true },
    { key: 'name', header: 'Holiday', hideable: false },
    { key: 'when', header: 'When', nowrap: true, value: (row) => holidayWhen(row) },
    { key: 'actions', header: 'Actions', cell: 'actions', headerHidden: true, hideable: false },
]);

const yearOptions = computed(() => props.years.map((year) => String(year)));

function changeYear(value: unknown): void {
    const year = Number(value);

    if (!Number.isFinite(year) || year === props.year) {
        return;
    }

    router.get(holidayRoutes.index(year), {}, { preserveState: false, preserveScroll: true });
}

/* ------------------------------------------------------------------- add / edit */

const dialogOpen = ref(false);
const editing = ref<Holiday | null>(null);
/** Today, so a new holiday in the current year defaults to today rather than to 1 January. */
const today = computed(() => new Date().toISOString().slice(0, 10));

function add(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function edit(holiday: Holiday): void {
    // Deferred for the reason `askRemove` is: the `⋯` menu dismisses on select and restores
    // focus to its trigger, and opening a dialog in the same tick races that.
    setTimeout(() => {
        editing.value = holiday;
        dialogOpen.value = true;
    }, 0);
}

/* -------------------------------------------------------------------- removing */

/**
 * Which holiday is asking to be removed.
 *
 * A dialog rather than a strip inside the row, because `DataTable` renders `#row-actions`
 * inside the `⋯` **dropdown menu**: a confirmation drawn there would live in a popover the
 * first click closes, in a box a few characters wide. The menu closes and the dialog opens,
 * which is `Admin/Projects/Index.vue`'s archive pattern — and it is where the `setTimeout(…, 0)`
 * below comes from too. reka dismisses the menu on select and restores focus to the `⋯` trigger;
 * opening the dialog in the same tick would race that and leave the focus trap holding an
 * element the menu is about to unmount. Deferring by a tick lets the menu finish first.
 *
 * **The question names the holiday and its date.** Twenty-one rows, several of them called
 * "Eid ul-Adha holiday", is a list where "Are you sure?" is not a question anybody can answer.
 */
const pending = ref<Holiday | null>(null);
const removing = ref(false);

function askRemove(holiday: Holiday): void {
    setTimeout(() => {
        pending.value = holiday;
    }, 0);
}

function confirmRemove(): void {
    const holiday = pending.value;

    if (!holiday || removing.value) {
        return;
    }

    removing.value = true;

    router.delete(holidayRoutes.destroy(holiday.id), {
        preserveScroll: true,
        onFinish: () => {
            removing.value = false;
            pending.value = null;
            // The row and its ⋯ button are gone, so there is nothing to give focus back to:
            // the page's own Add control is the nearest thing that still exists.
            returnFocus(null);
        },
    });
}

/** The id the Add control carries so `returnFocus` can find it without a template ref. */
const ADD_BUTTON_ID = 'holidays-add';

/**
 * Put focus back where the overlay was opened from.
 *
 * reka's Dialog restores focus to whatever held it when the dialog opened — which here was a
 * menu item that the `⋯` menu has already unmounted, so the restore lands on `<body>` and a
 * keyboard user is dropped at the top of the document. (The same gap is in
 * `Admin/Projects/Index.vue`'s archive confirm; it is the shape of the pattern, not of this
 * screen.) So the row's `⋯` trigger is found by the accessible name `DataTable` gave it and
 * focused explicitly — the VISIBLE one, because the table and the card list each render one
 * and only one of the two is on screen at a given width.
 */
function returnFocus(holiday: Holiday | null): void {
    void nextTick(() => {
        const label = holiday
            ? `Actions for ${holiday.name} on ${formatHolidayDate(holiday.date)}`
            : null;

        const trigger = label
            ? [...document.querySelectorAll<HTMLElement>('button[aria-label^="Actions for "]')].find(
                  (el) => el.getAttribute('aria-label') === label && el.offsetParent !== null,
              )
            : undefined;

        (trigger ?? document.getElementById(ADD_BUTTON_ID))?.focus();
    });
}

/** How many of the listed rows are still ahead. Zero is an answer, and the header prints it. */
const stillToCome = computed(() => props.holidays.filter((holiday) => !holiday.is_past).length);

const summary = computed(() => {
    const total = props.holidays.length;

    if (total === 0) {
        return `No holidays are on the calendar for ${props.year}.`;
    }

    const noun = total === 1 ? 'holiday' : 'holidays';

    if (props.year < props.currentYear) {
        return `${total} ${noun} in ${props.year}.`;
    }

    return `${total} ${noun} in ${props.year}, ${stillToCome.value} still to come.`;
});
</script>

<template>
    <Head title="Holidays" />

    <PageShell
        title="Holidays"
        description="The company calendar. A holiday is a day nobody is expected in: nobody is marked absent on it, and every employee's attendance reads Holiday — unless it is already their day off."
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Leave' }, { label: 'Holidays' }]"
    >
        <template #actions>
            <Button v-if="canManage" :id="ADD_BUTTON_ID" @click="add">
                <Plus aria-hidden="true" />
                Add holiday
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!--
                The year switcher and the count. A server round trip, not a client filter —
                see the script. The label is visible rather than an `aria-label`, because this
                control changes the whole page and a bare select with a year in it says nothing
                about what it does.
            -->
            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="yearSelectId">Year</Label>
                    <Select
                        :id="yearSelectId"
                        :model-value="String(year)"
                        @update:model-value="changeYear($event)"
                    >
                        <SelectTrigger :id="yearSelectId" class="w-full sm:w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in yearOptions" :key="option" :value="option">
                                {{ option }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <p class="text-sm text-muted-foreground">{{ summary }}</p>
            </div>

            <!--
                The warning that makes this screen worth opening. Most of the seeded dates are
                estimates, and an Admin who does not know that has no reason to check a row.
                It is text, not a tinted banner with no words — colour is never the only
                carrier (DESIGN.md §6 rule 6).
            -->
            <p v-if="holidays.length > 0" class="text-xs text-muted-foreground">
                Bangladesh's Eid, Shab e-Barat, Ashura, Janmashtami, Durga Puja and Buddha Purnima dates are
                lunar or lunisolar: the seeded list is an estimate for those, not a government date. Check
                them against the gazette and correct them here — the change reaches every employee's
                attendance, including days already past.
            </p>

            <DataTable
                id="admin-holidays"
                :columns="columns"
                :rows="holidays"
                :row-label="(row) => `${row.name} on ${formatHolidayDate(row.date)}`"
                noun="holiday"
                :empty-icon="PartyPopper"
                :empty-title="`No holidays for ${year} yet`"
                empty-description="Bangladesh gazettes its holiday list one year at a time, so a year only has holidays once somebody enters them. Add them here and every employee's calendar and attendance follow."
            >
                <template #empty-action>
                    <Button v-if="canManage" @click="add">
                        <Plus aria-hidden="true" />
                        Add holiday
                    </Button>
                </template>

                <template #cell-date="{ row }">
                    <span class="tabular-nums">{{ formatHolidayDate(row.date) }}</span>
                </template>

                <template #cell-when="{ row }">
                    <span :class="row.is_past ? 'text-muted-foreground' : ''">{{ holidayWhen(row) }}</span>
                </template>

                <!--
                    Two menu items, the same shape every other admin list uses. Both are drawn
                    from the row's OWN `permissions` block, which the server resolved per record
                    — never from a role compared here (decisions 2-28, 2-31).
                -->
                <template #row-actions="{ row }">
                    <DropdownMenuItem v-if="row.permissions.can_update" @select="edit(row)">
                        <Pencil aria-hidden="true" />
                        Edit holiday
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="row.permissions.can_delete"
                        variant="destructive"
                        @select="askRemove(row)"
                    >
                        <Trash2 aria-hidden="true" />
                        Remove holiday
                    </DropdownMenuItem>
                </template>
            </DataTable>
        </div>

        <HolidayFormDialog
            v-model:open="dialogOpen"
            :holiday="editing"
            :year="year"
            :today="today"
        />

        <!--
            The confirmation. It NAMES the holiday and its date in the title — the whole reason
            it exists, on a list where several rows are called "Eid ul-Adha holiday" — and it
            says in words what removing it does to days that have already happened, because a
            holiday is derived at read time and that is not what a calendar screen usually
            implies.
        -->
        <Dialog
            :open="pending !== null"
            @update:open="(open: boolean) => { if (!open) { const row = pending; pending = null; returnFocus(row); } }"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ pending ? holidayDeletePrompt(pending) : 'Remove holiday?' }}</DialogTitle>
                    <DialogDescription>
                        That day goes back to following each employee's own work schedule — on every month
                        already shown as well as on the ones to come — and the absent sweep starts marking
                        it again. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="removing"
                        @click="() => { const row = pending; pending = null; returnFocus(row); }"
                    >
                        Keep it
                    </Button>
                    <Button type="button" variant="destructive" :disabled="removing" @click="confirmRemove">
                        <Trash2 aria-hidden="true" />
                        {{ removing ? 'Removing…' : 'Remove holiday' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageShell>
</template>
