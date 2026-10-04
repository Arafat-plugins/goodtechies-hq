<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import CategoryFormDialog from '@/Components/Finance/CategoryFormDialog.vue';
import CategorySideCard from '@/Components/Finance/CategorySideCard.vue';
import type { FinanceCategory, FinanceCategoryKind } from '@/Components/Finance/finance';
import { financeRoutes } from '@/Components/Finance/finance';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * The category list — the names money is filed under, on both sides of the ledger (Part D §13:
 * *"Categories (seeded, Admin-editable)"*).
 *
 * **One screen with two readers.** Reading this list is `finance.view` and changing it is
 * `settings.manage` (decision 8-12), so the Accountant opens the same page and sees the same
 * two lists with no controls on them, while an Admin sees Add on each side and Rename and
 * Delete on each row. Nothing in this file compares a role: `permissions.can_create` and each
 * row's own `permissions` block are the server's answers, per record (decisions 2-28, 2-31).
 *
 * That matters more here than it looks. The Accountant lives in the pickers these names fill —
 * a category list they could not read would make both finance forms unusable — and Part D
 * reserves the list itself for the Admin because renaming *Website* changes how every historic
 * report reads. Both halves of that are said in one screen.
 *
 * **A category anything is filed under has no Delete control**, because `ON DELETE RESTRICT`
 * would refuse it: the count is printed beside every name so the absence has a visible reason.
 * The refusal still exists — `FinanceService::deleteCategory()` throws it as a sentence with
 * the number in it, the controller flashes that sentence, and the whole path is a race the
 * database wins. The control is the courtesy; the constraint is the promise.
 *
 * The side of the ledger is not editable anywhere on this screen, for anybody. Moving a
 * category across would move every record under it without touching one finance row.
 */
defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

const props = defineProps<{
    income: FinanceCategory[];
    expense: FinanceCategory[];
    permissions: { can_create: boolean };
}>();

/* ------------------------------------------------------------------ add / rename */

const formOpen = ref(false);
const formKind = ref<FinanceCategoryKind>('income');
const editing = ref<FinanceCategory | null>(null);

/**
 * The control that opened the dialog, so focus goes back to it when the dialog closes.
 *
 * These controls are plain buttons rather than menu items — see `CategorySideCard` — so unlike
 * decision 5-20's case the element is still mounted and reka's own focus restore works. This is
 * belt and braces for the one case where it does not: a category that has just been deleted
 * takes its buttons with it, and then the Add control on that side is the nearest thing that
 * still exists.
 */
const opener = ref<string | null>(null);

function add(kind: FinanceCategoryKind): void {
    opener.value = `finance-categories-add-${kind}`;
    formKind.value = kind;
    editing.value = null;
    formOpen.value = true;
}

function rename(category: FinanceCategory): void {
    opener.value = `Rename ${category.name}`;
    formKind.value = category.kind;
    editing.value = category;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    restoreFocus();
}

/* -------------------------------------------------------------------- deleting */

const pending = ref<FinanceCategory | null>(null);
const deleting = ref(false);

function askRemove(category: FinanceCategory): void {
    opener.value = `Delete ${category.name}`;
    pending.value = category;
}

function cancelRemove(): void {
    pending.value = null;
    restoreFocus();
}

function confirmRemove(): void {
    const category = pending.value;

    if (!category || deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(financeRoutes.categories.destroy(category.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            pending.value = null;
            // The row's own buttons may have gone with it; the side's Add control has not.
            opener.value = `finance-categories-add-${category.kind}`;
            restoreFocus();
        },
    });
}

/**
 * Focus whatever opened the overlay: an element id where the control has one, otherwise the
 * visible button carrying that accessible name — the card renders one list and a phone and a
 * desktop see the same one, but the lookup is written the careful way regardless.
 */
function restoreFocus(): void {
    const name = opener.value;
    opener.value = null;

    if (!name) {
        return;
    }

    void nextTick(() => {
        const byId = document.getElementById(name);

        if (byId) {
            byId.focus();

            return;
        }

        [...document.querySelectorAll<HTMLElement>('button[aria-label]')]
            .find((el) => el.getAttribute('aria-label') === name && el.offsetParent !== null)
            ?.focus();
    });
}

const total = computed(() => props.income.length + props.expense.length);
</script>

<template>
    <Head title="Finance categories" />

    <PageShell
        title="Finance categories"
        description="What income and expenses are filed under. Every finance form offers these lists in this order, and the monthly rollup reports a line per name."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Categories' }]"
    >
        <div class="flex min-w-0 flex-col gap-4">

            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                <CategorySideCard
                    kind="income"
                    :categories="income"
                    :can-create="permissions.can_create"
                    @add="add"
                    @rename="rename"
                    @remove="askRemove"
                />
                <CategorySideCard
                    kind="expense"
                    :categories="expense"
                    :can-create="permissions.can_create"
                    @add="add"
                    @rename="rename"
                    @remove="askRemove"
                />
            </div>
        </div>

        <CategoryFormDialog
            :open="formOpen"
            :kind="formKind"
            :category="editing"
            @close="closeForm"
        />

        <!--
            The confirmation. It names the category, and it says what is true: this is a plain
            delete of a row nothing is filed under, so nothing moves and nothing is lost — which
            is the opposite of the ledger's own delete dialog, and worth saying rather than
            reusing the frightening wording.
        -->
        <Dialog :open="pending !== null" @update:open="(open: boolean) => { if (!open) { cancelRemove(); } }">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Delete the “{{ pending?.name }}” {{ pending?.kind === 'income' ? 'income' : 'expense' }} category?
                    </DialogTitle>
                    <DialogDescription>
                        Nothing is filed under it, so no figure changes and no record is touched — it simply
                        stops being offered on the {{ pending?.kind === 'income' ? 'income' : 'expense' }} form.
                        This cannot be undone, but the same name can be added again.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="deleting" @click="cancelRemove">
                        Keep it
                    </Button>
                    <Button type="button" variant="destructive" :disabled="deleting" @click="confirmRemove">
                        <Trash2 aria-hidden="true" />
                        {{ deleting ? 'Deleting…' : 'Delete category' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageShell>
</template>
