<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Events\CompanyUpdated;
use App\Domain\Companies\TaxIdUpdate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edits a company.
 *
 * As with the client, the status has its own operations, because deactivating a
 * company while clients are still attached is a decision with rules rather than
 * a field edit.
 */
final class UpdateCompany
{
    /**
     * @param  array<string, string|null>  $attributes
     */
    public function execute(Company $company, array $attributes, User $actor): Company
    {
        $changed = [];

        return DB::transaction(function () use ($company, $attributes, $actor, &$changed): Company {
            foreach (['legal_name', 'trade_name', 'email', 'phone', 'address', 'city', 'department'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $incoming = $attributes[$field];
                $incoming = $incoming === null || trim($incoming) === '' ? null : trim($incoming);

                if ($incoming === $company->{$field}) {
                    continue;
                }

                $company->{$field} = $incoming;
                $changed[] = $field;
            }

            // Both fields are resolved together, because sending one of them says
            // something about the other. An edit of an unrelated field that happens
            // to carry the bare number must not clear the digit: see `TaxIdUpdate`.
            if (array_key_exists('tax_id', $attributes) || array_key_exists('verification_digit', $attributes)) {
                $parts = TaxIdUpdate::resolve($company, $attributes);
                $taxId = $parts['tax_id'];
                $digit = $parts['verification_digit'];

                if ($taxId !== $company->tax_id) {
                    $company->tax_id = $taxId;
                    $changed[] = 'tax_id';
                }

                // Recorded separately so the audit trail says which one moved: a
                // digit changed on its own is a correction of an uncertain value.
                if ($digit !== $company->verification_digit) {
                    $company->verification_digit = $digit;
                    $changed[] = 'verification_digit';
                }
            }

            if ($changed !== []) {
                $company->save();

                event(new CompanyUpdated($company, $actor, $changed));
            }

            return $company->refresh();
        });
    }
}
