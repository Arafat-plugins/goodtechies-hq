<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, Copy, Download, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { CardContent, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';

defineOptions({ layout: AuthLayout });

const props = defineProps<{
    codes: string[];
}>();

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

function codesText(): string {
    return `GoodTechies HQ recovery codes\nEach code works once.\n\n${props.codes.join('\n')}\n`;
}

async function copyAll(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

function download(): void {
    const blob = new Blob([codesText()], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'goodtechies-hq-recovery-codes.txt';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <Head title="Save your recovery codes" />

    <CardHeader>
        <CardTitle>Save your recovery codes</CardTitle>
    </CardHeader>

    <CardContent class="flex flex-col gap-4">
        <Alert>
            <TriangleAlert class="text-status-review" />
            <AlertDescription class="text-foreground">
                Each code works once. They will not be shown again.
            </AlertDescription>
        </Alert>

        <ul class="grid grid-cols-2 gap-2 rounded-md border bg-muted p-4 font-mono text-sm" aria-label="Recovery codes">
            <li v-for="code in codes" :key="code" class="text-center">{{ code }}</li>
        </ul>

        <div class="grid grid-cols-2 gap-2">
            <Button type="button" variant="outline" @click="copyAll">
                <Check v-if="copied" class="text-status-done" />
                <Copy v-else />
                {{ copied ? 'Copied' : 'Copy all' }}
            </Button>
            <Button type="button" variant="outline" @click="download">
                <Download />
                Download .txt
            </Button>
        </div>
    </CardContent>

    <CardFooter>
        <Button as-child class="w-full">
            <Link href="/">Continue</Link>
        </Button>
    </CardFooter>
</template>
