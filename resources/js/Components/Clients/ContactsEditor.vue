<script lang="ts">
export interface ClientContact {
    name: string;
    role: string;
    email: string;
    phone: string;
}

/** The server keeps `contact_info` small; ten rows is plenty for one client. */
export const MAX_CONTACTS = 10;

export function blankContact(): ClientContact {
    return { name: '', role: '', email: '', phone: '' };
}
</script>

<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

const contacts = defineModel<ClientContact[]>({ required: true });

const props = withDefaults(
    defineProps<{
        /** Laravel's indexed messages, e.g. `contacts.0.email`. */
        errors?: Record<string, string>;
        disabled?: boolean;
    }>(),
    { errors: () => ({}), disabled: false },
);

const FIELDS = [
    { key: 'name', label: 'Name', type: 'text', autocomplete: 'off' },
    { key: 'role', label: 'Role', type: 'text', autocomplete: 'off' },
    { key: 'email', label: 'Email', type: 'email', autocomplete: 'off' },
    { key: 'phone', label: 'Phone', type: 'tel', autocomplete: 'off' },
] as const;

function errorFor(index: number, key: string): string | undefined {
    return props.errors[`contacts.${index}.${key}`];
}

function add(): void {
    if (contacts.value.length >= MAX_CONTACTS) {
        return;
    }

    contacts.value = [...contacts.value, blankContact()];
}

function remove(index: number): void {
    contacts.value = contacts.value.filter((_, at) => at !== index);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="contacts.length === 0" class="text-sm text-muted-foreground">
            No contacts yet. Add the people you deal with at this client.
        </p>

        <div v-for="(contact, index) in contacts" :key="index" class="flex flex-col gap-3 border-b pb-4 last:border-b-0 last:pb-0">
            <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    Contact {{ index + 1 }}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="disabled"
                    :aria-label="`Remove contact ${index + 1}`"
                    @click="remove(index)"
                >
                    <Trash2 aria-hidden="true" />
                </Button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="field in FIELDS" :key="field.key" class="flex min-w-0 flex-col gap-2">
                    <Label :for="`contact-${index}-${field.key}`">{{ field.label }}</Label>
                    <Input
                        :id="`contact-${index}-${field.key}`"
                        v-model="contact[field.key]"
                        :type="field.type"
                        :autocomplete="field.autocomplete"
                        :disabled="disabled"
                        :aria-invalid="errorFor(index, field.key) ? true : undefined"
                        :aria-describedby="errorFor(index, field.key) ? `contact-${index}-${field.key}-error` : undefined"
                    />
                    <p
                        v-if="errorFor(index, field.key)"
                        :id="`contact-${index}-${field.key}-error`"
                        class="text-xs text-destructive"
                    >
                        {{ errorFor(index, field.key) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                :disabled="disabled || contacts.length >= MAX_CONTACTS"
                @click="add"
            >
                <Plus aria-hidden="true" />
                Add contact
            </Button>
            <p v-if="contacts.length >= MAX_CONTACTS" class="text-xs text-muted-foreground">
                Ten contacts is the limit for one client.
            </p>
        </div>
    </div>
</template>
