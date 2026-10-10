<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DispatchAutomations;
use Illuminate\Console\Command;

class AutomationsDispatch extends Command
{
    protected $signature = 'automations:dispatch';

    protected $description = 'Dispatch automation rules for execution';

    public function handle(): int
    {
        DispatchAutomations::dispatchSync();

        $this->info('Automations dispatch dispatched');

        return self::SUCCESS;
    }
}