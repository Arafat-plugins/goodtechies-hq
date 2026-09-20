<script lang="ts">
export interface ProfileDetails {
    name: string;
    email: string;
    timezone: string;
}
</script>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

const props = defineProps<{
    profile: ProfileDetails;
    timezones: string[];
}>();

const form = useForm({
    name: props.profile.name,
    email: props.profile.email,
    timezone: props.profile.timezone,
});

// Native select, styled to match `Input` (the timezone list is ~400 entries).
const selectClass =
    'border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40';

function submit(): void {
    if (form.processing) {
        return;
    }

    form.put('/profile', {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Details</CardTitle>
            <CardDescription>Your name, sign-in email and timezone.</CardDescription>
        </CardHeader>
        <CardContent>
            <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <Label for="profile-name">
                            Name <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="profile-name"
                            v-model="form.name"
                            name="name"
                            autocomplete="name"
                            required
                            :aria-invalid="form.errors.name ? true : undefined"
                            :aria-describedby="form.errors.name ? 'profile-name-error' : undefined"
                        />
                        <p v-if="form.errors.name" id="profile-name-error" class="text-xs text-destructive">
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="profile-email">
                            Email <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="profile-email"
                            v-model="form.email"
                            name="email"
                            type="email"
                            autocomplete="email"
                            required
                            :aria-invalid="form.errors.email ? true : undefined"
                            :aria-describedby="form.errors.email ? 'profile-email-error' : undefined"
                        />
                        <p v-if="form.errors.email" id="profile-email-error" class="text-xs text-destructive">
                            {{ form.errors.email }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="profile-timezone">
                        Timezone <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <select
                        id="profile-timezone"
                        v-model="form.timezone"
                        name="timezone"
                        required
                        :class="selectClass"
                        :aria-invalid="form.errors.timezone ? true : undefined"
                        :aria-describedby="form.errors.timezone ? 'profile-timezone-error' : undefined"
                    >
                        <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                    </select>
                    <p v-if="form.errors.timezone" id="profile-timezone-error" class="text-xs text-destructive">
                        {{ form.errors.timezone }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
