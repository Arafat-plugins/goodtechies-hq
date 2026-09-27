<?php

namespace App\Exceptions;

use App\Models\FinanceCategory;
use App\Support\FinanceCategoryKind;
use RuntimeException;

/**
 * Thrown when a finance record — or the change being made to it — is not one this application
 * can accept.
 *
 * The sibling of `TaskStateException`, `LeaveStateException`, `AttendanceStateException` and
 * `MeetingStateException`, and distinct from `AuthorizationException` for the same reason all
 * four are: the Accountant is perfectly entitled to record income, this particular row is
 * simply not income. A refusal here comes back as a flash error on the form it came from,
 * never as a 403.
 *
 * ## Every case below is ALSO a database constraint, and that is not redundancy
 *
 * The constraint is the promise — it holds against a seeder, a console command, a later CSV
 * import and somebody at `psql`. This class is the sentence a person reads. Reaching the
 * database with a Payroll category on an income row would otherwise produce
 * `SQLSTATE[23503] … violates foreign key constraint "income_category_id_foreign"` on a form
 * about September's invoices.
 */
class FinanceStateException extends RuntimeException
{
    /**
     * An income was filed under an expense category, or the other way round.
     *
     * The database refuses this through the composite foreign key on `(category_id,
     * category_kind)`; see `2026_10_05_0002_create_income_table.php`.
     */
    public static function categoryKindMismatch(FinanceCategoryKind $expected, FinanceCategory $category): self
    {
        return new self(sprintf(
            '“%s” is an %s category, so it cannot be used on an %s record.',
            $category->name,
            $category->kind?->label() ?? 'unknown',
            $expected->label(),
        ));
    }

    /**
     * A category somebody has filed money under cannot be deleted.
     *
     * The database refuses it (`ON DELETE RESTRICT`). The count is in the sentence because it
     * is the blast radius, and because the next question is always "how many?".
     */
    public static function categoryInUse(FinanceCategory $category, int $records): self
    {
        return new self(sprintf(
            '“%s” is still on %d finance %s, so it cannot be deleted. Rename it, or move those records first.',
            $category->name,
            $records,
            $records === 1 ? 'record' : 'records',
        ));
    }

    /**
     * A category in use cannot change sides. `ON UPDATE RESTRICT` on the composite foreign key
     * is what makes that true; this is the sentence.
     *
     * It is refused even when the category is NOT in use, because "which side of the ledger is
     * this?" is the one thing about a category that is never a correction — a category created
     * on the wrong side has nothing filed under it yet, and deleting it and making the right one
     * is one click and leaves no ambiguity behind.
     */
    public static function categoryKindIsImmutable(FinanceCategory $category): self
    {
        return new self(sprintf(
            '“%s” is an %s category and cannot be moved to the other side of the ledger. Delete it and create the one you meant.',
            $category->name,
            $category->kind?->label() ?? 'unknown',
        ));
    }

    /**
     * Zero, or a negative.
     *
     * The sign is the table, not the number: `income` and `expenses` each hold a positive
     * magnitude and the table it is in says which direction it goes. `amount > 0` is a CHECK on
     * both.
     */
    public static function amountNotPositive(string $amount): self
    {
        return new self(sprintf(
            'A finance record needs an amount greater than zero; “%s” is not one.',
            $amount,
        ));
    }
}
