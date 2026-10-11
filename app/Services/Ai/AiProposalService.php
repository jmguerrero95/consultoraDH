<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Models\AiActionProposal;
use App\Models\User;

/**
 * Decides what happens to a proposed write.
 *
 * ## The browser is not trusted with the arguments
 *
 * A confirmation request carries a proposal id and nothing else. The work that
 * runs is the work stored on the proposal when it was made, so a browser that
 * edits the arguments and confirms has edited a record nobody executes. The
 * arguments are validated again here regardless, because a proposal can sit for
 * minutes and what it referred to can change in that time.
 *
 * ## Exactly once
 *
 * The claim is a conditional update: `pending` to `confirmed`, with the
 * conversation, the tool and the expiry in the `where`. If a second confirmation
 * arrives — a double click, a retry after a timeout — the row no longer reads as
 * `pending`, the update matches nothing, and the work is refused rather than
 * performed a second time.
 */
class AiProposalService
{
    public function __construct(
        private readonly AiToolRegistry $registry,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Execute a proposal, once.
     *
     * @return array<string, mixed> the tool's result
     *
     * @throws AiProposalNotConfirmable
     */
    public function confirm(AiActionProposal $proposal, User $user): array
    {
        // Someone else's proposal is not confirmable, and saying so generically
        // avoids confirming whether the id exists.
        if ((int) $proposal->user_id !== (int) $user->id) {
            throw new AiProposalNotConfirmable('Esa propuesta no le pertenece.');
        }

        $claimed = AiActionProposal::query()
            ->whereKey($proposal->id)
            ->where('status', AiActionProposal::PENDING)
            ->where('expires_at', '>', now())
            ->update([
                'status' => AiActionProposal::CONFIRMED,
                'executed_at' => now(),
                'updated_at' => now(),
            ]);

        // Zero rows matched: already confirmed, already rejected, or expired
        // while it waited. Either way the work must not happen again.
        if ($claimed === 0) {
            throw new AiProposalNotConfirmable('Esta propuesta ya no está disponible para confirmar.');
        }

        $proposal->refresh();

        try {
            $result = $this->registry->executeWrite($proposal->tool, $proposal->arguments, $user);
        } catch (\Throwable $e) {
            // The proposal is spent either way: a failed write is not a pending
            // one, and leaving it confirmable would invite a second attempt at
            // the same failure.
            $proposal->update(['status' => AiActionProposal::REJECTED]);

            $this->audit->record(AuditAction::AiProposalFailed, $user, [
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
                'error' => $e->getMessage(),
            ], null, $proposal);

            throw $e;
        }

        $this->audit->record(AuditAction::AiProposalConfirmed, $user, [
            'proposal_id' => $proposal->id,
            'tool' => $proposal->tool,
            'result' => array_map(
                static fn ($value): string => is_scalar($value) ? (string) $value : gettype($value),
                $result
            ),
        ], null, $proposal);

        return $result;
    }

    /**
     * Decline a proposal. The work is not performed and never will be.
     */
    public function reject(AiActionProposal $proposal, User $user): AiActionProposal
    {
        if ((int) $proposal->user_id !== (int) $user->id) {
            throw new AiProposalNotConfirmable('Esa propuesta no le pertenece.');
        }

        $proposal->update(['status' => AiActionProposal::REJECTED]);

        $this->audit->record(AuditAction::AiProposalRejected, $user, [
            'proposal_id' => $proposal->id,
            'tool' => $proposal->tool,
        ], null, $proposal);

        return $proposal;
    }
}
