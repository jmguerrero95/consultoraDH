<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Models\SupportSlaEvent;

class SupportSlaEventEmitted extends AuditableEvent
{
    public function __construct(
        public SupportSlaEvent $slaEvent
    ) {}

    public function action(): string
    {
        return 'support.sla.'.$this->slaEvent->level;
    }

    public function subject(): object
    {
        return $this->slaEvent;
    }
}
