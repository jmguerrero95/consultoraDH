<?php

declare(strict_types=1);

use App\Domain\Affiliations\ManageAffiliations;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditWriteFailed;
use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\ImportPlan;
use App\Domain\Payments\Actions\ManagePayments;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SocialSecurityEntity;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| A01-AUD-01 — a critical write and its trail entry are one unit
|--------------------------------------------------------------------------
|
| The recorder used to swallow every write error, so a payment, an allocation, a
| void, a reversal, an affiliation or an applied import could persist even when
| its mandatory trail entry could not be written.
|
| The failure is forced with a real PostgreSQL constraint on `audit_events`, so
| the tests exercise the actual INSERT and the actual rollback rather than a
| mock that always succeeds.
|
*/

/**
 * Make the database refuse one audit action.
 *
 * A real CHECK constraint, not a mock: the point of the test is that the
 * database itself rejects the trail entry, and that the mutation that was
 * carrying it is undone.
 */
function audit01BlockAction(AuditAction $action): void
{
    DB::statement(sprintf(
        'ALTER TABLE audit_events ADD CONSTRAINT audit01_block_%s CHECK (action <> %s)',
        str_replace('.', '_', $action->value),
        DB::connection()->getPdo()->quote($action->value),
    ));
}
function audit01Count(string $action): int
{
    return AuditEvent::query()->where('action', $action)->count();
}

beforeEach(function (): void {
    // The role matrix comes from the real seeder, so the actor of the audited
    // actions is the same account an operator would be.
    seedRoles();
});

it('reverts the payment when its mandatory trail entry cannot be written', function (): void {
    $employer = a03_employerFor('auditoria-pago');
    $actor = actingAsRole();

    audit01BlockAction(AuditAction::PaymentCreated);

    $before = Payment::query()->count();

    expect(fn () => app(ManagePayments::class)->register(
        $employer['client'],
        [
            'amount_cop' => 250000,
            'received_on' => '2026-10-15',
            'method' => 'bank_transfer',
        ],
        $actor,
    ))->toThrow(AuditWriteFailed::class);

    // The money did not arrive: the row is gone because the trail entry and
    // the payment were one transaction.
    expect(Payment::query()->count())->toBe($before)
        ->and(audit01Count(AuditAction::PaymentCreated->value))->toBe(0);
});

it('reverts the allocation when its mandatory trail entry cannot be written', function (): void {
    $employer = a03_employerFor('auditoria-aplicacion');
    $actor = actingAsRole();

    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);

    audit01BlockAction(AuditAction::PaymentAllocated);

    expect(fn () => app(ManagePayments::class)->allocate($payment, $obligation, 200000, $actor))
        ->toThrow(AuditWriteFailed::class);

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(audit01Count(AuditAction::PaymentAllocated->value))->toBe(0);

    // The debt is still whole: the money was not applied to anything.
    $effective = $obligation->base_amount_cop
        + (int) $obligation->adjustments()->sum('delta_cop');

    expect($effective)->toBe(200000);
});

it('reverts the reversal of an allocation when its trail entry cannot be written', function (): void {
    $employer = a03_employerFor('auditoria-reversion');
    $actor = actingAsRole();

    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);

    $allocation = app(ManagePayments::class)->allocate($payment, $obligation, 200000, $actor);

    audit01BlockAction(AuditAction::PaymentAllocationReversed);

    expect(fn () => app(ManagePayments::class)->reverseAllocation($allocation, 'correccion', $actor))
        ->toThrow(AuditWriteFailed::class);

    // The allocation is still live: the reversal did not happen.
    expect($allocation->fresh()->reversed_at)->toBeNull()
        ->and(audit01Count(AuditAction::PaymentAllocationReversed->value))->toBe(0);
});

it('reverts the void of a payment when its trail entry cannot be written', function (): void {
    $employer = a03_employerFor('auditoria-anulacion');
    $actor = actingAsRole();

    $payment = a03_payablePayment(200000, $employer);

    audit01BlockAction(AuditAction::PaymentVoided);

    expect(fn () => app(ManagePayments::class)->void($payment, 'error de captura', $actor))
        ->toThrow(AuditWriteFailed::class);

    expect($payment->fresh()->voided_at)->toBeNull()
        ->and(audit01Count(AuditAction::PaymentVoided->value))->toBe(0);
});

it('reverts an affiliation when its trail entry cannot be written', function (): void {
    $client = Client::factory()->create();
    $entity = SocialSecurityEntity::factory()->create();
    $actor = actingAsRole();

    audit01BlockAction(AuditAction::AffiliationCreated);

    expect(fn () => app(ManageAffiliations::class)->create(
        client: $client,
        entity: $entity,
        actor: $actor,
        startedOn: new DateTimeImmutable('2026-01-01'),
    ))->toThrow(AuditWriteFailed::class);

    expect($client->affiliations()->count())->toBe(0)
        ->and(audit01Count(AuditAction::AffiliationCreated->value))->toBe(0);
});

