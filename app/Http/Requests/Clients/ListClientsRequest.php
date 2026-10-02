<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Http\Requests\ListQueryRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

final class ListClientsRequest extends ListQueryRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('clients.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'company_id' => ['sometimes', 'nullable', 'integer', Rule::exists('companies', 'id')],
        ]);
    }

    /**
     * The company the list is restricted to, when the caller may ask for it.
     *
     * The filter is itself relationship information: which clients work at a given
     * company. A role without `relationships.view` cannot use it, and saying so is
     * better than quietly ignoring it, which would answer a question the caller
     * asked with a list that has nothing to do with their request.
     */
    public function companyFilter(): ?int
    {
        $companyId = $this->validated('company_id');

        if (! is_numeric($companyId)) {
            return null;
        }

        if (! $this->user()?->can('relationships.view')) {
            throw new AuthorizationException(
                'Filtrar clientes por empresa requiere el permiso relationships.view.'
            );
        }

        return (int) $companyId;
    }
}
