<?php

namespace App\Support;

/**
 * Which side of the ledger a finance category belongs to (master prompt Part D §13, §20:
 * `finance_categories (kind[income|expense], name)`).
 *
 * ## Why this is an enum and the CATEGORIES are a table
 *
 * Part D §13 gives two fixed lists of category names, and Phase 8's screen list calls them
 * *"Categories (seeded, **Admin-editable**)"*. Editable is the whole of the argument: a name
 * the Admin can rename, add to or retire is data, so it lives in `finance_categories`.
 *
 * The KIND is not editable by anybody. There are two sides to a ledger and there will not be a
 * third — an application that grew a `kind = 'transfer'` would need a second amount column and
 * a different rollup, which is a feature and not a row. So the kind is an enum, it backs a
 * CHECK on `finance_categories.kind`, and it is the referenced half of the composite foreign
 * key that stops a Payroll *expense* category being used as income. See the migrations.
 *
 * ## The CHECK hazard, stated here because this enum is what backs it
 *
 * `finance_categories_kind_is_known` is generated from `values()` at the moment the migration
 * runs (decisions 3-6 and 7-12 — this has already bitten four times). **Adding a case to this
 * enum therefore needs a migration that widens that constraint**, or the feature works on every
 * database built by `migrate:fresh` and fails on the client's, which was built at Phase 2.
 *
 * There is no plausible third case, which is precisely why the note is here: the day somebody
 * thinks of one, this paragraph is what they will read first.
 */
enum FinanceCategoryKind: string
{
    /** Money in. Part D §13's list: Maintenance, SEO, Website, Other. */
    case Income = 'income';

    /**
     * Money out. Part D §13's list: Payroll, Office, Hosting, Software, Marketing, Utilities,
     * Operations, Project Cost, Other.
     */
    case Expense = 'expense';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    /**
     * The one table a category of this kind may be used from.
     *
     * Not a convenience: it is the statement the composite foreign key in each migration makes
     * in SQL, written once in PHP so a service refusal and a constraint violation are talking
     * about the same rule rather than about two rules that happen to agree today.
     */
    public function table(): string
    {
        return match ($this) {
            self::Income => 'income',
            self::Expense => 'expenses',
        };
    }
}
