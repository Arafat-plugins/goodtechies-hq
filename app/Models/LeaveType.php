<?php

namespace App\Models;

use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of leave: Annual, Sick, Emergency, Personal, Unpaid, Other (master prompt Part D §9).
 *
 * Seeded by `LeaveTypeSeeder` and never created by the application — the list is the agency's
 * policy, not its data. Two independent facts hang off it and neither is derived from the
 * other:
 *
 *   - `has_balance` — capped. An employee has a per-type balance and a request for more days
 *     than are left is refused.
 *   - `is_unpaid` — every day of it lands in `leave_requests.unpaid_days`, which is what Phase
 *     9's payroll reads.
 *
 * On the seed only Unpaid is unpaid, and **Other is uncapped and still paid** — which is
 * exactly why these are two columns and not one. See the migration.
 *
 * @property int $id
 * @property string $name
 * @property bool $has_balance
 * @property bool $is_unpaid
 * @property int $position
 */
#[Fillable(['name', 'has_balance', 'is_unpaid', 'position'])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_balance' => 'boolean',
            'is_unpaid' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LeaveBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * The order every picker, balance table and legend offers them in.
     *
     * By `position` and then by name, never by id: the seeder is idempotent, so a type added to
     * a database that already has five would otherwise sort to the bottom of every list in the
     * application for ever.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /**
     * The types an employee holds a balance for. The Admin balance editor's columns.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCapped(Builder $query): void
    {
        $query->where('has_balance', true);
    }

    /**
     * The payload every leave screen reads a type as.
     *
     * `has_balance` travels because the apply form has to say *"no balance — these days are
     * uncapped"* rather than print an empty number beside Unpaid, and `is_unpaid` travels
     * because the same form has to warn that the days will be unpaid **before** somebody sends
     * it, not on the payslip. Both are facts about the type, resolved here once, so no screen
     * infers either from the name.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'has_balance' => (bool) $this->has_balance,
            'is_unpaid' => (bool) $this->is_unpaid,
        ];
    }
}
