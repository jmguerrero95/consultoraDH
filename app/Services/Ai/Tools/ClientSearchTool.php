<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\Client;
use App\Models\User;
use App\Support\Validation\SafeSearch;

/**
 * Find clients by name or document.
 *
 * Reuses the portfolio's own search rule rather than inventing one: documents are
 * searched both as written and with separators removed, so "12.345.678" and
 * "12345678" reach the same person, exactly as the client list does.
 *
 * The result is a list of names and documents, never the whole record, because the
 * assistant's job is to identify who the question is about, not to answer it.
 */
final class ClientSearchTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'client_search';
    }

    public function description(): string
    {
        return 'Busca clientes por nombre o número de documento. Devuelve una lista breve de coincidencias para identificar a quién se refiere la pregunta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Nombre o número de documento a buscar.'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo de resultados (1 a 20).'],
            ],
            'required' => ['query'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $limit = $arguments['limit'] ?? 10;

        if (! is_int($limit) && ! (is_string($limit) && ctype_digit($limit))) {
            throw new AiToolRejected("El argumento 'limit' debe ser un número.");
        }

        return [
            'query' => $this->requireString($arguments, 'query', 120),
            'limit' => max(1, min(20, (int) $limit)),
        ];
    }

    public function describe(array $arguments): string
    {
        return 'Buscar clientes por «'.$arguments['query'].'».';
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'clients.view');

        $needle = $arguments['query'];
        $compact = mb_strtolower(str_replace(['.', ' ', '-'], '', $needle));
        $pattern = SafeSearch::likeNeedle($needle);
        $compactPattern = $compact === mb_strtolower($needle) ? null : SafeSearch::likeNeedle($compact);

        $query = Client::query()->where(function ($q) use ($pattern, $compactPattern): void {
            $q->where('first_names', 'ilike', $pattern)
                ->orWhere('last_names', 'ilike', $pattern)
                ->orWhere('document_number', 'ilike', $pattern);

            if ($compactPattern !== null) {
                $q->orWhere('document_number', 'ilike', $compactPattern);
            }
        });

        $rows = $query
            ->orderBy('first_names')
            ->limit($arguments['limit'])
            ->get(['id', 'first_names', 'last_names', 'document_type', 'document_number', 'status']);

        return [
            'found' => $rows->count(),
            'clients' => $rows->map(fn (Client $client): array => [
                'client_id' => $client->id,
                'full_name' => trim($client->first_names.' '.$client->last_names),
                'document_number' => $client->document_number,
                'document_type' => $client->document_type->value ?? $client->document_type,
                'status' => $client->status->value ?? $client->status,
            ])->all(),
        ];
    }
}
