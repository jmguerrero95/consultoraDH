<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Services\Ai\AiCapabilityCatalog;
use App\Services\Ai\AiTurnSupervisor;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic rule that cuts an unproductive tool loop.
 *
 * A07 stability block: a manual turn recorded client_search + client_summary,
 * four rounds and an empty answer. The supervisor is ordinary code with
 * counters, not a second model, so it can be pinned exactly here: a productive
 * round resets the streak, two unproductive rounds in a row force synthesis,
 * and a write proposal always resets it because the turn ends there.
 */
final class AiTurnSupervisorTest extends TestCase
{
    private function toolResult(string $tool, mixed $output): array
    {
        return ['tool' => $tool, 'output' => $output];
    }

    public function test_a_fresh_turn_does_not_force_synthesis(): void
    {
        $this->assertFalse((new AiTurnSupervisor)->shouldForceSynthesis());
    }

    public function test_one_unproductive_round_is_tolerated(): void
    {
        $supervisor = new AiTurnSupervisor;

        // A dedupe note and an error are both "nothing new".
        $supervisor->recordRound([
            $this->toolResult('client_search', ['note' => 'Ya ejecutaste esta misma llamada en este turno.']),
        ]);

        $this->assertFalse($supervisor->shouldForceSynthesis());
    }

    public function test_two_unproductive_rounds_in_a_row_force_synthesis(): void
    {
        $supervisor = new AiTurnSupervisor;

        $supervisor->recordRound([$this->toolResult('client_search', ['error' => 'boom'])]);
        $supervisor->recordRound([$this->toolResult('client_summary', 'Error: no disponible')]);

        $this->assertTrue($supervisor->shouldForceSynthesis());
        $this->assertSame(2, $supervisor->stalledRounds());
    }

    public function test_a_productive_round_resets_the_streak(): void
    {
        $supervisor = new AiTurnSupervisor;

        $supervisor->recordRound([$this->toolResult('client_search', ['error' => 'boom'])]);
        // Real data returns the streak to zero.
        $supervisor->recordRound([$this->toolResult('client_summary', ['name' => 'Empresa'])]);
        $supervisor->recordRound([$this->toolResult('client_search', ['error' => 'boom'])]);

        $this->assertFalse($supervisor->shouldForceSynthesis());
        $this->assertSame(1, $supervisor->stalledRounds());
    }

    public function test_repeating_discovery_is_not_progress(): void
    {
        $supervisor = new AiTurnSupervisor;

        $found = ['capabilities' => [['name' => 'client_search']], 'note' => 'disponibles'];

        // The first lookup is progress: it hands the model the catalogue.
        $supervisor->recordRound([$this->toolResult(AiCapabilityCatalog::DISCOVERY_TOOL, $found)]);
        // Every repeat returns the same list the model already holds, so the
        // rounds that follow are unproductive: two in a row force synthesis.
        $supervisor->recordRound([$this->toolResult(AiCapabilityCatalog::DISCOVERY_TOOL, $found)]);
        $supervisor->recordRound([$this->toolResult(AiCapabilityCatalog::DISCOVERY_TOOL, $found)]);

        $this->assertTrue($supervisor->shouldForceSynthesis());
    }

    public function test_a_write_proposal_always_resets_the_streak(): void
    {
        $supervisor = new AiTurnSupervisor;

        $supervisor->recordRound([$this->toolResult('client_search', ['error' => 'boom'])]);
        $supervisor->recordRound([$this->toolResult('create_operational_task', ['proposal_created' => true])], true);

        // The turn ends at a proposal, so the supervisor must not claim a stall.
        $this->assertFalse($supervisor->shouldForceSynthesis());
        $this->assertSame(0, $supervisor->stalledRounds());
    }
}
