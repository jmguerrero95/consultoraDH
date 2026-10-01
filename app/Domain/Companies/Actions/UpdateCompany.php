<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Events\CompanyUpdated;
use App\Domain\Companies\TaxId;
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

            if (array_key_exists('tax_id', $attributes)) {
                $taxId = TaxId::normalise($attributes['tax_id']);
                $taxId = $taxId === '' ? null : $taxId;

                if ($taxId !== $company->tax_id) {
                    $company->tax_id = $taxId;
                    $company->verification_digit = $taxId === null
                        ? null
                        : TaxId::checkDigit($taxId);
                    $changed[] = 'tax_id';
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
