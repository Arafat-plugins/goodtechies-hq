<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { EmployeeOption, Project } from '@/Components/Projects/ProjectForm.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

const props = defineProps<{
    project: Project;
    assignableEmployees: EmployeeOption[];
    /** Mirrors `permissions.can_manage_members`; false leaves the card read-only. */
    canManage: boolean;
}>();

const members = computed(() => props.project.members ?? []);

const editing = ref(false);

const form = useForm({
    members: members.value.map((member) => member.id),
    roles: {} as Record<string, string>,
});

function rolesFromProject(): Record<string, string> {
    const roles: Record<string, string> = {};

    for (const member of members.value) {
        roles[String(member.id)] = member.role_on_project ?? '';
    }

    return roles;
}

function startEditing(): void {
    form.clearErrors();
    form.members = members.value.map((member) => member.id);
    form.roles = rolesFromProject();
    editing.value = true;
}

function isMember(employeeId: number): boolean {
    return form.members.includes(employeeId);
}

function toggleMember(employeeId: number, checked: boolean): void {
    form.members = checked
        ? [...form.members, employeeId]
        : form.members.filter((id) => id !== employeeId);

    if (checked && form.roles[String(employeeId)] === undefined) {
        form.roles = { ...form.roles, [String(employeeId)]: '' };
    }
}

function setRole(employeeId: number, role: string): void {
    form.roles = { ...form.roles, [String(employeeId)]: role };
}

function submit(): void {
    if (form.processing) {
        return;
    }

    // Only send roles for people who are actually on the project.
    form.transform((data) => ({
        members: data.members,
        roles: Object.fromEntries(
            data.members
                .filter((id) => (data.roles[String(id)] ?? '').trim() !== '')
                .map((id) => [String(id), data.roles[String(id)]]),
        ),
    }));

    form.put(`/admin/projects/${props.project.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

// `useForm` types `errors` by top-level field; Laravel also sends `members.0` keys.
const errors = computed(() => form.errors as unknown as Record<string, string | undefined>);
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Members</CardTitle>
            <CardDescription>Everyone assigned to this project and what they do on it.</CardDescription>
            <CardAction v-if="canManage && !editing">
                <Button type="button" variant="outline" size="sm" @click="startEditing">
                    <Pencil aria-hidden="true" />
                    Edit members
                </Button>
            </CardAction>
        </CardHeader>

        <CardContent>
            <template v-if="!editing">
                <EmptyState
                    v-if="members.length === 0"
                    :icon="Users"
                    title="No members yet"
                    description="Assign people and they will see this project on their own list."
                />
                <ul v-else class="divide-y">
                    <li
                        v-for="member in members"
                        :key="member.id"
                        class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                    >
                        <p class="text-sm font-medium break-words">{{ member.name }}</p>
                        <p class="text-xs text-muted-foreground break-words sm:text-right">
                            {{ member.role_on_project ?? 'No role set' }}
                        </p>
                    </li>
                </ul>
                <p v-if="!canManage" class="mt-3 text-xs text-muted-foreground">
                    You cannot change who works on this project.
                </p>
            </template>

            <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="employee in assignableEmployees"
                        :key="employee.id"
                        class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-2">
                            <Checkbox
                                :id="`member-${employee.id}`"
                                :model-value="isMember(employee.id)"
                                :disabled="form.processing"
                                @update:model-value="(checked) => toggleMember(employee.id, checked === true)"
                            />
                            <Label :for="`member-${employee.id}`" class="min-w-0 font-normal">
                                {{ employee.name }}
                            </Label>
                        </div>
                        <Input
                            :id="`member-role-${employee.id}`"
                            :model-value="form.roles[String(employee.id)] ?? ''"
                            :disabled="form.processing || !isMember(employee.id)"
                            :aria-label="`Role on project for ${employee.name}`"
                            placeholder="Role on project"
                            class="sm:w-56"
                            @update:model-value="(value) => setRole(employee.id, String(value))"
                        />
                    </li>
                </ul>

                <p v-if="errors.members" class="text-xs text-destructive">{{ errors.members }}</p>
                <p v-if="errors.roles" class="text-xs text-destructive">{{ errors.roles }}</p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : 'Save members' }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.processing"
                        @click="editing = false"
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
