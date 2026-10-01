<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Requests\ListQueryRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

final class ListCompaniesRequest extends ListQueryRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('companies.view') ?? false;
    }
}
