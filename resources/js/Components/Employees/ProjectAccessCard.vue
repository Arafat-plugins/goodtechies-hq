<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { FolderKanban, KeyRound, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { EmployeeDetail, EmployeeProjectGrant, NamedRef, Option } from '@/Components/Employees/employees';
import { employeeRoutes, formatDate, humanise } from '@/Components/Employees/employees';
import StatusBadge from '@/Components/StatusBadge.vue';
import { toneForProjectStatus } from '@/Components/StatusPill.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

/**
 * Project access: which projects this person is on, and which project-level permissions they
 * have been granted on top of their role.
 *
 * Part C §1: *"Project-level overrides (`user_project_permissions`) layer on top — e.g. a
 * MANAGER may be granted `projects.view_finance` for one project."* That is what the grant
 * list is, and it is the reason the two halves share one card: a membership says they can see
 * the project, a grant says what extra they may do inside it, and reading one without the
 * other tells you half the answer.
 *
 * Every control here is drawn from what the server sent. `can_manage_permissions` decides
 * whether the grant form exists at all; `can_revoke` decides it per row; and the two option
 * lists are the server's — a project or a permission that is not in them is not offered,
 * because offering it would be offering something the endpoint refuses (DESIGN.md §5.11).
 */

const props = defineProps<{
    employee: EmployeeDetail;
    /** The projects a grant may be made on. Absent or empty means no grant form. */
    grantableProjects?: NamedRef[];
    /** The permission keys a grant may carry, with their words. */
    grantablePermissions?: Option[];
}>();

const memberships = computed(() => props.employee.projects ?? []);
const grants = computed(() => props.employee.project_permissions ?? []);

const projectOptions = computed(() => props.grantableProjects ?? []);
const permissionOptions = computed(() => props.grantablePermissions ?? []);

const canGrant = computed(
    () =>
        props.employee.permissions?.can_manage_permissions === true &&
        projectOptions.value.length > 0 &&
        permissionOptions.value.length > 0,
);

function permissionLabel(grant: EmployeeProjectGrant): string {
    return grant.permission.label ?? humanise(grant.permission.key.replace(/\./g, ' ')) ?? grant.permission.key;
}

/* ------------------------------------------------------------------ grant */

const granting = ref(false);

const form = useForm({
    project_id: '',
    permission: '',
});

function submitGrant(): void {
    if (form.processing) {
        return;
    }

    form
        .transform((data) => ({ project_id: Number(data.project_id), permission: data.permission }))
        .post(employeeRoutes.grant(props.employee.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                granting.value = false;
            },
        });
}

/* ----------------------------------------------------------------- revoke */

const pendingRevoke = ref<EmployeeProjectGrant | null>(null);
const revoking = ref(false);

function confirmRevoke(): void {
    const grant = pendingRevoke.value;

    if (!grant || revoking.value) {
        return;
    }

    revoking.value = true;

    router.delete(employeeRoutes.revoke(props.employee.id, grant.id), {
        preserveScroll: true,
        onFinish: () => {
            revoking.value = false;
            pendingRevoke.value = null;
        },
    });
}
</script>

