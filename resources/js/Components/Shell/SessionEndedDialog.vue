<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ExternalLink, LogIn, UserRoundCog } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { recheckSession, sessionAttention, sessionState } from '@/lib/session';
import { toast } from '@/lib/toast';

/**
 * Reliability slice 1: the session ended (or the role changed) while this page was open.
 *
 * Mounted once, by `ShellLive.vue`, which every layout renders inside `<main>`. It draws
 * `lib/session.ts`'s state and owns nothing else: every poller has already stopped by itself on
 * the same state.
 *
 * **The page is locked, not replaced.** While the session is not `ok`, `<main>` is blurred and
 * made `inert` + `aria-hidden` — payroll or salaries must not stay readable on a shared PC after
 * an idle timeout — but nothing in it is unmounted, so whatever the person had typed is still in
 * its field when they are back. The dialog cannot be dismissed into a page it has locked.
 *
 * - **Sign in in a new tab** opens `/login` beside this one. When this tab is looked at again
 *   (`focus`, or `visibilitychange` to visible) the session is re-checked. The same person back:
 *   this closes, every poll resumes, one toast. A DIFFERENT person signed in: the page reloads,
 *   so nothing of the first person's is shown to or sent as the second.
 * - **Sign in here** reloads the page: the server redirects to the login page and brings the
 *   person back to this URL afterwards (the intended URL). Typed text is lost that way, which is
 *   why it is the secondary action.
 */

const page = usePage();

/** Whose page this is — read once, before any recheck could change what the props say. */
const pageUserId: number | null = page.props.auth.user?.id ?? null;

const open = ref(false);

/* ---------------------------------------------------------------- the lock on <main> */

const LOCK_CLASSES = ['blur-md', 'select-none'];

function mainContent(): HTMLElement | null {
    return typeof document === 'undefined' ? null : document.getElementById('main-content');
}

function lockPage(): void {
    const main = mainContent();

    if (main === null) {
        return;
    }

    main.setAttribute('inert', '');
    main.setAttribute('aria-hidden', 'true');
    main.classList.add(...LOCK_CLASSES);
}

function unlockPage(): void {
    const main = mainContent();

    if (main === null) {
        return;
    }

    main.removeAttribute('inert');
    main.removeAttribute('aria-hidden');
    main.classList.remove(...LOCK_CLASSES);
}

watch(
    () => sessionState.status,
    (status, previous) => {
        if (status === 'ok') {
            // Unlock BEFORE closing: the dialog hands focus back to the field it was opened
            // over, and an element inside an `inert` subtree cannot take it.
            unlockPage();
            open.value = false;

            return;
        }

        if (status !== previous) {
            open.value = true;
        }
    },
    { immediate: true },
);

/* ------------------------------------------------------------------ the actions */

async function recheck(): Promise<void> {
    if (sessionState.status !== 'ended') {
        return;
    }

    const result = await recheckSession(pageUserId, page.version ?? null);

    if (result === 'live') {
        toast.success("You're signed in again.");
    }

    if (result === 'other-user') {
        // Somebody else is signed in now. No resume, no timer replay: a full reload, and the
        // server decides what (if anything) that person sees here.
        window.location.reload();
    }
}

function signInInNewTab(): void {
    window.open('/login', '_blank', 'noopener');
}

function signInHere(): void {
    window.location.reload();
}

function goHome(): void {
    window.location.assign(sessionState.home ?? '/');
}

function onFocus(): void {
    void recheck();
}

function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') {
        void recheck();
    }
}

onMounted(() => {
    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onVisibilityChange);

    // A layout remounted while signed out mounts a fresh `<main>`: lock it too.
    if (sessionState.status !== 'ok') {
        lockPage();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('focus', onFocus);
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

/* ------------------------------------------------------------------ focus */

function primaryIn(root: ParentNode | null): HTMLElement | null {
    return root?.querySelector<HTMLElement>('[data-session-primary]') ?? null;
}

/**
 * Start on the primary action (it keeps the typed text), not on the first button in DOM order.
 * This is also the moment the page is locked: the dialog's focus scope has just recorded the
 * field the person was in, so the `inert` that follows cannot rob it of where to return.
 */
function onOpenAutoFocus(event: Event): void {
    lockPage();

    const primary = primaryIn(event.target as HTMLElement | null);

    if (primary) {
        event.preventDefault();
        primary.focus();
    }
}

/** A Save refused while this is already up: pull focus back here so the click is answered. */
watch(sessionAttention, () => {
    open.value = true;
    primaryIn(document.querySelector('[data-testid^="session-"][role="dialog"]'))?.focus();
});

/** Neither dialog can be dismissed into the page it has locked: only signing in closes it. */
function onOpenChange(next: boolean): void {
    if (!next && sessionState.status !== 'ok') {
        return;
    }

    open.value = next;
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            v-if="sessionState.status === 'surface'"
            class="sm:max-w-md"
            :show-close-button="false"
            data-testid="session-surface-dialog"
            @open-auto-focus="onOpenAutoFocus"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <UserRoundCog class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    Your access has changed
                </DialogTitle>
                <DialogDescription>
                    Your role was changed while this page was open. Go to your home page to see what you can use now.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" data-session-primary @click="goHome">Go to my home page</Button>
            </DialogFooter>
        </DialogContent>

        <DialogContent
            v-else
            class="sm:max-w-md"
            :show-close-button="false"
            data-testid="session-ended-dialog"
            @open-auto-focus="onOpenAutoFocus"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <LogIn class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    Your session has ended
                </DialogTitle>
                <DialogDescription>
                    Sign in again to carry on. This page stays open, so anything you have typed is still here.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" @click="signInHere">Sign in here</Button>
                <Button type="button" data-session-primary @click="signInInNewTab">
                    <ExternalLink aria-hidden="true" />
                    Sign in in a new tab
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
