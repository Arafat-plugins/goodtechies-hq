<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Hourglass, House, Lock, SearchX, ServerCrash, Wrench } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * Reliability slice 2a: the one page for every error an Inertia visit can meet — 403, 404, 429,
 * 500, 503 — rendered by `App\Http\ErrorResponses` with the response's own status, in place of
 * Laravel's HTML inside Inertia's raw modal.
 *
 * The layout is chosen the way `Shared/Profile.vue` chooses it, from the viewer's own surface, so a
 * signed-in person keeps their shell (and their way out through the nav). A guest — or a request
 * that failed before the session was read, such as a URL no route matches — gets `AuthLayout`.
 * `auth` can be absent altogether in that last case, hence the optional chaining.
 */
defineOptions({
    layout: (props: Partial<SharedProps>) => {
        const surface = props.auth?.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        if (surface === 'accountant') {
            return AccountantLayout;
        }

        return surface === 'employee' ? EmployeeLayout : AuthLayout;
    },
});

const props = defineProps<{
    status: number;
    /** The signed-in person's dashboard, or the login page for a guest. */
    home: string;
}>();

const page = usePage();

const signedIn = computed(() => Boolean((page.props as Partial<SharedProps>).auth?.user?.surface));

interface ErrorCopy {
    title: string;
    icon: Component;
}

const COPY: Record<number, ErrorCopy> = {
    403: { title: "You don't have access to this.", icon: Lock },
    404: { title: "This page doesn't exist, or you don't have access to it.", icon: SearchX },
    429: { title: 'Too many attempts. Wait a minute and try again.', icon: Hourglass },
    500: { title: 'Something went wrong on our side.', icon: ServerCrash },
    503: { title: 'goodERP is being updated. Try again in a minute.', icon: Wrench },
};

const copy = computed<ErrorCopy>(() => COPY[props.status] ?? COPY[500]);

function goBack(): void {
    window.history.back();
}
</script>

<template>
    <Head :title="copy.title" />

    <PageShell v-if="signedIn" :title="copy.title">
        <Card>
            <CardContent class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                <span class="rounded-full bg-muted p-3 text-muted-foreground">
                    <component :is="copy.icon" class="size-5" aria-hidden="true" />
                </span>
                <p class="text-sm text-muted-foreground tabular-nums sm:flex-1">Error {{ status }}</p>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <Button variant="outline" @click="goBack">
                        <ArrowLeft aria-hidden="true" />
                        Go back
                    </Button>
                    <Button as-child>
                        <Link :href="home">
                            <House aria-hidden="true" />
                            Go to my home page
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    </PageShell>

    <CardContent v-else class="flex flex-col items-center gap-4 text-center">
        <span class="rounded-full bg-muted p-3 text-muted-foreground">
            <component :is="copy.icon" class="size-5" aria-hidden="true" />
        </span>
        <div class="flex flex-col gap-1">
            <h1 class="text-base font-semibold text-foreground">{{ copy.title }}</h1>
            <p class="text-sm text-muted-foreground tabular-nums">Error {{ status }}</p>
        </div>
        <div class="flex w-full flex-col gap-2">
            <Button variant="outline" @click="goBack">
                <ArrowLeft aria-hidden="true" />
                Go back
            </Button>
            <Button as-child>
                <Link :href="home">
                    <House aria-hidden="true" />
                    Go to my home page
                </Link>
            </Button>
        </div>
    </CardContent>
</template>
