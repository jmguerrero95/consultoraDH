<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function refreshTestDatabase(): void
    {
        if (! App::runningInConsole()) {
            return;
        }

        Artisan::call('test:db:fresh', [
            '--force' => true,
        ]);
    }
}
