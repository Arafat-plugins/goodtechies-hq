<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, with the schema built by the schema owner (pgsql_migrator / hq_migrator).
 * Tests themselves keep running on the default pgsql connection (hq_app), so they exercise
 * the real runtime grants.
 */
trait RefreshHqDatabase
{
    use RefreshDatabase {
        migrateFreshUsing as refreshDatabaseMigrateFreshUsing;
    }

    /**
     * The parameters that should be used when running "migrate:fresh".
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return array_merge($this->refreshDatabaseMigrateFreshUsing(), [
            '--database' => 'pgsql_migrator',
        ]);
    }
}
