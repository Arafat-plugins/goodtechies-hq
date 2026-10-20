<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee bonuses: an Eid, annual, performance or other bonus for one employee, with its own
 * amount, currency, payment date, reason and status (pending → approved → paid, or cancelled).
 *
 * An approved bonus whose payment date falls in a payroll month is linked to that month's
 * period (`payroll_period_id`), and `BonusService::syncPeriodFor()` writes the sum of the
 * linked bonuses into `payroll_items.bonus`. So the item's bonus is derived, never typed.
 *
 * Every existing `payroll_items.bonus > 0` is backfilled here as one `custom` bonus record of
 * the same amount, linked to the same period, so no figure on an item changes.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        $schema->create('employee_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('title', 120)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('payment_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'payment_date']);
            $table->index('payroll_period_id');
            $table->index(['status', 'payment_date']);
        });

        $db->statement(
            "ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_type_is_known CHECK (type IN ('eid','annual','performance','reward','custom'))",
        );
        $db->statement(
            "ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_status_is_known CHECK (status IN ('pending','approved','paid','cancelled'))",
        );
        $db->statement('ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_amount_is_positive CHECK (amount > 0)');
        $db->statement("ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_currency_is_a_code CHECK (currency ~ '^[A-Z]{3}$')");
        $db->statement(
            "ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_custom_has_a_title CHECK ((type <> 'custom') OR (title IS NOT NULL AND length(trim(title)) > 0))",
        );
        $db->statement(
            "ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_cancelled_has_a_time CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))",
        );

        $setting = strtoupper(trim((string) $db->table('settings')->where('key', 'currency')->value(DB::raw("value #>> '{}'"))));
        $currency = preg_match('/^[A-Z]{3}$/', $setting) === 1 ? $setting : 'BDT';

        $db->insert(
            "INSERT INTO employee_bonuses
                (employee_id, payroll_period_id, type, title, amount, currency, payment_date, status, approved_at, created_at, updated_at)
             SELECT i.employee_id, i.payroll_period_id, 'custom', 'Bonus entered on payroll', i.bonus, ?, p.month,
                    CASE WHEN p.status = 'paid' THEN 'paid' ELSE 'approved' END, now(), now(), now()
               FROM payroll_items i
               JOIN payroll_periods p ON p.id = i.payroll_period_id
              WHERE i.bonus > 0",
            [$currency],
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('employee_bonuses');
    }
};
