<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { MessageSquare, UsersRound } from '@lucide/vue';
import type { TeamMember } from '@/Components/Team/team';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * The Team directory (Part D §2: Admin WORK → Team, Employee Messages → Team).
 *
 * Name, role, today's availability, DM button. Four things, and the plan's line ends *"no
 * salary, no tracking data"* — so this screen prints exactly what
 * `TeamMemberResource` sends and has no way to print anything else. There is no clock time
 * here, no minute count, no total, no ratio and no order but alphabetical: nothing on this page
 * ranks or compares anybody (Part H §1).
 *
 * ## One page, two shells
 *
 * Shared like Profile, Attendance, Leave and Messages, and it picks its layout the same way —
 * from `auth.user.surface`. There is no Accountant branch because there is no Accountant
 * reader: `can:messages.use` refuses them at the route. They still appear in the LIST, because
 * they work here; what they do not get is a DM button pointing at them, and the server decides
 * that per row.
 *
 * ## The DM button
 *
 * `dm_url` is the whole of it: a URL or null, resolved per reader on the server. Nothing here
 * builds one from an id, and nothing here asks what role anybody is (decisions 2-28, 2-31).
 * It posts, because `POST /messages/direct/{user}` opens or creates the conversation and
 * redirects into it — so the button is a button and not a link that looks like one.
 *
 * ## A list, not a table
 *
 * Five to fifteen people, read at a glance. `DataTable` would bring a column menu, a density
 * toggle and a sort nothing implements, which DESIGN.md §5.11 forbids on its own. At phone
 * width each row stacks; at `sm` and up the name sits left and the status and button right.
 */

defineOptions({
    layout: (props: SharedProps) => (props.auth.user?.surface === 'admin' ? AdminLayout : EmployeeLayout),
});

defineProps<{
    today: { value: string; label: string };
    members: TeamMember[];
}>();
</script>

<template>
    <Head title="Team" />

    <PageShell title="Team" :description="today.label" :breadcrumb="[{ label: 'Work' }, { label: 'Team' }]">
        <div class="flex min-w-0 flex-col gap-4">
            <Card v-if="!members.length" class="p-6">
                <EmptyState
                    :icon="UsersRound"
                    title="Nobody to show"
                    description="The directory lists active employees. It fills up as people are added."
                />
            </Card>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="flex min-w-0 flex-col gap-3 rounded-md border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            <span class="min-w-0 break-words">{{ member.name }}</span>
                            <!-- A word, not a tint: "you" is state, and state carried by colour
                                 alone is not readable by everybody (DESIGN.md §5.6). -->
                            <span
                                v-if="member.is_you"
                                class="rounded-full border px-1.5 text-xs font-normal text-muted-foreground"
                            >
                                You
                            </span>
                        </p>
                        <p class="text-xs text-muted-foreground">{{ member.role_label }}</p>
                    </div>

                    <div class="flex min-w-0 flex-wrap items-center gap-2 sm:justify-end">
                        <!-- The word is always beside the tint, and a person with no status to
                             tint still gets the word. -->
                        <StatusBadge
                            v-if="member.availability_tone"
                            :status="member.availability_tone"
                            :label="member.availability_label"
                            size="sm"
                        />
                        <span v-else class="text-sm text-muted-foreground">{{ member.availability_label }}</span>

                        <!-- Why the office is shut, when it is. Beside the status, never
                             instead of it. -->
                        <span v-if="member.holiday_name" class="text-xs text-muted-foreground">
                            {{ member.holiday_name }}
                        </span>

                        <Button v-if="member.dm_url" as-child variant="outline" size="sm">
                            <Link :href="member.dm_url" method="post" as="button" preserve-scroll>
                                <MessageSquare class="size-4" aria-hidden="true" />
                                <span aria-hidden="true">Message</span>
                                <span class="sr-only">Message {{ member.name }}</span>
                            </Link>
                        </Button>
                    </div>
                </li>
            </ul>

            <p v-if="members.length" class="text-xs text-muted-foreground">
                Availability is today's status from each person's own work schedule — the same answer the Attendance
                roster gives. It is a status, not a measure: nothing here counts hours or compares anybody.
            </p>
        </div>
    </PageShell>
</template>
