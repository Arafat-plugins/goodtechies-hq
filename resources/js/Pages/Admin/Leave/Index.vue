<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Inbox, Scale } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import LeaveDecisionDialog, { type LeaveDecision } from '@/Components/Leave/LeaveDecisionDialog.vue';
import LeaveRequestCard from '@/Components/Leave/LeaveRequestCard.vue';
import type { LeaveRequestRow, LeaveStatusOption } from '@/Components/Leave/leave';
import { leaveRoutes } from '@/Components/Leave/leave';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { cn } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → Leave: the requests queue.
 *
 * ## It opens on what is still owed a decision
 *
 * A queue is a list of things waiting on somebody, so the default is the two open statuses and
 * `?status=` widens it. The filter is in the URL, because a filtered queue is a link a colleague
 * can open (DESIGN.md §5.10).
 *
 * The rows are **soonest first**, not newest: a request for next Monday matters more than one
 * for December, and a queue ordered by when it was filed buries the urgent one under the
 * patient one. It is not ordered by person, by length or by anything that would read as a
 * ranking (Part H §1).
 *
 * ## Nothing here decides what may be done
 *
 * Each row's three buttons come from `request.permissions.can_decide`, which is
 * `LeaveRequestPolicy::decide` resolved per record on the server — so an Admin's own request
 * appears in the queue with no buttons rather than with three the endpoint would refuse
 * (decisions 2-28, 2-31). Every endpoint checks again.
 */

defineProps<{
    requests: LeaveRequestRow[];
    counts: Record<string, number>;
    filters: { status: string | null };
    statuses: LeaveStatusOption[];
}>();

const deciding = ref<LeaveRequestRow | null>(null);
const decision = ref<LeaveDecision>('approve');
const dialogOpen = ref(false);

function ask(request: LeaveRequestRow, verb: LeaveDecision): void {
    deciding.value = request;
    decision.value = verb;
    dialogOpen.value = true;
}
</script>

<template>
    <Head title="Leave" />

    <PageShell
        title="Leave"
        description="Requests waiting on a decision, and everything that has been decided."
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Leave' }]"
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child variant="outline">
                    <Link :href="leaveRoutes.calendar()">
                        <CalendarDays class="size-4" aria-hidden="true" />
                        Calendar
                    </Link>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="leaveRoutes.balances">
                        <Scale class="size-4" aria-hidden="true" />
                        Balances
                    </Link>
                </Button>
            </div>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!--
                The filter. Each chip carries its own count, from the same scope the list uses,
                so a number and the list it opens cannot disagree (decision 2-37). Zero is shown
                rather than hidden: a status with nothing in it is an answer.
            -->
            <nav aria-label="Filter by status" class="flex min-w-0 flex-wrap gap-2">
                <Button
                    as-child
                    size="sm"
                    :variant="filters.status === null ? 'default' : 'outline'"
                    :class="cn('tabular-nums')"
                >
                    <Link :href="leaveRoutes.queue()" :aria-current="filters.status === null ? 'page' : undefined">
                        Waiting · {{ counts.open ?? 0 }}
                    </Link>
                </Button>
                <Button
                    v-for="status in statuses"
                    :key="status.value"
                    as-child
                    size="sm"
                    :variant="filters.status === status.value ? 'default' : 'outline'"
                    class="tabular-nums"
                >
                    <Link
                        :href="leaveRoutes.queue(status.value)"
                        :aria-current="filters.status === status.value ? 'page' : undefined"
                    >
                        {{ status.label }} · {{ counts[status.value] ?? 0 }}
                    </Link>
                </Button>
            </nav>

            <ul v-if="requests.length" class="flex min-w-0 flex-col gap-3">
                <li v-for="request in requests" :key="request.id">
                    <LeaveRequestCard
                        :request="request"
                        show-decisions
                        @approve="ask($event, 'approve')"
                        @reject="ask($event, 'reject')"
                        @correction="ask($event, 'correction')"
                    />
                </li>
            </ul>

            <Card v-else class="p-4">
                <EmptyState
                    :icon="Inbox"
                    :title="filters.status === null ? 'Nothing waiting' : 'Nothing with that status'"
                    :description="
                        filters.status === null
                            ? 'Every leave request has been decided.'
                            : 'No leave request currently has this status.'
                    "
                />
            </Card>
        </div>

        <LeaveDecisionDialog v-model:open="dialogOpen" :decision="decision" :request="deciding" />
    </PageShell>
</template>
