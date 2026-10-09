<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reports\ReportScheduleRunner;
use Illuminate\Console\Command;

final class RunReportSchedulesCommand extends Command
{
    protected $signature = 'reports:run-schedules';

    protected $description = 'Generate the due report schedules, idempotently.';

    public function handle(ReportScheduleRunner $runner): int
    {
        $count = $runner->runDue();

        $this->info("{$count} schedule(s) processed.");

        return self::SUCCESS;
    }
}
