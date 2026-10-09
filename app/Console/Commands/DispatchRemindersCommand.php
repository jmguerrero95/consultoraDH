<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\ReminderDispatcher;
use Illuminate\Console\Command;

final class DispatchRemindersCommand extends Command
{
    protected $signature = 'operations:dispatch-reminders';

    protected $description = 'Dispatch due task reminders idempotently.';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue();

        $this->info("{$count} reminder(s) dispatched.");

        return self::SUCCESS;
    }
}
