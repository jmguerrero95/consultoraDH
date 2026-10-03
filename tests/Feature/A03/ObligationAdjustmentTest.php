<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\AdjustmentRejected;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Receivables\ObligationPresenter;
use App\Models\AuditEvent;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Models\Payment;
use App\Models\PaymentAllocation;

/**
 * Adjustments: the correction ledger, and the two limits that protect it.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

function a03_obligation(int $amount = 200000): MonthlyObligation
{
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    return MonthlyObligation::factory()->forPeriod($period)->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'base_amount_cop' => $amount,
        'due_on' => '2026-11-10',
    ]);
}

// --- Posting ----------------------------------------------------------------

it('posts a discount and reduces what is owed', function (): void {
    $obligation = a03_obligation(200000);

    $adjustment = app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Discount,
        -10000,
        'Descuento acordado con la empresa',
        actingAsRole(),
    );

    expect($adjustment->delta_cop)->toBe(-10000)
        ->and($adjustment->type)->toBe(AdjustmentType::Discount);

    $totals = app(ObligationPresenter::class)->describe($obligation->fresh());

    expect($totals['effective_amount_cop'])->toBe(190000)
        ->and($totals['balance_cop'])->toBe(190000);
});

it('posts a surcharge and increases what is owed', function (): void {
    $obligation = a03_obligation(200000);

    app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Surcharge,
        15000,
        'Recargo por mora del periodo anterior',
        actingAsRole(),
    );

    expect(app(ObligationPresenter::class)->describe($obligation->fresh())['effective_amount_cop'])
        ->toBe(215000);
});

it('adds several adjustments up', function (): void {
    $obligation = a03_obligation(200000);
    $action = app(AdjustObligation::class);

    $action->execute($obligation, AdjustmentType::Discount, -10000, 'Descuento de julio', actingAsRole());
    $action->execute($obligation, AdjustmentType::Surcharge, 5000, 'Recargo de agosto', actingAsRole());
    $action->execute($obligation, AdjustmentType::Correction, -2000, 'Corrección de un dígito', actingAsRole());

    expect(app(ObligationPresenter::class)->describe($obligation->fresh())['effective_amount_cop'])
        ->toBe(193000);
});

it('refuses an adjustment of zero', function (): void {
    $obligation = a03_obligation();

    // A zero adjustment is a comment, not an adjustment: it changes nothing and
    // would still have to be interpreted by whoever reads the balance.
    expect(fn () => app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Correction,
        0,
        'Sin efecto real',
        actingAsRole(),
    ))->toThrow(AdjustmentRejected::class, 'distinto de cero');

    expect(ObligationAdjustment::query()->count())->toBe(0);
});

it('refuses an adjustment with no reason', function (): void {
    $obligation = a03_obligation();

    expect(fn () => app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Discount,
        -1000,
        '   ',
        actingAsRole(),
    ))->toThrow(AdjustmentRejected::class, 'motivo');
});

it('refuses an adjustment that would make the obligation negative', function (): void {
    $obligation = a03_obligation(200000);

    expect(fn () => app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Discount,
        -250000,
        'Un descuento mayor que lo adeudado',
        actingAsRole(),
    ))->toThrow(AdjustmentRejected::class);

    expect(app(ObligationPresenter::class)->describe($obligation->fresh())['effective_amount_cop'])
        ->toBe(200000);
});

it('refuses an adjustment that would create an overpayment', function (): void {
    $obligation = a03_obligation(200000);

    // Something has already been paid against it.
    $payment = Payment::factory()->create([
        'client_id' => $obligation->client_id,
        'amount_cop' => 200000,
    ]);
    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'obligation_id' => $obligation->id,
        'amount_cop' => 200000,
    ]);

    // Reducing the obligation below what is paid would make it negative, which has
    // no representation here. The correct order is to correct the allocation first.
    try {
        app(AdjustObligation::class)->execute(
            $obligation,
            AdjustmentType::Discount,
            -50000,
            'Descuento posterior al pago',
            actingAsRole(),
        );
        $this->fail('the adjustment should have been refused');
    } catch (AdjustmentRejected $e) {
        expect($e->reasonCode)->toBe('would_overpay')
            ->and($e->getMessage())->toContain('sobrepago');
    }

    expect(ObligationAdjustment::query()->count())->toBe(0)
        ->and(app(ObligationPresenter::class)->describe($obligation->fresh())['balance_cop'])
        ->toBe(0);
});

// --- Reversal ---------------------------------------------------------------

it('reverses an adjustment by writing the opposite one', function (): void {
    $obligation = a03_obligation(200000);
    $action = app(AdjustObligation::class);

    $original = $action->execute($obligation, AdjustmentType::Discount, -10000, 'Descuento acordado', actingAsRole());

    $reversal = $action->reverse($original, 'El descuento no aplicaba', actingAsRole());

    // The original row is still there. The ledger shows the correction and its
    // withdrawal; an edited row would leave no trace that one had been needed.
    expect($original->fresh()->exists())->toBeTrue()
        ->and($reversal->delta_cop)->toBe(10000)
        ->and($reversal->type)->toBe(AdjustmentType::Reversal)
        ->and($reversal->reverses_adjustment_id)->toBe($original->id);

    // And the balance is back to where it was before the correction.
    expect(app(ObligationPresenter::class)->describe($obligation->fresh())['effective_amount_cop'])
        ->toBe(200000);
});

it('refuses to reverse the same adjustment twice', function (): void {
    $obligation = a03_obligation();
    $action = app(AdjustObligation::class);

    $original = $action->execute($obligation, AdjustmentType::Surcharge, 15000, 'Recargo válido', actingAsRole());
    $action->reverse($original, 'Anulado por el cliente', actingAsRole());

    // A second reversal would cancel the original twice over and change the balance
    // by an amount nobody chose.
    expect(fn () => $action->reverse($original, 'otra vez', actingAsRole()))
        ->toThrow(AdjustmentRejected::class, 'ya fue revertido');

    expect(ObligationAdjustment::query()->where('reverses_adjustment_id', $original->id)->count())->toBe(1);
});

it('refuses to reverse a reversal', function (): void {
    $obligation = a03_obligation();
    $action = app(AdjustObligation::class);

    $original = $action->execute($obligation, AdjustmentType::Discount, -10000, 'Descuento', actingAsRole());
    $reversal = $action->reverse($original, 'Mal aplicado', actingAsRole());

    expect(fn () => $action->reverse($reversal, 'otra vez', actingAsRole()))
        ->toThrow(AdjustmentRejected::class);
});

it('refuses a reversal with no reason', function (): void {
    $obligation = a03_obligation();
    $action = app(AdjustObligation::class);
    $original = $action->execute($obligation, AdjustmentType::Discount, -10000, 'Descuento', actingAsRole());

    expect(fn () => $action->reverse($original, '  ', actingAsRole()))
        ->toThrow(AdjustmentRejected::class, 'motivo');
});

it('refuses a reversal chosen as an ordinary adjustment', function (): void {
    $obligation = a03_obligation();

    // A reversal is what the system writes when the undo action runs. Accepting it
    // as an ordinary adjustment would put a row in the ledger whose meaning is
    // decided by whether somebody filled in one more field.
    expect(fn () => app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Reversal,
        -1000,
        'Intento de reversión manual',
        actingAsRole(),
    ))->toThrow(AdjustmentRejected::class, 'acción de deshacer');
});

// --- HTTP and audit ---------------------------------------------------------

it('answers a refusal with 422 and a code the interface can use', function (): void {
    $obligation = a03_obligation(200000);

    $this->actingAs(actingAsRole())
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'discount',
            'delta_cop' => -300000,
            'reason' => 'Un descuento que deja la obligación en negativo',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'adjustment_rejected')
        ->assertJsonPath('reason_code', 'would_go_below_zero');
});

it('gates adjusting behind its own permission', function (): void {
    $obligation = a03_obligation();

    // A role that may read obligations and nothing else may not change one.
    $this->actingAs(userWithPermissions(['obligations.view']))
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'discount',
            'delta_cop' => -1000,
            'reason' => 'Un descuento cualquiera',
        ])
        ->assertForbidden();

    expect(ObligationAdjustment::query()->count())->toBe(0);
});

it('records both the adjustment and its reversal in the audit trail', function (): void {
    $obligation = a03_obligation(200000);
    $actor = actingAsRole();
    $action = app(AdjustObligation::class);

    $original = $action->execute($obligation, AdjustmentType::Surcharge, 15000, 'Recargo de prueba', $actor);
    $action->reverse($original, 'No correspondía', $actor);

    $posted = AuditEvent::query()
        ->where('action', 'obligation.adjusted')
        ->where('subject_id', $obligation->id)
        ->firstOrFail();

    $reversed = AuditEvent::query()
        ->where('action', 'obligation.adjustment_reversed')
        ->where('subject_id', $obligation->id)
        ->firstOrFail();

    // The subject is the obligation, so reading one client's trail answers "what
    // happened to this debt" without searching by client.
    expect($posted->subject_type)->toBe(MonthlyObligation::class)
        ->and($posted->metadata)->toMatchArray(['type' => 'surcharge', 'delta_cop' => 15000])
        ->and($posted->metadata['reason'])->toBe('Recargo de prueba')
        ->and($reversed->metadata)->toMatchArray([
            'reverses_adjustment_id' => $original->id,
            'delta_cop' => -15000,
        ])
        ->and($reversed->metadata['reason'])->toBe('No correspondía');
});

it('keeps personal data out of the adjustment audit trail', function (): void {
    $obligation = a03_obligation();

    app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Discount,
        -10000,
        'Descuento acordado',
        actingAsRole(),
    );

    $metadata = AuditEvent::query()->where('action', 'obligation.adjusted')->firstOrFail()->metadata;

    // Identifiers and amounts, not names. A person's name is not needed to answer
    // "what was adjusted", and the trail is read by people who should not be reading
    // the client list.
    // Identifiers and amounts, never a name, a document or an address. A person's
    // name is not needed to answer "what was adjusted", and the trail is read by
    // people who should not be reading the client list.
    expect($metadata)->toHaveKeys(['obligation_id', 'client_id', 'company_id', 'period_id', 'delta_cop', 'reason'])
        ->and($metadata)->not->toHaveKeys(['client_name', 'document_number', 'email'])
        ->and(json_encode($metadata))->not->toContain($obligation->client->fullName())
        ->and(json_encode($metadata))->not->toContain($obligation->client->document_number);
});
