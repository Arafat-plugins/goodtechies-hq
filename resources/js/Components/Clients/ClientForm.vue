<script lang="ts">
import type { ClientContact } from '@/Components/Clients/ContactsEditor.vue';

export type { ClientContact };

export interface StatusOption {
    value: string;
    label: string;
}

/** A client as every Admin client endpoint serializes it. Optional keys may be absent by role. */
export interface Client {
    id: number;
    name: string;
    status: string;
    status_label: string;
    projects_count?: number;
    contacts?: ClientContact[];
    internal_notes?: string | null;
}
</script>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ContactsEditor from '@/Components/Clients/ContactsEditor.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';

const props = defineProps<{
    statuses: StatusOption[];
    /** Absent on Create. */
    client?: Client;
    submitLabel: string;
}>();

const form = useForm({
    name: props.client?.name ?? '',
    status: props.client?.status ?? (props.statuses[0]?.value ?? ''),
    internal_notes: props.client?.internal_notes ?? '',
    contacts: (props.client?.contacts ?? []).map((contact) => ({ ...contact })),
});

// `useForm` types `errors` by field, but Laravel also sends `contacts.0.email` keys.
const contactErrors = computed(() => form.errors as unknown as Record<string, string>);

function submit(): void {
    if (form.processing) {
        return;
    }

    if (props.client) {
        form.put(`/admin/clients/${props.client.id}`, { preserveScroll: true });

        return;
    }

    form.post('/admin/clients', { preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Card class="min-w-0 gap-4 shadow-xs">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Details</CardTitle>
                <CardDescription>The client's name and where they stand with the agency.</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="client-name">
                            Name <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="client-name"
                            v-model="form.name"
                            name="name"
                            autocomplete="organization"
                            required
                            :disabled="form.processing"
                            :aria-invalid="form.errors.name ? true : undefined"
                            :aria-describedby="form.errors.name ? 'client-name-error' : undefined"
                        />
                        <p v-if="form.errors.name" id="client-name-error" class="text-xs text-destructive">
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="client-status">
                            Status <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Select v-model="form.status" :disabled="form.processing">
                            <SelectTrigger
                                id="client-status"
                                class="w-full"
                                :aria-invalid="form.errors.status ? true : undefined"
                            >
                                <SelectValue placeholder="Choose a status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="status in statuses" :key="status.value" :value="status.value">
                                    {{ status.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.status" class="text-xs text-destructive">{{ form.errors.status }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="client-internal-notes">Internal notes</Label>
                    <Textarea
                        id="client-internal-notes"
                        v-model="form.internal_notes"
                        name="internal_notes"
                        rows="4"
                        :disabled="form.processing"
                        :aria-invalid="form.errors.internal_notes ? true : undefined"
                        aria-describedby="client-internal-notes-help"
                    />
                    <p id="client-internal-notes-help" class="text-xs text-muted-foreground">
                        Only Admins see these notes.
                    </p>
                    <p v-if="form.errors.internal_notes" class="text-xs text-destructive">
                        {{ form.errors.internal_notes }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <Card class="min-w-0 gap-4 shadow-xs">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Contacts</CardTitle>
                <CardDescription>The people to reach at this client.</CardDescription>
            </CardHeader>
            <CardContent>
                <ContactsEditor
                    v-model="form.contacts"
                    :errors="contactErrors"
                    :disabled="form.processing"
                />
                <p v-if="form.errors.contacts" class="mt-3 text-xs text-destructive">{{ form.errors.contacts }}</p>
            </CardContent>
        </Card>

        <div class="flex flex-wrap items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : submitLabel }}
            </Button>
            <Button as-child type="button" variant="outline">
                <Link href="/admin/clients">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
