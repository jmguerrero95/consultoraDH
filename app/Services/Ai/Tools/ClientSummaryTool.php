<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Client;
use App\Models\User;

/**
 * A client's identity and current company relationships.
 *
 * The relationships are read through the same relationship the client's own screen
 * uses, so a caller without `relationships.view` gets an answer without them
 * rather than a refusal: the information is simply not there to be summarized.
 */
final class ClientSummaryTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'client_summary';
    }

    public function description(): string
    {
        return 'Resumen de un cliente: identidad, estado y las empresas y entidades donde esta registrado.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client_id' => ['type' => 'integer', 'description' => 'Identificador del cliente.'],
            ],
            'required' => ['client_id'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        return ['client_id' => $this->requireId($arguments, 'client_id')];
    }

    public function describe(array $arguments): string
    {
        return 'Consultar el resumen del cliente #'.$arguments['client_id'].'.';
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'clients.view');

        $client = Client::find($arguments['client_id']);

        if ($client === null) {
            return ['found' => false, 'message' => 'No existe un cliente con ese identificador.'];
        }

        $summary = [
            'found' => true,
            'client_id' => $client->id,
            'full_name' => trim($client->first_names.' '.$client->last_names),
            'document' => $client->document_type->value.' '.$client->document_number,
            'status' => $client->status->value,
        ];

        // Absent rather than empty when the caller may not read relationships:
        // "no companies" and "not allowed to see them" are different answers.
        if ($user->can('relationships.view')) {
            $summary['companies'] = $client->companyAssignments()
                ->whereNull('ended_on')
                ->with('company')
                ->get()
                ->map(fn ($assignment): array => [
                    'assignment_id' => $assignment->id,
                    'company' => $assignment->company?->displayName(),
                ])
                ->all();
        }

        if ($user->can('affiliations.view')) {
            $summary['affiliations'] = $client->affiliations()
                ->whereNull('ended_on')
                ->with('entity')
                ->get()
                ->map(fn ($affiliation): array => [
                    'entity' => $affiliation->entity?->name,
                    'started_on' => $affiliation->started_on?->toDateString(),
                ])
                ->all();
        }

        return $summary;
    }
}
