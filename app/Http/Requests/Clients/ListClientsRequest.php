<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Http\Requests\ListQueryRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

/**
 * The client list, and the one filter that needs a second permission.
 *
 * ## The filter is checked before it is validated
 *
 * `company_id` is relationship information: it answers "which clients work at this
 * company". The rule for it is `exists`, which queries the `companies` table, and
 * the permission for it was checked afterwards, in `companyFilter()`.
 *
 * That ordering turns the filter into a way of reading the company table one bit at
 * a time. A caller without `relationships.view` sending `company_id=1` got a 403,
 * and the same caller sending `company_id=999999999` got a 422 with a validation
 * message. Two different answers to the same request, and the only thing that
 * differed was whether the company existed. The filter was not usable, so it must
 * not be informative either.
 *
 * The decision therefore happens in `authorize()`, which Laravel calls before
 * `rules()`. Nothing has been validated and no query has run when it is made, so an
 * unauthorised caller is refused identically whether the identifier is real, made
 * up, or not a number at all.
 *
 * A blank filter is ordinary `clients.view` access: asking for the whole list is not
 * asking about anyone's employment.
 */
final class ListClientsRequest extends ListQueryRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        if (! ($this->user()?->can('clients.view') ?? false)) {
            return false;
        }

        if ($this->suppliedCompanyId() !== null && ! ($this->user()?->can('relationships.view') ?? false)) {
            // Thrown rather than returned as false, so the response says why. The
            // caller already has `clients.view`; what they are missing is named.
            throw new AuthorizationException(
                'Filtrar clientes por empresa requiere el permiso relationships.view.'
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Only ever reached by a caller who already holds `relationships.view`,
            // so this query cannot answer a question they were not allowed to ask.
            'company_id' => ['sometimes', 'nullable', 'integer', Rule::exists('companies', 'id')],
        ]);
    }

    /**
     * The company id exactly as it arrived, or null when none was supplied.
     *
     * Read from the raw input on purpose. `validated()` would only have a value
     * after the `exists` query has already run, which is the thing being avoided.
     * A blank string counts as absent: an empty select is not a filter.
     */
    private function suppliedCompanyId(): string|int|null
    {
        $value = $this->input('company_id') ?? $this->query('company_id');

        if ($value === null || is_array($value)) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * The company the list is restricted to.
     *
     * Permission was settled in `authorize()` and the identifier was validated in
     * `rules()`, so this only has to turn the value into an integer. It reads
     * `validated()` now, which is safe for the first time it is used here: by the
     * time this runs, the caller has been authorised.
     */
    public function companyFilter(): ?int
    {
        $companyId = $this->validated('company_id');

        return is_numeric($companyId) ? (int) $companyId : null;
    }
}
