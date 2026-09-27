<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Copy, KeyRound } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import type { FirstSignInCredential } from '@/Components/Employees/employees';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * The generated first-sign-in password, handed over once.
 *
 * There is no email in the MVP (Part H §1) and no password-reset route in `routes/auth.php` —
 * login and the two-factor challenge are the whole of the guest surface — so there is nothing to
 * mail an invitation to and nothing for a reset link to point at. Reading this string out to the
 * new colleague **is** the delivery mechanism, which is why this panel is loud, sits at the top of
 * their record, and spends its words on *hand it over now*.
 *
 * ## Why it is not a flash message
 *
 * It used to ride in `success`, the generic channel `FlashMessage.vue` renders as a persistent
 * Alert — the same strip, the same grey, the same shape as *"Project archived"*. A credential is
 * not that kind of sentence: it has to look like a thing being handed over, it needs a copy
 * control, and it must not be one of three alerts stacked on a page.
 *
 * ## The promise is now true
 *
 * The old sentence claimed *"It is not shown again"* and that was false. Inertia keeps every
 * page's props in `history.state`, so navigating on and pressing Back restored this response
 * from the browser — password included — without asking the server anything.
 *
 * Three things together fix it, and all three are needed:
 *
 *   1. **The server says it once.** `EmployeeController::store()` flashes the password under one
 *      key and `show()` pulls it. Flash data lives for a single request, so a reload of this URL,
 *      a second tab, and every later visit have nothing to render.
 *   2. **The history entry is ciphertext.** That response calls `Inertia::encryptHistory()`, so
 *      what the browser stores for this page is an encrypted blob rather than a readable object,
 *      and `Inertia::clearHistory()` throws away the key everything written *before* it used.
 *   3. **The key is rolled again the moment this panel exists.** `router.clearHistory()` below
 *      runs in `onMounted`, which Inertia reaches strictly *after* it has written this page's
 *      history entry — so the entry is sealed and then the key is destroyed. Pressing Back onto
 *      it leaves Inertia unable to restore it, so it re-asks the server, and the server has
 *      already spent the flash. What comes back is this page without a password.
 *
 * The password is held in a local ref rather than read from the prop in the template, so none of
 * that can blank the panel while the Admin is still reading it.
 *
 * It is never audited, never stored in plaintext and carried by no resource — `audit_logs` has no
 * `password` key on `employee.created`, and `EmployeeResource` has no branch that could add one.
 */

const props = defineProps<{ credential: FirstSignInCredential }>();

/**
 * Captured once. Everything else on this screen may be re-rendered by a later visit; this must
 * not vanish mid-hand-over because something else on the page saved.
 */
const password = ref(props.credential.password);
const name = ref(props.credential.name ?? 'The new employee');
const email = ref(props.credential.email ?? null);

/**
 * A re-issue, or a new hire's first one.
 *
 * Captured with the rest, and it changes two sentences rather than the design: the same
 * credential, handed over the same way, with the one consequence that differs between them
 * said out loud — a reset has just signed that person out everywhere, and somebody who does
 * not know that will be told "it stopped working" an hour later.
 */
const reissued = ref(props.credential.reissued === true);

const dismissed = ref(false);
const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    // Step 3 above. By the time a child component mounts, Inertia has already written this
    // page's (encrypted) history entry — so this destroys the key that entry was sealed with,
    // and nothing can read it back. Entries written before this page were plaintext and are
    // untouched, so ordinary Back navigation everywhere else still works.
    router.clearHistory();
});

onUnmounted(() => clearTimeout(copiedTimer));

async function copyPassword(): Promise<void> {
    try {
        await navigator.clipboard.writeText(password.value);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = false), 2000);
    } catch {
        // A refused clipboard is not an error worth an alert — the password is on the screen in
        // a font built to be read out, which is the fallback.
        copied.value = false;
    }
}
</script>

<template>
    <Card v-if="!dismissed" class="min-w-0 gap-4 border-status-review-border bg-status-review-bg">
        <CardHeader>
            <CardTitle class="flex min-w-0 items-center gap-2 text-sm font-medium">
                <KeyRound class="size-4 shrink-0 text-status-review" aria-hidden="true" />
                {{ reissued ? `${name}'s new sign-in password` : `${name}'s first sign-in password` }}
            </CardTitle>
            <CardDescription class="text-foreground">
                Give this to them now. It is not stored anywhere, nobody can look it up later, and this is the
                only time it is shown — leaving this page takes it away for good.
                <template v-if="reissued">
                    Their old password no longer works and they have been signed out everywhere.
                </template>
            </CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <dl class="flex min-w-0 flex-col gap-3">
                <div v-if="email" class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">They sign in with</dt>
                    <dd class="font-mono text-sm break-all">{{ email }}</dd>
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">Password</dt>
                    <dd
                        class="rounded-md border bg-card px-3 py-2 font-mono text-base tracking-wide break-all select-all"
                    >
                        {{ password }}
                    </dd>
                </div>
            </dl>

            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" @click="copyPassword">
                    <Check v-if="copied" class="text-status-done" aria-hidden="true" />
                    <Copy v-else aria-hidden="true" />
                    {{ copied ? 'Copied' : 'Copy password' }}
                </Button>
                <Button type="button" variant="outline" size="sm" @click="dismissed = true">
                    I have passed it on
                </Button>
            </div>

            <p class="text-xs text-muted-foreground">
                Ask them to change it at Profile → Password as soon as they are in. If they are an Admin or an
                Accountant they will be walked through two-factor enrolment before they can reach anything else.
            </p>
        </CardContent>
    </Card>
</template>
