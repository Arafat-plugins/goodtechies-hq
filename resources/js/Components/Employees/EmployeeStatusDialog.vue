<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { EmployeeDetail, EmployeeRow } from '@/Components/Employees/employees';
import { employeeRoutes, openTasksUrl } from '@/Components/Employees/employees';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

/**
 * The confirmation for the two status moves, used by both the list and the detail.
 *
 * **Deactivation is not deletion** (Part B §3 rule 11: *"Employee departure = `status =
 * inactive`, never delete"*), so the dialog spends its words saying what actually happens
 * instead of asking "are you sure": the login stops, the record is kept, the history is kept,
 * and the open tasks stay assigned to somebody who is not coming back — which is the one
 * consequence that needs a person to do something, so it carries the link to exactly those
 * tasks.
 *
 * The count comes from the server (`impact.open_task_count`). Absent means the server did not
 * count them, so the sentence loses its NUMBER but never its consequence: "0 open tasks" and
 * "we did not look" are not the same sentence, and only one of them is safe to act on. Either
 * way the link is there, carrying the two filters `/admin/tasks` really reads.
 */

const props = defineProps<{
    employee: (EmployeeRow | EmployeeDetail) | null;
    intent: 'deactivate' | 'reactivate' | 'reset-password';
}>();

const emit = defineEmits<{ close: [] }>();

const working = ref(false);

/**
 * Null when the server sent no count — not zero. `EmployeeResource` puts `impact` on list rows
 * as well as the detail, which is what lets this dialog open from either screen and still say
 * the number.
 */
const openTaskCount = computed<number | null>(() => props.employee?.impact?.open_task_count ?? null);

const tasksHref = computed(() => (props.employee ? openTasksUrl(props.employee) : '#'));

function confirm(): void {
    const employee = props.employee;

    if (!employee || working.value) {
        return;
    }

    working.value = true;

    const url = {
        deactivate: employeeRoutes.deactivate,
        reactivate: employeeRoutes.reactivate,
        'reset-password': employeeRoutes.resetPassword,
    }[props.intent](employee.id);

    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
                emit('close');
            },
        },
    );
}
</script>

<template>
    <Dialog :open="employee !== null" @update:open="(open) => !open && emit('close')">
        <DialogContent>
            <template v-if="intent === 'reset-password'">
                <DialogHeader>
                    <DialogTitle>Re-issue {{ employee?.name }}'s password?</DialogTitle>
                    <DialogDescription>
                        Use this when they cannot sign in — a password that was never passed on, or one nobody
                        remembers. There is no "forgot password" link in this application, so this is the only way
                        back in.
                    </DialogDescription>
                </DialogHeader>

                <ul class="flex flex-col gap-2 text-sm">
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>
                            A new password is generated and shown to you
                            <strong class="font-medium">once</strong>. Have a way to pass it on ready.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>Their old password stops working immediately.</span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>
                            They are signed out everywhere, on every device — so if somebody else had the old
                            password, that ends here too.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>Nothing else changes: their role, their record and their work are untouched.</span>
                    </li>
                </ul>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline" :disabled="working">Cancel</Button>
                    </DialogClose>
                    <Button type="button" :disabled="working" @click="confirm">
                        {{ working ? 'Re-issuing…' : 'Re-issue password' }}
                    </Button>
                </DialogFooter>
            </template>

            <template v-else-if="intent === 'deactivate'">
                <DialogHeader>
                    <DialogTitle>Deactivate {{ employee?.name }}?</DialogTitle>
                    <DialogDescription>
                        Deactivating ends their access. It is not the same as taking them off the system — their
                        record is kept for good.
                    </DialogDescription>
                </DialogHeader>

                <ul class="flex flex-col gap-2 text-sm">
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>They can no longer sign in, and any session they have open ends.</span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>
                            They stay on the Employees list, marked <strong class="font-medium">Inactive</strong>.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span>
                            Their tasks, tracked time, attendance, leave and messages stay exactly as they are.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-muted-foreground">•</span>
                        <span v-if="openTaskCount === null">
                            Anything still open and assigned to them stays assigned to them.
                            <Link :href="tasksHref" class="font-medium underline underline-offset-4">
                                Check their open tasks
                            </Link>
                            and hand them to somebody else, or they sit with nobody working on them.
                        </span>
                        <span v-else-if="openTaskCount > 0">
                            <Link :href="tasksHref" class="font-medium underline underline-offset-4">
                                {{ openTaskCount }} open
                                {{ openTaskCount === 1 ? 'task is' : 'tasks are' }}
                            </Link>
                            still assigned to them. Hand
                            {{ openTaskCount === 1 ? 'it' : 'them' }} to somebody else, or
                            {{ openTaskCount === 1 ? 'it sits' : 'they sit' }} with nobody working on
                            {{ openTaskCount === 1 ? 'it' : 'them' }}.
                        </span>
                        <span v-else>They have no open tasks waiting to be picked up.</span>
                    </li>
                </ul>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="working" @click="emit('close')">
                        Cancel
                    </Button>
                    <Button type="button" variant="destructive" :disabled="working" @click="confirm">
                        {{ working ? 'Deactivating…' : 'Deactivate' }}
                    </Button>
                </DialogFooter>
            </template>

            <template v-else>
                <DialogHeader>
                    <DialogTitle>Reactivate {{ employee?.name }}?</DialogTitle>
                    <DialogDescription>
                        They can sign in again straight away, with the role and the working week they had. Nothing
                        about their history changes.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="working" @click="emit('close')">
                        Cancel
                    </Button>
                    <Button type="button" :disabled="working" @click="confirm">
                        {{ working ? 'Reactivating…' : 'Reactivate' }}
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
