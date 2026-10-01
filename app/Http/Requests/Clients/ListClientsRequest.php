<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Http\Requests\ListQueryRequest;
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

    public function companyFilter(): ?int
    {
        $companyId = $this->validated('company_id');

        return is_numeric($companyId) ? (int) $companyId : null;
    }
}
