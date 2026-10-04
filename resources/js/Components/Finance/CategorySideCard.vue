<script setup lang="ts">
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import type { FinanceCategory, FinanceCategoryKind } from '@/Components/Finance/finance';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';

/**
 * One side of the category list: the names money is filed under, in picker order.
 *
 * ## Read-only is the same component, not a second one
 *
 * Reading this list is `finance.view` and changing it is `settings.manage` (decision 8-12), so
 * an Accountant sees every category here with no controls and an Admin sees the same list with
 * an Add button and two per row. That difference is drawn entirely from each row's own
 * `permissions` block and from `canCreate`, both resolved on the server — there is not one
 * comparison against a role in this file (decisions 2-28, 2-31), which is why the Accountant's
 * view and the Admin's are one component and cannot drift apart.
 *
 * ## A category in use has no Remove control
 *
 * `ON DELETE RESTRICT` refuses deleting a category anything is filed under, so offering the
 * control there would be offering a button whose only outcome is an error (DESIGN.md §5 rule
 * 11). `usage_count` is on every row for exactly this, and the count is printed beside the name
 * so the absence of the control has a visible reason rather than looking like a permission the
 * reader does not have. The service still catches the case — the count is a race with anybody
 * recording income in the same millisecond, and the database is what wins it.
 *
 * ## The row controls are buttons, not a `⋯` menu
 *
 * Deliberately. The menu-item-opens-a-dialog pattern drops focus to `<body>` when the dialog
 * closes, because reka restores focus to a menu item the menu has already unmounted (decision
 * 5-20). A plain button is still there when the dialog closes, so focus goes back to the thing
 * that was pressed and the bug has nothing to inherit. Two controls per row do not need a menu
 * to hold them.
 *
 * Each is icon-only, so each carries an `aria-label` naming the category and a tooltip printing
 * the same words.
 */
const props = defineProps<{
    kind: FinanceCategoryKind;
    categories: FinanceCategory[];
    canCreate: boolean;
}>();

const emit = defineEmits<{
    add: [kind: FinanceCategoryKind];
    rename: [category: FinanceCategory];
    remove: [category: FinanceCategory];
}>();

const isIncome = computed(() => props.kind === 'income');

const title = computed(() => (isIncome.value ? 'Income categories' : 'Expense categories'));

const description = computed(() =>
    isIncome.value
        ? 'What a payment in is filed under. Every income form offers this list, in this order, and the monthly rollup reports a line per name.'
        : 'What a payment out is filed under. Every expense form offers this list, in this order, and the monthly rollup reports a line per name.',
);

/** The id the Add control carries, so a page can put focus back on it without a template ref. */
const addButtonId = computed(() => `finance-categories-add-${props.kind}`);

function usage(category: FinanceCategory): string {
    const count = category.usage_count ?? 0;

    if (count === 0) {
        return 'None yet';
    }

    return count === 1 ? '1 record' : `${count} records`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
            <CardDescription>{{ description }}</CardDescription>
        </CardHeader>
        <CardContent class="flex min-w-0 flex-col gap-4">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ title }}, in the order every picker offers them.</caption>
                <thead>
                    <tr class="border-b border-border">
                        <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                            Category
                        </th>
                        <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                            Filed under it
                        </th>
                        <th scope="col" class="py-2 text-right font-medium text-muted-foreground">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories" :key="category.id" class="border-b border-border/60">
                        <th scope="row" class="py-2 pr-3 text-left font-normal break-words">
                            {{ category.name }}
                        </th>
                        <!--
                            The blast radius, in words rather than a bare number — it is the
                            reason the Remove control is or is not there, and a reason nobody
                            can read is not one (DESIGN.md §5 rule 6).
                        -->
                        <td class="py-2 pr-3 tabular-nums text-muted-foreground">{{ usage(category) }}</td>
                        <td class="py-2 text-right">
                            <TooltipProvider>
                                <div class="flex items-center justify-end gap-1">
                                    <Tooltip v-if="category.permissions.can_update">
                                        <TooltipTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                :aria-label="`Rename ${category.name}`"
                                                @click="emit('rename', category)"
                                            >
                                                <Pencil aria-hidden="true" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Rename {{ category.name }}</TooltipContent>
                                    </Tooltip>

                                    <Tooltip v-if="category.permissions.can_delete && !category.in_use">
                                        <TooltipTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                :aria-label="`Delete ${category.name}`"
                                                @click="emit('remove', category)"
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Delete {{ category.name }}</TooltipContent>
                                    </Tooltip>
                                </div>
                            </TooltipProvider>
                        </td>
                    </tr>
                </tbody>
            </table>


            <div v-if="canCreate">
                <Button :id="addButtonId" type="button" variant="outline" @click="emit('add', kind)">
                    <Plus aria-hidden="true" />
                    Add {{ isIncome ? 'income' : 'expense' }} category
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