it('reverts the application of an import when its trail entry cannot be written', function (): void {
    $import = applicableImport();

    $plan = new ImportPlan(
        $import,
        $import->actions()->orderBy('ordinal')->get()->all(),
        $import->issues()->where('blocking', true)->whereNull('resolved_at')->count(),
    );

    $clientsBefore = Client::query()->count();

    audit01BlockAction(AuditAction::ImportApplied);

    expect(fn () => app(ApplyImportPlan::class)->handle($import, $plan))
        ->toThrow(AuditWriteFailed::class);

    // The batch is not applied and nothing it wrote survived.
    expect($import->fresh()->status->value)->not->toBe('applied')
        ->and(Client::query()->count())->toBe($clientsBefore)
        ->and(audit01Count(AuditAction::ImportApplied->value))->toBe(0);
});

it('keeps an auxiliary event from failing the operation that produced it', function (): void {
    $actor = actingAsRole();

    // `task.created` is informative: losing it must not undo the task. The
    // recorder swallows the failure for auxiliary actions on purpose.
    audit01BlockAction(AuditAction::TaskCreated);

    $response = $this->actingAs($actor)
        ->postJson('/api/tasks', [
            'title' => 'Tarea de prueba de auditoria',
            'description' => 'Descripcion de la tarea de prueba',
            'assigned_to' => $actor->id,
            'priority' => 'normal',
        ]);

    $response->assertCreated();

    expect(OperationalTask::query()->count())->toBe(1)
        ->and(audit01Count(AuditAction::TaskCreated->value))->toBe(0);
});

it('writes one trail entry per critical action, with the subject and the actor', function (): void {
    $employer = a03_employerFor('auditoria-atribucion');
    $actor = actingAsRole();

    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);

    $allocation = app(ManagePayments::class)->allocate($payment, $obligation, 200000, $actor);

    $event = AuditEvent::query()
        ->where('action', AuditAction::PaymentAllocated->value)
        ->latest('id')
        ->firstOrFail();

    expect($event->user_id)->toBe($actor->id)
        // The subject of an allocation is the obligation it pays: that is what
        // an auditor reads when asking "what did this person pay in March".
        ->and($event->subject_type)->toBe(MonthlyObligation::class)
        ->and($event->subject_id)->toBe($obligation->id)
        ->and($event->metadata)->toHaveKey('amount_cop')
        ->and($event->metadata)->toHaveKey('allocation_id', $allocation->id)
        ->and(audit01Count(AuditAction::PaymentAllocated->value))->toBe(1);
});

it('keeps the trail free of secrets and free of duplicates', function (): void {
    $employer = a03_employerFor('auditoria-limpia');
    $actor = actingAsRole();

    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);

    app(ManagePayments::class)->allocate($payment, $obligation, 200000, $actor);

    $events = AuditEvent::query()->where('action', AuditAction::PaymentAllocated->value)->get();

    expect($events)->toHaveCount(1);

    $flat = json_encode($events->toArray());

    expect($flat)->not->toContain('password')
        ->and($flat)->not->toContain('$2y$')
        ->and($flat)->not->toContain('remember_token');
});

it('reports a critical audit failure as an error and never as a silent success', function (): void {
    $employer = a03_employerFor('auditoria-error');
    $actor = actingAsRole();

    audit01BlockAction(AuditAction::PaymentCreated);

    try {
        app(ManagePayments::class)->register(
            $employer['client'],
            [
                'amount_cop' => 250000,
                'received_on' => '2026-10-15',
                'method' => 'bank_transfer',
            ],
            $actor,
        );
    } catch (AuditWriteFailed $failure) {
        expect($failure->action)->toBe(AuditAction::PaymentCreated)
            ->and($failure->getMessage())->toContain('payment.created');

        return;
    }

    $this->fail('The registration was expected to fail when the trail entry could not be written.');
});

it('does not let the blocked constraint leak into the next operation', function (): void {
    $employer = a03_employerFor('auditoria-reanudacion');
    $actor = actingAsRole();

    audit01BlockAction(AuditAction::PaymentCreated);

    try {
        app(ManagePayments::class)->register(
            $employer['client'],
            ['amount_cop' => 250000, 'received_on' => '2026-10-15', 'method' => 'bank_transfer'],
            $actor,
        );
    } catch (AuditWriteFailed) {
        // expected
    }

    // The constraint is dropped explicitly so the rest of the test can prove
    // the recorder writes again as soon as the database allows it.
    DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit01_block_payment_created');

    $payment = app(ManagePayments::class)->register(
        $employer['client'],
        ['amount_cop' => 250000, 'received_on' => '2026-10-15', 'method' => 'bank_transfer'],
        $actor,
    );

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and(audit01Count(AuditAction::PaymentCreated->value))->toBe(1);
});

it('refuses to rewrite a trail entry that is already written', function (): void {
    // A guard on the guard: the recorder writes the catalogue's value, never a
    // free text, and the trail is append-only.
    $event = AuditEvent::query()->create([
        'action' => 'payment.created',
        'created_at' => now(),
    ]);

    expect($event->action)->toBe('payment.created');

    // The model refuses the update and the delete, so the row survives.
    expect($event->update(['action' => 'payment.voided']))->toBeFalse()
        ->and($event->delete())->toBeFalse()
        ->and(AuditEvent::query()->whereKey($event->id)->value('action'))->toBe('payment.created');
});
