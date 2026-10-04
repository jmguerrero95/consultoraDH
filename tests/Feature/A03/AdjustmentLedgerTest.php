<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\AdjustmentRejected;
use App\Domain\Billing\AdjustmentType;
use App\Models\ObligationAdjustment;

/**
 * A03-R1 §18, §19 and §20: the adjustment ledger, its contract, and its sign semantics.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

// --- §18: the response contract ----------------------------------------------

it('publishes the adjustment list under the envelope the interface reads', function (): void {
    $actor = a03_admin();
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs($actor)
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'correction',
            'delta_cop' => -15000,
            'reason' => 'Se corrigió un valor duplicado en la factura.',
        ])
        ->assertCreated();

    // `items`, not `adjustments`. The backend has always published `items` and
    // `adjustments_total_cop`; the interface asked for `adjustments`, so the history modal
    // received undefined on a request that had succeeded and reported "no fue posible
    // cargar los ajustes" for a server that had answered perfectly.
    $list = $this->actingAs($actor)
        ->getJson("/api/obligations/{$obligation->id}/adjustments")
        ->assertOk()
        ->json();

    expect($list)->toHaveKey('items')
        ->and($list)->toHaveKey('adjustments_total_cop')
        ->and($list)->not->toHaveKey('adjustments')
        ->and($list['items'])->toHaveCount(1)
        ->and($list['adjustments_total_cop'])->toBe(-15000);
});

// --- §19: reversal is a row, not a marker ------------------------------------

it('reports a reversal through the ledger, not through columns that do not exist', function (): void {
    $actor = a03_admin();
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs($actor)
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'correction',
            'delta_cop' => -30000,
            'reason' => 'Valor corregido tras revisar la factura.',
        ])
        ->assertCreated();

    // Read through the endpoint, because the derived state is what the response publishes:
    // the columns do not exist on the table, so asking the model for them proves nothing.
    $before = $this->actingAs($actor)
        ->getJson("/api/obligations/{$obligation->id}/adjustments")
        ->assertOk()
        ->json('items.0');

    // Live.
    expect($before['is_active'])->toBeTrue()
        ->and($before['is_reversed'])->toBeFalse()
        ->and($before['can_reverse'])->toBeTrue()
        ->and($before['id'])->toBeInt();

    $beforeId = $before['id'];

    $this->actingAs($actor)
        ->postJson("/api/obligation-adjustments/{$beforeId}/reverse", [
            'reason' => 'La corrección era incorrecta y se anula.',
            'confirm' => true,
        ])
        // 201: a reversal writes a **new row**, so it is a creation and not an update of the
        // original. The published status is unchanged by A03-R1.
        ->assertStatus(201);

    $after = $this->actingAs($actor)
        ->getJson("/api/obligations/{$obligation->id}/adjustments")
        ->assertOk()
        ->json('items');

    expect($after)->toHaveCount(2);

    [$originalRow, $reversalRow] = $after;

    // The original now says it has been undone, and says **which row** undid it.
    expect($originalRow['is_reversed'])->toBeTrue()
        ->and($originalRow['reversed_by_adjustment_id'])->toBe($reversalRow['id'])
        ->and($originalRow['reversal_reason'])->toBe('La corrección era incorrecta y se anula.')
        ->and($originalRow['reversal_created_at'])->not->toBeNull()
        ->and($originalRow['is_active'])->toBeFalse();

    // No second reversal is offered: the original is undone, and the reversal row is not
    // itself reversible.
    expect($originalRow['can_reverse'])->toBeFalse()
        ->and($reversalRow['can_reverse'])->toBeFalse();

    // The reversal row points back at what it undoes.
    expect($reversalRow['is_reversal'])->toBeTrue()
        ->and($reversalRow['reverses_adjustment_id'])->toBe($originalRow['id'])
        ->and($reversalRow['is_reversed'])->toBeFalse();

    // The effective sum is back where it started: -30000 and +30000 cancel.
    expect($after[0]['delta_cop'] + $after[1]['delta_cop'])->toBe(0);
});

it('does not offer a second reversal for an adjustment that has one', function (): void {
    $actor = a03_admin();
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs($actor)->postJson("/api/obligations/{$obligation->id}/adjustments", [
        'type' => 'discount',
        'delta_cop' => -10000,
        'reason' => 'Descuento comercial acordado.',
    ])->assertStatus(201);

    $adjustment = ObligationAdjustment::query()->where('obligation_id', $obligation->id)->firstOrFail();

    $this->actingAs($actor)->postJson("/api/obligation-adjustments/{$adjustment->id}/reverse", [
        'reason' => 'El descuento no aplicaba a este cliente.',
        'confirm' => true,
    ])->assertStatus(201);

    // A second reversal is refused by the domain, and the screen is told beforehand.
    $this->actingAs($actor)->postJson("/api/obligation-adjustments/{$adjustment->id}/reverse", [
        'reason' => 'Intento de revertir dos veces la misma corrección.',
        'confirm' => true,
    ])->assertStatus(422);

    expect(ObligationAdjustment::query()->where('obligation_id', $obligation->id)->count())->toBe(2);
});

// --- §20: the type and its direction ------------------------------------------

it('publishes the selectable types with the direction each one accepts', function (): void {
    $this->actingAs(a03_admin())
        ->getJson('/api/obligation-adjustments/vocabulary')
        ->assertOk()
        ->assertJsonPath('types.0.value', 'correction')
        ->assertJsonPath('types.0.direction', 'either')
        ->assertJsonPath('types.1.value', 'discount')
        ->assertJsonPath('types.1.direction', 'decrease')
        ->assertJsonPath('types.2.value', 'surcharge')
        ->assertJsonPath('types.2.direction', 'increase');

    // `credit` is visible. The interface's own list omitted it, so the type the API accepted
    // was not the type the screen offered.
    $values = $this->actingAs(a03_admin())
        ->getJson('/api/obligation-adjustments/vocabulary')
        ->json('types.*.value');

    expect($values)->toContain('credit')
        // And `reversal` is never selectable: it is what the system writes.
        ->and($values)->not->toContain('reversal');
});

it('refuses a discount that would increase what is owed', function (): void {
    $obligation = a03_payable(200000, '2026-10');

    expect(fn () => app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Discount,
        50000,
        'Un descuento que en realidad suma.',
        actingAsRole(),
    ))->toThrow(AdjustmentRejected::class, 'reducir');
})->with([
    'discount positive' => ['discount', 50000, 'reducir'],
    'credit positive' => ['credit', 50000, 'reducir'],
    'surcharge negative' => ['surcharge', -50000, 'aumentar'],
]);

it('refuses the same combination through the API with a field error', function (string $type, int $delta, string $must): void {
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs(a03_admin())
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => $type,
            'delta_cop' => $delta,
            'reason' => 'Una combinación que la etiqueta no describe.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delta_cop');

    expect(ObligationAdjustment::query()->count())->toBe(0);
})->with([
    'discount positive' => ['discount', 50000, 'reducir'],
    'credit positive' => ['credit', 50000, 'reducir'],
    'surcharge negative' => ['surcharge', -50000, 'aumentar'],
]);

it('accepts a correction in either direction', function (int $delta): void {
    $obligation = a03_payable(200000, '2026-10');

    $adjustment = app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Correction,
        $delta,
        'Una corrección que mueve la cantidad.',
        actingAsRole(),
    );

    expect($adjustment->delta_cop)->toBe($delta);
})->with([
    'reducing' => [-25000],
    'increasing' => [25000],
]);

it('refuses `reversal` as user input', function (): void {
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs(a03_admin())
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'reversal',
            'delta_cop' => -1000,
            'reason' => 'Una reversión escrita a mano.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('refuses money with centavos rather than storing a rounded amount', function (mixed $delta): void {
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs(a03_admin())
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'correction',
            'delta_cop' => $delta,
            'reason' => 'Un importe con centavos que este sistema no representa.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delta_cop');

    // `235000.50` stored as `235000` would lose fifty pesos silently and look like success.
    expect(ObligationAdjustment::query()->count())->toBe(0);
})->with([
    'string with centavos' => ['235000.50'],
    'float' => [235000.7],
    'text' => ['50 000 abc'],
]);

it('refuses an unknown adjustment type with a 422 rather than a ValueError', function (): void {
    $obligation = a03_payable(200000, '2026-10');

    $this->actingAs(a03_admin())
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'descuento',
            'delta_cop' => -1000,
            'reason' => 'Un tipo escrito en español que no existe en la API.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('keeps the effective amount consistent with the sign the type allows', function (): void {
    $obligation = a03_payable(200000, '2026-10');
    $adjust = app(AdjustObligation::class);

    $adjust->execute($obligation, AdjustmentType::Discount, -20000, 'Descuento comercial.', actingAsRole());
    expect($obligation->refresh()->balance())->toBe(180000);

    $adjust->execute($obligation->refresh(), AdjustmentType::Surcharge, 15000, 'Recargo por mora.', actingAsRole());
    expect($obligation->refresh()->balance())->toBe(195000);

    $adjust->execute($obligation->refresh(), AdjustmentType::Credit, -5000, 'Nota de crédito.', actingAsRole());
    expect($obligation->refresh()->balance())->toBe(190000);

    $adjust->execute($obligation->refresh(), AdjustmentType::Correction, 5000, 'Ajuste menor.', actingAsRole());
    expect($obligation->refresh()->balance())->toBe(195000);
});
