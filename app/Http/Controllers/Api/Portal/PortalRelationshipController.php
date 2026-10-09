<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortalRelationshipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->account_type === 'client', 403, 'Esta sección es solo para clientes.');

        $client = Client::query()->findOrFail($user->client_id);

        $assignments = ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->with('company')
            ->orderByDesc('started_on')
            ->get();

        $affiliations = ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->with('entity')
            ->orderByDesc('started_on')
            ->get();

        return response()->json([
            'relationships' => $assignments->map(fn (ClientCompanyAssignment $a): array => [
                'id' => (int) $a->id,
                'company_name' => $a->company?->legal_name,
                'company_tax_id' => $a->company?->tax_id,
                'started_on' => $a->started_on?->format('Y-m-d'),
                'ended_on' => $a->ended_on?->format('Y-m-d'),
                'job_title' => $a->job_title,
            ])->all(),
            'affiliations' => $affiliations->map(fn (ClientAffiliation $a): array => [
                'id' => (int) $a->id,
                'type' => $a->type->value,
                'entity_name' => $a->entity?->name,
                'started_on' => $a->started_on?->format('Y-m-d'),
                'ended_on' => $a->ended_on?->format('Y-m-d'),
            ])->all(),
        ]);
    }
}
