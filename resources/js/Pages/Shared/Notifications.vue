<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BellOff, CheckCheck, Clock } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type {
    NotificationRow as NotificationRowPayload,
    NotificationTabKey,
    NotificationTabSummary,
} from '@/Components/Notifications/notifications';
import { TAB_PHASE, centerHref, markAllRead } from '@/Components/Notifications/notifications';
import NotificationRow from '@/Components/Notifications/NotificationRow.vue';
import PageShell from '@/Components/PageShell.vue';
import Pagination from '@/Components/Pagination.vue';
import type { PaginationLinks, PaginationMeta } from '@/Components/Pagination.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useNavigationPending } from '@/lib/useNavigationPending';
import type { SharedProps } from '@/types';

/**
 * The Notification Center (master prompt §11): seven tabs, mark read, mark all read, and a deep
 * link on every row.
 *
 * **It is one shared page on one route, not three.** A person's own mail is a fact about the
 * person and not about the shell they are in, which is why `routes/shared.php` carries the
 * endpoints and why this picks its layout from the viewer's own surface, exactly as
 * `Pages/Shared/Profile.vue` does. The only thing that differs between an Admin's Center and an
 * employee's is where each row's `link` points, and `NotificationResource` has already resolved
 * that per reader.
 *
 * **The route is the endpoint.** `GET /notifications` answers this page to a browser and the
 * same payload as JSON to anything that asks for JSON, so the tab is a query parameter on a URL
 * somebody can bookmark or send, and both writes' `back()` re-renders this screen from the
 * server. Nothing below holds a count of its own.
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
    tab: NotificationTabKey;
    tabs: NotificationTabSummary[];
    unread_count: number;
    notifications: NotificationRowPayload[];
    links: PaginationLinks;
    meta: PaginationMeta;
}>();

const pending = useNavigationPending();

const current = computed(
    () => props.tabs.find((tab) => tab.key === props.tab) ?? props.tabs[0]!,
);

/** The phase an unbuilt tab is waiting for. Only the four that have no types yet have one. */
const waitingForPhase = computed(() => (current.value.is_built ? null : TAB_PHASE[current.value.key] ?? null));

/**
 * A tab is a visit, because the tab is in the URL.
 *
 * `activation-mode="manual"` is what makes that bearable with a keyboard: the arrow keys move
 * between the triggers and Enter or Space chooses one, so running along seven tabs is one
 * request rather than seven. The selected tab follows the server's answer rather than the
 * click, so the strip and the list can never disagree about which tab is being shown.
 */
function choose(value: string | number): void {
    const key = String(value) as NotificationTabKey;

    if (key === props.tab) {
        return;
    }

    router.get(centerHref(key), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <PageShell
        title="Notifications"
        description="Everything you have been told about, newest first. Reading one takes you to the thing it is about."
    >
        <template #actions>
            <Button v-if="unread_count > 0" variant="outline" @click="markAllRead()">
                <CheckCheck aria-hidden="true" />
                Mark all read
                <span class="sr-only">({{ unread_count }} unread)</span>
            </Button>
        </template>

        <!--
            The strip and the panel are one `Tabs` root, in the page body rather than in
            `PageShell`'s `tabs` slot: that slot is a different part of the DOM, and a `TabsList`
            separated from its `TabsContent` is a tab strip that controls nothing as far as a
            screen reader is concerned.
        -->
        <Tabs :model-value="tab" activation-mode="manual" class="min-w-0 gap-4" @update:model-value="choose">
            <!-- The trigger row scrolls on its own; the page never does. -->
            <div class="min-w-0 max-w-full overflow-x-auto">
                <TabsList>
                    <TabsTrigger v-for="one in tabs" :key="one.key" :value="one.key">
                        {{ one.label }}
                        <!--
                            The count beside the label, not a coloured dot: a tinted mark would
                            be a state carried by colour alone (DESIGN.md §5.6), and the number
                            is the more useful thing anyway. The counts arrive from `index`'s one
                            grouped query; nothing is counted here.
                        -->
                        <span
                            v-if="one.unread_count > 0"
                            class="rounded-full bg-foreground/10 px-1.5 text-xs font-medium tabular-nums"
                        >
                            {{ one.unread_count }}
                            <span class="sr-only">unread</span>
                        </span>
                    </TabsTrigger>
                </TabsList>
            </div>

            <!--
                The panel is a tab stop of its own — reka gives it `tabindex="0"` so a keyboard
                can reach the list the strip controls — and the primitive's base class is
                `outline-none`, which left that stop with nothing to show for itself. The ring
                is put back here rather than in the generated component.
            -->
            <TabsContent
                :value="tab"
                class="min-w-0 rounded-lg focus-visible:ring-3 focus-visible:ring-ring/50"
                :aria-busy="pending || undefined"
            >
                <Card class="min-w-0 gap-0 overflow-hidden p-0">
                    <!--
                        A tab with no types yet says which phase fills it. It is not hidden and
                        it is not faked: `is_built` comes from `NotificationTab::isBuilt()`, and
                        an empty list with no explanation reads as a bug rather than as a plan.
                    -->
                    <EmptyState
                        v-if="waitingForPhase !== null"
                        :icon="Clock"
                        :title="`Arrives in Phase ${waitingForPhase}`"
                        :description="`${current.label} notifications will appear here once that part of the app is built.`"
                    />

                    <EmptyState
                        v-else-if="notifications.length === 0"
                        :icon="BellOff"
                        title="Nothing here yet"
                        :description="
                            tab === 'all'
                                ? 'Assignments, reviews and comments land here as they happen.'
                                : `Nothing on the ${current.label} tab yet.`
                        "
                    />

                    <ul v-else class="min-w-0">
                        <NotificationRow v-for="row in notifications" :key="row.id" :row="row" />
                    </ul>
                </Card>

                <div v-if="meta.last_page > 1" class="mt-4">
                    <Pagination :links="links" :meta="meta" />
                </div>
            </TabsContent>
        </Tabs>
    </PageShell>
</template>
