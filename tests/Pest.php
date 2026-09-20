<?php

use Tests\Concerns\RefreshHqDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Permissions tests run on Tests\TestCase and refresh the
| PostgreSQL test database (goodtechies_hq_test) for every test. The schema
| is built as hq_migrator; the tests run as hq_app. Unit tests run on the
| plain PHPUnit test case and never touch the database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshHqDatabase::class)
    ->in('Feature', 'Permissions');
