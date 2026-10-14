<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An expense can now be assigned to a project and flagged billable to the client, the way
 * FreshBooks and QuickBooks treat a cost (decision 8-17b, superseding 8-17). The link is the
 * same optional, unscoped `project_id` income already has (8-9); `billable` marks a cost the
 * client pays back (a domain renewal charged on, a VPS for one client's site).
 *
 * The expense categories also gain `VPS` and `Domain`, inserted only when missing, at the end
 * of the expense list, so an install that already has them is left alone.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NEW_EXPENSE_CATEGORIES = ['VPS', 'Domain'];

    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        Schema::connection($this->getConnection())->table('expenses', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('billable')->default(false);
            $table->index('project_id');
        });

        foreach (self::NEW_EXPENSE_CATEGORIES as $name) {
            $exists = $db->table('finance_categories')
                ->where('kind', 'expense')
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            $position = (int) $db->table('finance_categories')
                ->where('kind', 'expense')
                ->max('position');

            $now = now();

            $db->table('finance_categories')->insert([
                'kind' => 'expense',
                'name' => $name,
                'position' => $position + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        // Only UNUSED VPS/Domain categories are removed. Whether a row was created by up() or
        // already existed before it cannot be told apart afterwards, so an install that already
        // had these categories keeps them on rollback only if an expense uses them; an unused
        // one is removed either way.
        $db->table('finance_categories')
            ->where('kind', 'expense')
            ->whereIn('name', self::NEW_EXPENSE_CATEGORIES)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('expenses')
                    ->whereColumn('expenses.category_id', 'finance_categories.id');
            })
            ->delete();

        Schema::connection($this->getConnection())->table('expenses', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('billable');
        });
    }
};
