<?php

namespace App\Support;

/**
 * The vocabulary `employees.employment_type` is written in (master prompt Part D §20).
 *
 * The column is a plain string with a `full_time` default and no CHECK behind it, and until
 * Phase 12 nothing wrote it but `EmployeeFactory` and the seeded team — so the four words below
 * are not a schema change, they are the list the one screen that writes the column offers.
 *
 * It exists for the reason `Weekday` exists: three places need the same vocabulary — the
 * select's options, `StoreEmployeeRequest`'s `Rule::in`, and the word the employee record
 * prints — and three literal arrays is how one of them ends up spelling `fulltime`.
 *
 * It is deliberately NOT a contract about pay, hours or notice. Part H §1 keeps HR out of
 * scope; this says how somebody is engaged and nothing follows from it in code.
 */
enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Intern = 'intern';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full time',
            self::PartTime => 'Part time',
            self::Contract => 'Contract',
            self::Intern => 'Intern',
        };
    }
}
