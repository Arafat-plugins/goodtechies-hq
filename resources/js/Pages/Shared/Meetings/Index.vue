<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, CalendarPlus, Video } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MeetingCalendarNav from '@/Components/Meetings/MeetingCalendarNav.vue';
import MeetingFilterChips from '@/Components/Meetings/MeetingFilterChips.vue';
import MeetingMonthGrid from '@/Components/Meetings/MeetingMonthGrid.vue';
import MeetingRow from '@/Components/Meetings/MeetingRow.vue';
import MeetingViewSwitcher from '@/Components/Meetings/MeetingViewSwitcher.vue';
import MeetingWeekView from '@/Components/Meetings/MeetingWeekView.vue';
import type { MeetingDay, MeetingFilters, MeetingSection, MeetingView, MeetingWindow } from '@/Components/Meetings/meetings';
import { MEETING_CREATE_HREF } from '@/Components/Meetings/meetings';
import PageShell from '@/Components/PageShell.vue';
import { buttonVariants } from '@/Components/ui/button';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { queryOf } from '@/lib/tableState';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * Meetings: one page, three views, and every piece of state that decides what is on it in the
 * **URL**.
 *
 * `?view=list` · `?view=month&month=2026-09` · `?view=week&week=2026-09-21`, plus `?scope=` and
 * `?when=`. That is what makes a month bookmarkable, makes the back button walk back through the
 * months somebody actually looked at, and lets "the week of the 21st" be pasted into a message.
 * It is the same call the Tasks view switcher makes and for the same reason (DESIGN.md §5.10).
 *
 * **One shared page, not three.** Whose calendar a meeting is on belongs to the person and not
 * to the shell they are in, so `routes/shared.php` carries the endpoints and this picks its
 * layout from `auth.user.surface`, exactly as `Pages/Shared/Messages.vue` does. The Accountant
 * never arrives — the route group is gated on `meetings.use` and they hold none.
 *
 * ## The window is the server's
 *
 * Nothing here decides what to fetch. The month grid draws `window.days` and the week draws its
 * seven; both arrive from `Meeting::overlapping()`, so a month view is a month's worth of rows
 * and not the table. The List is bounded by a cap per bucket and says when it has hit one.
 *
 * ## Two empty states, because they are two different sentences
 *
 * *"You have no meetings"* and *"nothing in September"* mean different things and lead to
 * different actions. `has_any` is one EXISTS query and it is what tells them apart: the first
 * offers the create form, the second offers the next month.
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

const props = defineProps<{
    view: MeetingView;
    filters: MeetingFilters;
    scopeOffered: boolean;
    window: MeetingWindow | null;
    days: MeetingDay[];
    sections: MeetingSection[];
    hasAny: boolean;
    permissions: { can_create: boolean };
}>();

/**
 * The query string as Inertia currently has it — read from `page.url` rather than
 * `window.location`, so the hrefs are recomputed after a filter link navigates and a switcher
 * can never still be pointing at the filter set somebody just left.
 */
const page = usePage();

const query = computed(() => queryOf(page.url));

/** What a calendar link has to keep hold of: the scope, and nothing that belongs to a view. */
const carry = computed<Record<string, string>>(() => {
    const kept: Record<string, string> = {};

    if (props.filters.scope === 'all') {
        kept.scope = 'all';
    }

    return kept;
});

const hasSomethingInWindow = computed(() => props.days.some((day) => day.meetings.length > 0));

const hasSomethingInList = computed(() => props.sections.some((section) => section.days.length > 0));

const description = computed(() =>
    props.filters.scope === 'all'
        ? 'Every meeting you can see.'
        : 'The meetings you are running or invited to.',
);
</script>

<template>
    <Head title="Meetings" />

    <PageShell title="Meetings" :description="description">
        <template #tabs>
            <MeetingViewSwitcher :current="view" :query="query" />
        </template>

        <template #actions>
            <Link
                v-if="permissions.can_create"
                :href="MEETING_CREATE_HREF"
                :class="cn(buttonVariants({ size: 'sm' }))"
            >
                <CalendarPlus aria-hidden="true" />
                New meeting
            </Link>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <MeetingFilterChips
                :view="view"
                :filters="filters"
                :scope-offered="scopeOffered"
                :query="query"
            />

            <!-- ─────────────────────────────────── nothing, ever ──────────────────────────── -->
            <EmptyState
                v-if="!hasAny"
                :icon="Video"
                title="No meetings yet"
                :description="
                    permissions.can_create
                        ? 'When you book one, it turns up here and on everyone else’s calendar too.'
                        : 'Meetings you are invited to will turn up here.'
                "
            >
                <template v-if="permissions.can_create" #action>
                    <Link :href="MEETING_CREATE_HREF" :class="cn(buttonVariants({ size: 'sm' }))">
                        <CalendarPlus aria-hidden="true" />
                        Schedule a meeting
                    </Link>
                </template>
            </EmptyState>

            <!-- ───────────────────────────────────── the List ─────────────────────────────── -->
            <template v-else-if="view === 'list'">
                <template v-if="hasSomethingInList">
                    <section
                        v-for="section in sections"
                        :key="section.key"
                        class="flex min-w-0 flex-col gap-3"
                    >
                        <h2 class="text-sm font-medium">{{ section.label }}</h2>

                        <p v-if="section.days.length === 0" class="text-sm text-muted-foreground">
                            {{ section.key === 'upcoming' ? 'Nothing coming up.' : 'Nothing has been held yet.' }}
                        </p>

                        <div v-for="day in section.days" :key="day.date" class="flex min-w-0 flex-col gap-2">
                            <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {{ day.label }}
                            </h3>
                            <ul class="flex min-w-0 flex-col gap-2">
                                <MeetingRow
                                    v-for="meeting in day.meetings"
                                    :key="meeting.id"
                                    :meeting="meeting"
                                />
                            </ul>
                        </div>

                        <p v-if="section.has_more" class="text-xs text-muted-foreground">
                            Only the fifty nearest are shown. Use the month or week view to reach the rest.
                        </p>
                    </section>
                </template>

                <EmptyState
                    v-else
                    :icon="CalendarDays"
                    title="Nothing matches"
                    :description="
                        filters.when === 'past'
                            ? 'No meetings have been held yet under this filter.'
                            : 'No meetings are coming up under this filter.'
                    "
                    variant="filtered"
                />
            </template>

            <!-- ────────────────────────────── the month and the week ──────────────────────── -->
            <template v-else-if="window">
                <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <MeetingCalendarNav :view="view === 'week' ? 'week' : 'month'" :window="window" :carry="carry" />
                </div>

                <MeetingMonthGrid v-if="view === 'month'" :window="window" :days="days" :carry="carry" />
                <MeetingWeekView v-else :window="window" :days="days" />

                <p v-if="!hasSomethingInWindow" class="text-sm text-muted-foreground">
                    Nothing in {{ window.label }}. You do have meetings — they are in another
                    {{ view === 'month' ? 'month' : 'week' }}.
                </p>
            </template>
        </div>
    </PageShell>
</template>
