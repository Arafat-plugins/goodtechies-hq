<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, LogOut, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { ROLE_LABELS } from '@/navigation/types';

const page = usePage();

const user = computed(() => page.props.auth.user);
const roleLabel = computed(() => (user.value?.role ? ROLE_LABELS[user.value.role] : ''));
const initials = computed(() =>
    (user.value?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join(''),
);
</script>

<template>
    <DropdownMenu v-if="user">
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" class="h-10 gap-2 px-2">
                <Avatar>
                    <AvatarFallback class="bg-primary text-xs font-medium text-primary-foreground">
                        {{ initials }}
                    </AvatarFallback>
                </Avatar>
                <span class="hidden min-w-0 flex-col items-start text-left sm:flex">
                    <span class="max-w-40 truncate text-sm font-medium">{{ user.name }}</span>
                    <span class="text-xs font-normal text-muted-foreground">{{ roleLabel }}</span>
                </span>
                <ChevronDown class="text-muted-foreground" aria-hidden="true" />
                <span class="sr-only sm:hidden">Account menu for {{ user.name }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel class="flex flex-col gap-1 font-normal">
                <span class="truncate text-sm font-medium">{{ user.name }}</span>
                <span class="truncate text-xs text-muted-foreground">{{ user.email }}</span>
                <span class="text-xs text-muted-foreground">{{ roleLabel }}</span>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link href="/profile" class="w-full">
                    <UserRound aria-hidden="true" />
                    Profile
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link href="/logout" method="post" as="button" class="w-full">
                    <LogOut aria-hidden="true" />
                    Sign out
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
