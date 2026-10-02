<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Company;

/**
 * Editing a company must not lose part of its identity.
 *
 * The bug this file exists for: the two columns are stored apart, the edit form
 * loaded the bare number, and sending it back with an unrelated field made the
 * update read as "the number, with no digit", which cleared a digit that was
 * perfectly good. Editing a telephone number erased part of a company's tax
 * identity, and nothing said so.
 *
 * The five cases below are the semantics the domain now guarantees. The first one
 * is the bug.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

function companyWithDigit(string $base = '900123456', string $digit = '3'): Company
{
    return Company::factory()->create([
        'tax_id' => $base,
        'verification_digit' => $digit,
    ]);
}

it('keeps the digit when an unrelated field is edited', function (): void {
    $company = companyWithDigit();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['phone' => '6049999999'])
        ->assertOk()
        ->assertJsonPath('company.phone', '6049999999');

    $reloaded = $company->fresh();

    expect($reloaded->tax_id)->toBe('900123456')
        ->and($reloaded->verification_digit)->toBe('3')
        ->and($reloaded->taxIdLabel())->toBe('900.123.456-3');
});

it('keeps the digit when the form sends the bare number back', function (): void {
    $company = companyWithDigit();

    // Exactly what the edit form used to send: the number, with no digit anywhere.
    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", [
            'legal_name' => 'Constructora Andina S.A.S.',
            'tax_id' => '900123456',
            'phone' => '6041111111',
        ])
        ->assertOk();

    $reloaded = $company->fresh();

    expect($reloaded->tax_id)->toBe('900123456')
        ->and($reloaded->verification_digit)->toBe('3');
});

it('changes only the digit when the digit alone is sent', function (): void {
    $company = companyWithDigit();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['verification_digit' => '7'])
        ->assertOk()
        ->assertJsonPath('company.tax_id', '900123456')
        ->assertJsonPath('company.verification_digit', '7')
        ->assertJsonPath('company.tax_id_label', '900.123.456-7');
});

it('drops the digit when the number changes and no digit is sent', function (): void {
    $company = companyWithDigit();

    // The stored digit described the old number. The new one is genuinely unknown,
    // and inventing a digit would be worse than an empty one.
    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['tax_id' => '800555444'])
        ->assertOk()
        ->assertJsonPath('company.tax_id', '800555444')
        ->assertJsonPath('company.verification_digit', null);

    expect($company->fresh()->verification_digit)->toBeNull();
});

it('refuses two different digits in one request', function (): void {
    $company = companyWithDigit();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", [
            'tax_id' => '900123456-4',
            'verification_digit' => '5',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'conflicting_verification_digit')
        ->assertJsonValidationErrors('verification_digit');

    // And nothing was written while deciding.
    expect($company->fresh()->verification_digit)->toBe('3')
        ->and($company->fresh()->tax_id)->toBe('900123456');
});

it('accepts the same digit written in both places', function (): void {
    $company = companyWithDigit();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", [
            'tax_id' => '900123456-9',
            'verification_digit' => '9',
        ])
        ->assertOk()
        ->assertJsonPath('company.verification_digit', '9');
});

it('clears the digit on purpose when the request says so', function (): void {
    $company = companyWithDigit();

    // An explicit null is a statement, not an absence: this is how an operator says
    // "this digit is empty" as opposed to "I did not mean to mention it".
    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", [
            'tax_id' => '900123456',
            'verification_digit' => null,
        ])
        ->assertOk()
        ->assertJsonPath('company.verification_digit', null);
});

it('records which of the two values changed', function (): void {
    $company = companyWithDigit();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['verification_digit' => '8'])
        ->assertOk();

    // The audit trail says which column moved, because a digit corrected on its own
    // is a different event from a number changed.
    $changed = AuditEvent::query()
        ->where('action', 'company.updated')
        ->firstOrFail()
        ->metadata;

    expect(json_encode($changed))->toContain('verification_digit')
        ->not->toContain('"tax_id"');
});

// --- Syntax ------------------------------------------------------------------

it('refuses a NIT that is not a NIT, with 422 and never a server error', function (string $value): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_id');
})->with([
    'letters' => 'ABC123',
    'separator in the wrong place' => '900-12-34',
    'two digits after the separator' => '900123456-33',
    'a letter where a digit belongs' => '900123456-X',
    'punctuation' => '!!',
    'thirteen digits' => '1234567890123',
]);

it('accepts the spellings people actually write', function (string $value, string $base, ?string $digit): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => $value]))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', $base)
        ->assertJsonPath('company.verification_digit', $digit);
})->with([
    'digits only' => ['900123456', '900123456', null],
    'with periods' => ['900.123.456', '900123456', null],
    'with spaces' => ['900 123 456', '900123456', null],
    'digits and digit' => ['900123456-3', '900123456', '3'],
    'periods and digit' => ['900.123.456-3', '900123456', '3'],
]);

it('refuses the same malformed values on update, not only on create', function (string $value): void {
    $company = companyWithDigit();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['tax_id' => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_id');

    // The stored identity is untouched by a request that was refused.
    expect($company->fresh()->tax_id)->toBe('900123456');
})->with([
    'letters' => 'ABC123',
    'two digits after the separator' => '900123456-33',
    'punctuation' => '900.123.456-3-4',
]);

// --- Strict parsing, not a tolerant repair -----------------------------------

it('refuses a NIT whose hyphens are misplaced or repeated', function (string $value): void {
    // Each of these is transformed into something valid by the tolerant parser the
    // value must not travel through, which is how a malformed request used to be
    // accepted and stored as a different NIT than the one that was sent.
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_id');

    expect(Company::query()->count())->toBe(0);
})->with([
    'leading hyphen' => '-900123456-3',
    'trailing hyphen' => '900123456-3-',
    'two leading hyphens' => '--900123456-3',
    'trailing double hyphen' => '900123456--',
    'double hyphen in the middle' => '900123-456-3',
    'a padded digit' => '900123456-03',
    'a hyphen between every digit' => '9-0-0-1-2-3-4-5-6',
]);

it('accepts the spellings the strict parser describes', function (string $value, string $base, ?string $digit): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => $value]))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', $base)
        ->assertJsonPath('company.verification_digit', $digit);
})->with([
    'digits only' => ['900123456', '900123456', null],
    'with periods' => ['900.123.456', '900123456', null],
    'with spaces' => ['900 123 456', '900123456', null],
    'digits and digit' => ['900123456-3', '900123456', '3'],
    'periods and digit' => ['900.123.456-3', '900123456', '3'],
    'surrounded by spaces' => ['  900123456-3  ', '900123456', '3'],
]);

// --- One rule for create and edit --------------------------------------------

it('refuses two contradictory digits on create as well as on update', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => '900123456-4',
            'verification_digit' => '5',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'conflicting_verification_digit')
        ->assertJsonValidationErrors('verification_digit');

    // Nothing was created, so the refusal happened before the write.
    expect(Company::query()->count())->toBe(0);
});

it('accepts the same digit written in both places on create', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => '900123456-4',
            'verification_digit' => '4',
        ]))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', '900123456')
        ->assertJsonPath('company.verification_digit', '4');
});

it('behaves the same way for a company that does not exist yet', function (): void {
    // The same input, once on create and once on update of an existing company,
    // answered identically. Two behaviours for one input would depend on whether
    // the company happened to be there already.
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => '900123456-4',
            'verification_digit' => '5',
        ]))
        ->assertStatus(422);

    $existing = companyWithDigit('800111222', '2');

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$existing->id}", [
            'tax_id' => '800111222-4',
            'verification_digit' => '5',
        ])
        ->assertStatus(422);

    expect($existing->fresh()->tax_id)->toBe('800111222')
        ->and($existing->fresh()->verification_digit)->toBe('2');
});
