<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let `notifications.type` hold the three attendance-correction types (polish 029).
     *
     * Decision 3-6 again: the constraint is rewritten from the enum, so the client's database —
     * created long before these types — accepts them. Re-running it is a no-op.
     */
    public function up(): void
    {
        $this->applyCheck(NotificationType::values());
    }

    public function down(): void
    {
        $this->applyCheck(array_values(array_filter(
            NotificationType::values(),
            fn (string $value): bool => ! str_starts_with($value, 'attendance.correction_'),
        )));
    }

    /**
     * @param  list<string>  $types
     */
    private function applyCheck(array $types): void
    {
        $list = implode(', ', array_map(fn (string $value): string => "'".$value."'", $types));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_is_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_is_known CHECK (type IN ({$list}))");
    }
};
