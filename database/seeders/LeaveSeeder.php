<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\TrackingMode;
use Illuminate\Database\Seeder;

/**
 * The six leave types (Part D §9), and a starting balance for everybody on the capped four.
 *
 * ## The types are policy, not data
 *
 * Annual, Sick, Emergency and Personal **with** balances; Unpaid and Other **without**. Only
 * Unpaid is unpaid — **Other is uncapped and still paid**, which is why `has_balance` and
 * `is_unpaid` are two independent columns rather than one flag read two ways.
 *
 * `updateOrCreate` on the name, so a re-seed brings an existing database in line instead of
 * failing on the unique index or inventing a seventh type. `position` is set here because it is
 * the order every picker in the application offers them in and it must not be the id: a type
 * added later to a database that already has five would otherwise sort to the bottom for ever.
 *
 * ## The balances are a starting point an Admin edits
 *
 * Part D §9 is explicit that balances are *"seeded per employee by Admin; no accrual logic in
 * MVP"*, so this seeder writes a plausible opening number and **never touches one that already
 * exists**: `firstOrCreate`, not `updateOrCreate`. The launcher seeds on every start, and a
 * re-seed that silently put Yaseen's Annual leave back to 15 after he had taken a week would
 * undo an Admin's decision — the same trap decision C-6 records for dragged card positions.
 *
 * Everybody gets one, the Accountant included: Part C §1 gives *Apply for own leave* to every
 * role, and a balance of nothing would make the only person in the company with no schedule
 * also the only person who could not book Annual leave.
 */
class LeaveSeeder extends Seeder
{
    /**
     * Name, capped, unpaid, and the opening balance for a capped type.
     *
     * @var list<array{name: string, has_balance: bool, is_unpaid: bool, opening: int}>
     */
    private const TYPES = [
        ['name' => 'Annual', 'has_balance' => true, 'is_unpaid' => false, 'opening' => 15],
        ['name' => 'Sick', 'has_balance' => true, 'is_unpaid' => false, 'opening' => 10],
        ['name' => 'Emergency', 'has_balance' => true, 'is_unpaid' => false, 'opening' => 5],
        ['name' => 'Personal', 'has_balance' => true, 'is_unpaid' => false, 'opening' => 3],
        ['name' => 'Unpaid', 'has_balance' => false, 'is_unpaid' => true, 'opening' => 0],
        ['name' => 'Other', 'has_balance' => false, 'is_unpaid' => false, 'opening' => 0],
    ];

    public function run(): void
    {
        $capped = [];

        foreach (self::TYPES as $position => $type) {
            $row = LeaveType::updateOrCreate(
                ['name' => $type['name']],
                [
                    'has_balance' => $type['has_balance'],
                    'is_unpaid' => $type['is_unpaid'],
                    'position' => $position,
                ],
            );

            if ($type['has_balance']) {
                $capped[(int) $row->getKey()] = $type['opening'];
            }
        }

        $employees = Employee::query()
            ->whereIn('tracking_mode', [
                TrackingMode::OfficeAttendance->value,
                TrackingMode::RemoteTimer->value,
                TrackingMode::None->value,
            ])
            ->get();

        foreach ($employees as $employee) {
            foreach ($capped as $typeId => $opening) {
                LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->getKey(), 'leave_type_id' => $typeId],
                    ['balance_days' => $opening],
                );
            }
        }
    }
}
