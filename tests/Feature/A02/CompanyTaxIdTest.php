<?php

declare(strict_types=1);

use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\Actions\UpdateCompany;
use App\Domain\Companies\InvalidTaxId;
use App\Domain\Companies\InvalidVerificationDigit;
use App\Domain\Companies\TaxIdParts;
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

// --- The domain refuses on its own, not only through the form request -------

it('refuses a malformed tax id when the domain action is called directly', function (string $value): void {
    // No form request, no validation, no HTTP. This is how the importer, the
    // assistant and a maintenance command will reach the same code, and the point
    // is that the refusal does not depend on the caller having checked first.
    expect(fn () => app(CreateCompany::class)->execute(
        ['legal_name' => 'Empresa directa S.A.S.', 'tax_id' => $value],
        actingAsRole(),
    ))->toThrow(InvalidTaxId::class);

    // Nothing was written, and certainly not a repaired version of the input.
    expect(Company::query()->count())->toBe(0);
})->with([
    'leading hyphen' => '-900123456-3',
    'trailing hyphen' => '900123456-3-',
    'two leading hyphens' => '--900123456-3',
    'trailing double hyphen' => '900123456--',
    'double hyphen inside' => '900123-456-3',
    'a padded digit' => '900123456-03',
    'letters' => 'ABC123',
]);

it('refuses a malformed tax id on update too, through the domain action', function (): void {
    $company = companyWithDigit('800111222', '2');

    expect(fn () => app(UpdateCompany::class)->execute(
        $company,
        ['tax_id' => '800111222--'],
        actingAsRole(),
    ))->toThrow(InvalidTaxId::class);

    // The stored NIT and digit are what they were.
    expect($company->fresh()->tax_id)->toBe('800111222')
        ->and($company->fresh()->verification_digit)->toBe('2');
});

it('never turns a malformed value into a different tax id', function (): void {
    // The failure mode this replaces: `-900123456-3` repaired into `900123456` with a
    // digit, stored, and reported as accepted. Every one of these would have become a
    // valid, different NIT under the tolerant parser.
    foreach (['-900123456-3', '900123456-3-', '900123456--', '--900123456-3'] as $value) {
        try {
            app(CreateCompany::class)->execute(
                ['legal_name' => 'Reparada S.A.S.', 'tax_id' => $value],
                actingAsRole(),
            );
        } catch (InvalidTaxId) {
            // Expected.
        }
    }

    expect(Company::query()->pluck('tax_id')->filter()->all())->toBe([]);
});

it('accepts the spellings the strict parser describes when called directly', function (): void {
    $company = app(CreateCompany::class)->execute(
        ['legal_name' => 'Directa S.A.S.', 'tax_id' => '900.123.456-3'],
        actingAsRole(),
    );

    expect($company->tax_id)->toBe('900123456')
        ->and($company->verification_digit)->toBe('3');
});

// --- A verification digit with no NIT ----------------------------------------

it('refuses a verification digit without a tax id on create', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => null,
            'verification_digit' => '3',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_tax_id')
        ->assertJsonValidationErrors('tax_id');

    // The digit was never silently dropped on the way to a success response.
    expect(Company::query()->count())->toBe(0);
});

it('refuses an orphan digit through the domain action as well', function (): void {
    expect(fn () => app(CreateCompany::class)->execute(
        ['legal_name' => 'Huerfana S.A.S.', 'verification_digit' => '3'],
        actingAsRole(),
    ))->toThrow(InvalidTaxId::class, 'dígito de verificación');

    expect(Company::query()->count())->toBe(0);
});

it('accepts a company with no tax id at all', function (): void {
    // The distinction that matters: no NIT and no digit is an honest record of an
    // unknown tax identity, which the data quality layer reports. A digit without a
    // NIT is not that.
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => null]))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', null)
        ->assertJsonPath('company.verification_digit', null);
});

it('still allows a digit alone on update, because that is the correction it exists for', function (): void {
    $company = companyWithDigit('800111222', '2');

    // The company already has a NIT, so the digit being sent alone is the whole point
    // of the operation: correcting the digit recorded against it.
    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['verification_digit' => '7'])
        ->assertOk()
        ->assertJsonPath('company.tax_id', '800111222')
        ->assertJsonPath('company.verification_digit', '7');
});

