<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // Declared here, not with Pest's ->use(): the guard below must override
    // the trait method, which only works from the class using the trait.
    use RefreshDatabase;

    /**
     * Last line of defence before RefreshDatabase wipes the database: never
     * run on anything but a *_testing database (docs/DECISIONS.md D36).
     *
     * @return void
     */
    protected function beforeRefreshingDatabase()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to refresh database [{$database}]: tests must run on a *_testing database.");
        }
    }
}
