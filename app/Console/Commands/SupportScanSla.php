<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScanSupportSla;
use Illuminate\Console\Command;

class SupportScanSla extends Command
{
    protected $signature = 'support:scan-sla';

    protected $description = 'Scan for SLA breaches and warnings, emit events';

    public function handle(): int
    {
        ScanSupportSla::dispatchSync();

        $this->info('SLA scan dispatched');

        return self::SUCCESS;
    }
}