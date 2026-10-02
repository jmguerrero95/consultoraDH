<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Events\CompanyCreated;
use App\Domain\Companies\TaxIdParts;
use App\Domain\Shared\RecordStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company master record.
 *
 * The NIT is normalised and split here rather than in the model, because the
 * verification digit is a second stored value and the split is a domain decision
 * about how a NIT is read, not something a setter should be guessing at. See
 * `TaxIdParts` for what happens when the caller sends both spellings.
 *
 * An empty NIT is stored as NULL, not as an empty string, so that the partial
 * unique index does not make every NIT-less company a duplicate of the first one.
 */
final class CreateCompany
{
    /**
     * @param  array<string, string|null>  $attributes
     *
     * @throws InvalidTaxId when the NIT is present and is not a NIT
     * @throws InvalidVerificationDigit when a verification digit is not a single digit
     */
    public function execute(array $attributes, User $actor): Company
    {
        $parts = TaxIdParts::from($attributes);
        $taxId = $parts['tax_id'];
        $digit = $parts['verification_digit'];

        // A digit with no NIT is refused here rather than stored as nothing. On create
        // there is no existing NIT for it to belong to, so the only reading left is
        // that the caller sent a field the system would drop: which is a mistake worth
        // reporting, not one to absorb silently. UpdateCompany has no such check,
        // because correcting the digit of a company that already has a NIT is exactly
        // what that field is for.
        if ($taxId === null) {
            TaxIdParts::refuseOrphanDigit(
                is_string($attributes['verification_digit'] ?? null) ? $attributes['verification_digit'] : null
            );
        }

        return DB::transaction(function () use ($attributes, $actor, $taxId, $digit): Company {
            $company = Company::query()->create([
                'legal_name' => $attributes['legal_name'],
                'trade_name' => $attributes['trade_name'] ?? null,
                'tax_id' => $taxId,
                'verification_digit' => $digit,
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'address' => $attributes['address'] ?? null,
                'city' => $attributes['city'] ?? null,
                'department' => $attributes['department'] ?? null,
                'status' => $attributes['status'] ?? RecordStatus::Active->value,
            ]);

            event(new CompanyCreated($company, $actor));

            return $company;
        });
    }
}
