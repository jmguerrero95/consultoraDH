<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Carbon;

/**
 * The server's reading of one Spanish sentence.
 *
 * Every field is something the interpreter could determine from the text. A
 * missing date is missing, not defaulted: the bot asks for it rather than
 * inventing "tomorrow at nine". The value object is also what keeps the write
 * path honest — the proposal is created from these values and nothing from the
 * model or the chat is trusted afterwards.
 */
final readonly class TelegramInterpretation
{
    /**
     * @param  string|null  $dateScope  today | tomorrow | overdue | null
     * @param  string|null  $sheetStatus  unpaid | paid | cancelled | null
     */
    public function __construct(
        public TelegramIntent $intent,
        public string $query = '',
        public ?string $title = null,
        public ?Carbon $dueOn = null,
        public ?Carbon $reminderAt = null,
        public ?string $period = null,
        public ?string $sheetStatus = null,
        public ?string $dateScope = null,
        public bool $globalScope = false,
        public ?int $documentId = null,
        public bool $sendDocument = false,
        public bool $pendingOnly = false,
        public bool $approvedOnly = false,
        public ?string $company = null,
        public bool $ambiguousTime = false,
    ) {}

    public function isWrite(): bool
    {
        return $this->intent->isWrite();
    }

    /**
     * Whether the write has everything the plan requires.
     *
     * A reminder needs an executable instant; a task may stand without a due
     * date. The check lives here so the bot refuses an ambiguous instruction
     * before persisting a proposal.
     */
    public function writeIsComplete(): bool
    {
        return match ($this->intent) {
            TelegramIntent::CreateReminder => $this->title !== null
                && $this->title !== ''
                && $this->reminderAt !== null
                && $this->dueOn !== null,
            TelegramIntent::CreateTask => $this->title !== null && $this->title !== '',
            default => false,
        };
    }
}
