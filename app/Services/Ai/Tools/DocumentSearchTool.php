<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\ClientDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searches documents the user is authorized to read.
 *
 * Reuses A05's document authorization and private storage.
 * A user may only read documents they could read through the existing product.
 */
final class DocumentSearchTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'document_search';
    }

    public function description(): string
    {
        return 'Busca documentos en la cartera del usuario. Solo devuelve documentos que el usuario puede leer según sus permisos.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Texto a buscar en título, descripción o nombre original.',
                ],
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'Filtrar por cliente específico.',
                ],
                'document_type_id' => [
                    'type' => 'integer',
                    'description' => 'Filtrar por tipo de documento.',
                ],
                'review_status' => [
                    'type' => 'string',
                    'description' => 'Filtrar por estado de revisión.',
                    'enum' => ['received', 'under_review', 'approved', 'rejected'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Máximo de resultados (1 a 50).',
                    'default' => 20,
                ],
            ],
            'required' => [],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        $clientId = $arguments['client_id'] ?? null;
        $documentTypeId = $arguments['document_type_id'] ?? null;
        $reviewStatus = $arguments['review_status'] ?? null;
        $limit = $arguments['limit'] ?? 20;

        if ($query !== null) {
            $query = $this->requireString(['query' => $query], 'query', 500);
        }

        if ($clientId !== null) {
            $clientId = $this->requireId(['client_id' => $clientId], 'client_id');
        }

        if ($documentTypeId !== null) {
            $documentTypeId = $this->requireId(['document_type_id' => $documentTypeId], 'document_type_id');
        }

        if ($reviewStatus !== null) {
            $reviewStatus = $this->requireOneOf(
                ['review_status' => $reviewStatus],
                'review_status',
                ['received', 'under_review', 'approved', 'rejected']
            );
        }

        if (! is_int($limit) || $limit < 1 || $limit > 50) {
            throw new AiToolRejected("'limit' debe ser un entero entre 1 y 50.");
        }

        return [
            'query' => $query,
            'client_id' => $clientId,
            'document_type_id' => $documentTypeId,
            'review_status' => $reviewStatus,
            'limit' => $limit,
        ];
    }

    public function describe(array $arguments): string
    {
        $parts = ['Buscar documentos'];
        if (! empty($arguments['query'])) {
            $parts[] = "con término: {$arguments['query']}";
        }
        if (! empty($arguments['client_id'])) {
            $parts[] = "cliente: {$arguments['client_id']}";
        }
        $parts[] = "máx. {$arguments['limit']} resultados";
        return implode(', ', $parts);
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'documents.view');

        $query = ClientDocument::query()
            ->with(['client', 'documentType'])
            ->orderByDesc('created_at');

        // Apply user's document visibility constraints
        // Portal clients can only see their own documents with client visibility
        if ($user->account_type === 'client') {
            $query->where('client_id', $user->client_id ?? 0)
                  ->where('visibility', 'client');
        }

        if (! empty($arguments['query'])) {
            $search = $arguments['query'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('original_name', 'ILIKE', "%{$search}%");
            });
        }

        if (! empty($arguments['client_id'])) {
            $query->where('client_id', $arguments['client_id']);
        }

        if (! empty($arguments['document_type_id'])) {
            $query->where('document_type_id', $arguments['document_type_id']);
        }

        if (! empty($arguments['review_status'])) {
            $query->where('review_status', $arguments['review_status']);
        }

        $documents = $query->limit($arguments['limit'])->get();

        return [
            'query' => $arguments['query'] ?? '',
            'results' => $documents->map(fn (ClientDocument $d): array => [
                'id' => (int) $d->id,
                'client_id' => (int) $d->client_id,
                'client_name' => $d->client?->fullName(),
                'document_type_id' => (int) $d->document_type_id,
                'document_type_name' => $d->documentType?->name,
                'title' => $d->title,
                'description' => $d->description,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => (int) $d->size_bytes,
                'visibility' => $d->visibility->value,
                'review_status' => $d->review_status->value,
                'uploaded_via_portal' => (bool) $d->uploaded_via_portal,
                'created_at' => $d->created_at->toIso8601String(),
            ])->all(),
            'total_results' => $documents->count(),
        ];
    }
}