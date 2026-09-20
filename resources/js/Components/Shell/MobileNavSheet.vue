<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { ref, watch } from 'vue';
import AppWordmark from '@/Components/AppWordmark.vue';
import AppSidebarNav from '@/Components/Shell/AppSidebarNav.vue';
import { Button } from '@/Components/ui/button';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Sheet, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@/Components/ui/sheet';
import type { NavGroup } from '@/navigation/types';

defineProps<{
    groups: NavGroup[];
    homeHref: string;
}>();

const open = ref(false);
const page = usePage();

watch(
    () => page.url,
    () => {
        open.value = false;
    },
);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetTrigger as-child>
            <Button variant="ghost" size="icon" class="-ml-2 lg:hidden">
                <Menu class="size-5" aria-hidden="true" />
                <span class="sr-only">Open navigation</span>
            </Button>
        </SheetTrigger>
        <SheetContent
            side="left"
            class="w-64 gap-0 border-sidebar-border bg-sidebar p-0 text-sidebar-foreground"
        >
            <div class="flex h-14 shrink-0 items-center border-b border-sidebar-border px-4">
                <Link :href="homeHref" @click="open = false">
                    <AppWordmark inverted />
                </Link>
            </div>
            <SheetTitle class="sr-only">Navigation</SheetTitle>
            <SheetDescription class="sr-only">Sections of GoodTechies HQ</SheetDescription>
            <ScrollArea class="min-h-0 flex-1">
                <AppSidebarNav :groups="groups" @navigate="open = false" />
            </ScrollArea>
        </SheetContent>
    </Sheet>
</template>