it('still allows removing the digit on update', function (): void {
    $company = companyWithDigit('800111222', '2');

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['verification_digit' => null])
        ->assertOk()
        ->assertJsonPath('company.tax_id', '800111222')
        ->assertJsonPath('company.verification_digit', null);
});

// --- The verification digit is checked by the domain, not only by the form ---

it('refuses a verification digit that is not one decimal digit, on create', function (mixed $digit): void {
    // Called through the application service, with no form request anywhere. This is
    // the path the importer, the assistant and the maintenance commands will use, and
    // the rule used to live only in a form request regex that none of them pass
    // through.
    expect(fn () => app(CreateCompany::class)->execute(
        ['legal_name' => 'Cifra inválida S.A.S.', 'tax_id' => '901234567', 'verification_digit' => $digit],
        actingAsRole(),
    ))->toThrow(InvalidVerificationDigit::class);

    expect(Company::query()->count())->toBe(0);
})->with([
    'two digits' => '12',
    'a sign' => '-1',
    'a letter' => 'X',
    'a padded digit' => '03',
    'an empty string' => '',
    'whitespace padded' => ' 3 ',
    'a decimal point' => '3.5',
]);

it('refuses a verification digit that is not one decimal digit, on update', function (mixed $digit): void {
    $company = companyWithDigit('800111222', '2');

    expect(fn () => app(UpdateCompany::class)->execute(
        $company,
        ['verification_digit' => $digit],
        actingAsRole(),
    ))->toThrow(InvalidVerificationDigit::class);

    // The stored digit is untouched.
    expect($company->fresh()->verification_digit)->toBe('2');
})->with([
    'two digits' => '12',
    'a sign' => '-1',
    'a letter' => 'X',
    'a padded digit' => '03',
]);

it('refuses a verification digit that is not a string or a small integer', function (): void {
    // Not a dataset. Pest spreads an array dataset value into positional arguments,
    // so `['3']` as a dataset entry arrives as `'3'` and silently tests the wrong
    // thing, which is how a list-shaped digit would have looked covered.
    foreach ([true, false, ['3'], 3.5, 10, -1, new stdClass] as $digit) {
        expect(fn () => TaxIdParts::requireSingleDigit($digit))
            ->toThrow(InvalidVerificationDigit::class);
    }
});

it('accepts every single digit, and the integer form of one', function (): void {
    foreach (['0', '1', '5', '9', 7] as $accepted) {
        expect(fn () => TaxIdParts::requireSingleDigit($accepted))->not->toThrow(InvalidVerificationDigit::class);
    }

    // And the whole range, as strings, because that is how a form sends them.
    for ($digit = 0; $digit <= 9; $digit++) {
        expect(fn () => TaxIdParts::requireSingleDigit((string) $digit))->not->toThrow(InvalidVerificationDigit::class);
    }

    // Absent is absent, not invalid.
    expect(fn () => TaxIdParts::requireSingleDigit(null))->not->toThrow(InvalidVerificationDigit::class);
});

it('stores a valid digit supplied on its own', function (): void {
    $company = app(CreateCompany::class)->execute(
        ['legal_name' => 'Cifra válida S.A.S.', 'tax_id' => '901234567', 'verification_digit' => '8'],
        actingAsRole(),
    );

    expect($company->verification_digit)->toBe('8');
});

it('answers an invalid digit over HTTP with 422, not a crash', function (): void {
    // Over HTTP the form request's regex refuses this first, so the answer is a
    // validation error and carries no `code`. That is the layering working: the
    // cheapest check answers, and the domain rule behind it exists for the callers
    // that never pass through the form.
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => '902234567',
            'verification_digit' => '12',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('verification_digit')
        ->assertJsonPath('errors.verification_digit.0', 'El dígito de verificación debe ser un solo número.');

    expect(Company::query()->where('tax_id', '902234567')->exists())->toBeFalse();
});

it('answers an invalid digit on update over HTTP with 422', function (): void {
    $company = companyWithDigit('800111222', '2');

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", ['verification_digit' => 'XX'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('verification_digit');

    expect($company->fresh()->verification_digit)->toBe('2');
});
