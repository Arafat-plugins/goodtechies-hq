<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { BellRing, X } from "@lucide/vue";
import { onBeforeUnmount, onMounted, ref } from "vue";
import { Button } from "@/Components/ui/button";
import { deviceState, pushSupported, turnOn } from "@/lib/push";
import { toast } from "@/lib/toast";

/**
 * Polish 016: "when any notification or message comes and the employee or admin is not in the
 * goodERP tab, show a popup". The popup itself is the existing Web Push path (`sw.js` shows it,
 * `PushService` sends it); what was missing is that nobody found the switch on the Profile page.
 *
 * So every signed-in person whose device is not on yet gets this small card in the top-right
 * corner, once per page load and never again for three days after "Not now". "Turn on" asks the
 * browser (its own Allow dialog), subscribes this device, and shows a sample popup so the person
 * sees exactly what will appear. Nothing shows when the browser cannot do push, when the server
 * has no VAPID key, when notifications are blocked, or on the Profile page (it has the full card).
 */

const SNOOZE_KEY = "hq.notify-prompt.snoozed-until";
const SNOOZE_MS = 3 * 24 * 60 * 60 * 1000;
const SHOW_AFTER_MS = 2500;

const page = usePage();
const open = ref(false);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

function vapidKey(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="vapid-public-key"]')
            ?.content ?? ""
    );
}

function snoozed(): boolean {
    try {
        return (
            Number(window.localStorage.getItem(SNOOZE_KEY) ?? 0) > Date.now()
        );
    } catch {
        return false;
    }
}

function snooze(): void {
    try {
        window.localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_MS));
    } catch {
        // Blocked storage: the card simply comes back on the next full load.
    }
}

onMounted(() => {
    if (
        !page.props.auth.user ||
        !pushSupported() ||
        vapidKey() === "" ||
        snoozed()
    ) {
        return;
    }

    if (
        Notification.permission === "denied" ||
        page.component === "Shared/Profile"
    ) {
        return;
    }

    timer = setTimeout(async () => {
        try {
            open.value = (await deviceState()) === "off";
        } catch {
            open.value = false;
        }
    }, SHOW_AFTER_MS);
});

onBeforeUnmount(() => clearTimeout(timer));

function later(): void {
    snooze();
    open.value = false;
}

async function enable(): Promise<void> {
    busy.value = true;

    try {
        const state = await turnOn(vapidKey());

        if (state === "on") {
            open.value = false;
            toast.success("Popups are on for this device");
            const registration = await navigator.serviceWorker.ready;
            await registration.showNotification("goodERP", {
                body: "New messages and notifications will pop up like this when goodERP is in the background.",
                icon: "/brand/icon-192.png",
                badge: "/brand/badge-96.png",
                tag: "gooderp-sample",
            });
        } else if (state === "blocked") {
            open.value = false;
            toast.error(
                "Notifications are blocked for goodERP. Allow them from the lock icon next to the address bar.",
            );
        } else {
            later();
        }
    } catch {
        toast.error(
            "Couldn't turn popups on. Check your connection and try again.",
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
        leave-to-class="opacity-0"
    >
        <section
            v-if="open"
            aria-labelledby="notify-prompt-title"
            class="fixed top-16 right-4 left-4 z-50 flex gap-3 rounded-xl bg-popover p-4 text-popover-foreground shadow-overlay sm:left-auto sm:w-96"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-tint text-primary"
            >
                <BellRing class="size-5" aria-hidden="true" />
            </span>
            <div class="flex min-w-0 flex-1 flex-col gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <h2 id="notify-prompt-title" class="text-sm font-semibold">
                        Get a popup for new messages
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        See messages and notifications on your screen even when
                        goodERP isn't the open tab.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="busy"
                        @click="enable"
                    >
                        <BellRing aria-hidden="true" />
                        Turn on
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="busy"
                        @click="later"
                        >Not now</Button
                    >
                </div>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="-mt-1 -mr-1 size-8 shrink-0"
                aria-label="Close"
                :disabled="busy"
                @click="later"
            >
                <X aria-hidden="true" />
            </Button>
        </section>
    </Transition>
</template>