<template>
    <!-- `#project-access` is the anchor every "N grants" count on the list links to. -->
    <Card id="project-access" class="min-w-0 scroll-mt-20 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Project access</CardTitle>
            <CardDescription>
                The projects {{ employee.name }} is on, and anything they have been granted on top of their role.
            </CardDescription>
            <CardAction v-if="canGrant && !granting">
                <Button type="button" variant="outline" size="sm" @click="granting = true">
                    <Plus aria-hidden="true" />
                    Grant permission
                </Button>
            </CardAction>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-6">
            <section class="flex min-w-0 flex-col gap-2">
                <h3 class="text-xs font-medium text-muted-foreground">Projects</h3>

                <EmptyState
                    v-if="memberships.length === 0"
                    :icon="FolderKanban"
                    title="Not on any project"
                    description="Add them to a project and it shows up on their own list."
                />

                <ul v-else class="divide-y">
                    <li
                        v-for="project in memberships"
                        :key="project.id"
                        class="flex min-w-0 flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                    >
                        <Link
                            :href="project.url ?? `/admin/projects/${project.id}`"
                            class="min-w-0 text-sm font-medium break-words hover:underline"
                        >
                            {{ project.name }}
                        </Link>
                        <div class="flex min-w-0 flex-wrap items-center gap-2 sm:justify-end">
                            <span class="text-xs text-muted-foreground break-words">
                                {{ project.role_on_project ?? 'No role set' }}
                            </span>
                            <!--
                                The project's own status, through the ONE mapping of a project
                                status to a tone (`toneForProjectStatus`). A second copy of it
                                here would be a second copy that drifts.
                            -->
                            <StatusBadge
                                v-if="project.status_label"
                                :status="toneForProjectStatus(project.status ?? '')"
                                :label="project.status_label"
                                size="sm"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <section class="flex min-w-0 flex-col gap-2">
                <h3 class="text-xs font-medium text-muted-foreground">Permission grants</h3>

                <EmptyState
                    v-if="grants.length === 0"
                    :icon="KeyRound"
                    title="No extra permissions"
                    description="They can do what their role allows on the projects above, and nothing more."
                />

                <ul v-else class="divide-y">
                    <li
                        v-for="grant in grants"
                        :key="grant.id"
                        class="flex min-w-0 flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <p class="text-sm break-words">
                                <span class="font-medium">{{ permissionLabel(grant) }}</span>
                                on
                                <Link
                                    :href="grant.project.url ?? `/admin/projects/${grant.project.id}`"
                                    class="font-medium hover:underline"
                                >
                                    {{ grant.project.name }}
                                </Link>
                            </p>
                            <p v-if="grant.granted_by || grant.granted_at" class="text-xs text-muted-foreground">
                                Granted
                                <template v-if="grant.granted_by">by {{ grant.granted_by }}</template>
                                <template v-if="grant.granted_at">
                                    on {{ formatDate(grant.granted_at) }}
                                </template>
                            </p>
                        </div>

                        <Button
                            v-if="grant.can_revoke"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="shrink-0"
                            @click="pendingRevoke = grant"
                        >
                            <X aria-hidden="true" />
                            Revoke
                            <span class="sr-only">{{ permissionLabel(grant) }} on {{ grant.project.name }}</span>
                        </Button>
                    </li>
                </ul>
            </section>

            <form v-if="canGrant && granting" class="flex min-w-0 flex-col gap-4 border-t pt-4" @submit.prevent="submitGrant">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="grant-project">Project</Label>
                        <Select v-model="form.project_id" :disabled="form.processing">
                            <SelectTrigger
                                id="grant-project"
                                class="w-full"
                                :aria-invalid="form.errors.project_id ? true : undefined"
                            >
                                <SelectValue placeholder="Choose a project" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="project in projectOptions"
                                    :key="project.id"
                                    :value="String(project.id)"
                                >
                                    {{ project.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.project_id" class="text-xs text-destructive">
                            {{ form.errors.project_id }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="grant-permission">Permission</Label>
                        <Select v-model="form.permission" :disabled="form.processing">
                            <SelectTrigger
                                id="grant-permission"
                                class="w-full"
                                :aria-invalid="form.errors.permission ? true : undefined"
                            >
                                <SelectValue placeholder="Choose a permission" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="permission in permissionOptions"
                                    :key="permission.value"
                                    :value="permission.value"
                                >
                                    {{ permission.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.permission" class="text-xs text-destructive">
                            {{ form.errors.permission }}
                        </p>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    A grant applies to that one project only, and it is recorded in the audit log.
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" size="sm" :disabled="form.processing">
                        {{ form.processing ? 'Granting…' : 'Grant permission' }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.processing"
                        @click="
                            granting = false;
                            form.reset();
                            form.clearErrors();
                        "
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>

    <Dialog :open="pendingRevoke !== null" @update:open="(open) => !open && (pendingRevoke = null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Revoke this permission?</DialogTitle>
                <DialogDescription>
                    <template v-if="pendingRevoke">
                        {{ employee.name }} will no longer have
                        <strong class="font-medium">{{ permissionLabel(pendingRevoke) }}</strong>
                        on {{ pendingRevoke.project.name }}. Whatever their role gives them on that project is
                        unchanged, and the change is recorded in the audit log.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="revoking" @click="pendingRevoke = null">
                    Cancel
                </Button>
                <Button type="button" variant="destructive" :disabled="revoking" @click="confirmRevoke">
                    {{ revoking ? 'Revoking…' : 'Revoke' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
