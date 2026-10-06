<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\Client;
use BackedEnum;

/**
 * §7.1's client identity **after** the reviewer has spoken.
 *
 * ## The model, stated once
 *
 * A downstream action targets a client by its **document identity** — `client_document_type` and
 * `client_document_number` — and `ApplyImportPlan::clientFor()` resolves it that way. So when a
 * reviewer says "this document is actually client #41", the whole downstream chain must carry
 * *that client's* document, not the source document with a side-channel id attached.
 *
 * Two designs were available:
 *
 * 1. a `linked_client_id` in every payload, plus a second lookup path in Apply;
 * 2. canonicalise the document identity once, at plan build time.
 *
 * The second is what this does, because it needs no new lookup, keeps every existing writer and
 * reader honest, and makes the persisted plan self-describing: a reviewer reading a plan sees
 * which client each action targets, because the plan says so in the same terms as every other
 * action. A `linked_client_id` would be a second, parallel way of naming the same thing — and
 * two ways of naming one thing is precisely how §7.1's answers went unread in A04-R2.
 *
 * ## Why `link_existing_client` was dead in R2
 *
 * `linkedClientId()` existed, was documented, and `clientActions()` called it — but it only
 * emitted a *skipped* `create_client`. Every relationship, affiliation and rate action was then
 * built from the **source** document, so `clientFor()` looked up a client that was never created
 * and the batch refused. The decision was recorded, the issue was answered, and the plan still
 * pointed at a person who did not exist.
 */
final readonly class EffectiveClientIdentity
{
    private function __construct(
        public string $documentType,
        public string $documentNumber,
        /** §13: what the workbook said, whatever was decided. */
        public string $sourceDocumentType,
        public string $sourceDocumentNumber,
        /** The names the source offered, for §7.1's "the document decides, not the name". */
        public ?string $sourceName = null,
        public ?int $linkedClientId = null,
        public string $resolution = 'source',
    ) {}

    /** §7.1's default: the row's own normalised document, unchanged. */
    public static function fromSource(string $documentType, string $documentNumber, ?string $name): self
    {
        return new self($documentType, $documentNumber, $documentType, $documentNumber, $name, null, 'source');
    }

    /**
     * §7.1's `link_existing_client`: the document belongs to a client that already exists.
     *
     * The target's own document wins, because §7.1's identity is `DocumentType + DocumentNumber`
     * and the row that a person explicitly selected is the one everything else must agree with.
     */
    public static function fromLinkedClient(Client $client, string $sourceDocumentType, string $sourceDocumentNumber, ?string $sourceName): self
    {
        // `Client::$document_type` is cast to `DocumentType`, and the effective identity speaks the
        // staged rows' language — a string, because that is what a workbook cell is and what
        // `ApplyImportPlan::clientFor()` compares against.
        return new self(
            $client->document_type instanceof BackedEnum
                ? (string) $client->document_type->value
                : (string) $client->document_type,
            $client->document_number,
            $sourceDocumentType,
            $sourceDocumentNumber,
            $sourceName,
            (int) $client->id,
            'link_existing_client',
        );
    }

    /**
     * §7.1's effective identity for one document, given the answers recorded for it.
     *
     * @param  list<array{type: string, number: string, name: string|null}>  $observations  every observed (type, number, name)
     */
    public static function resolve(string $documentType, string $documentNumber, array $observations, ImportDecisionSet $decisions): self
    {
        $names = [];

        foreach ($observations as $observation) {
            if ($observation['name'] !== null && trim($observation['name']) !== '') {
                $names[] = trim($observation['name']);
            }
        }

        $latest = $names === [] ? null : end($names);

        // The producer's own key — see `ImportDecisionSet::clientIdentityKey()`.
        $identityKey = ImportDecisionSet::clientIdentityKey($documentType, $documentNumber);
        $linkedId = $decisions->linkedClientId($identityKey);

        if ($linkedId !== null) {
            $client = Client::query()->find($linkedId);

            // The named client is gone. Falling through to the source identity would create the
            // person the reviewer said already exists — a duplicate of somebody else's record,
            // which §7.1 is explicit about. Returning the source keeps the plan buildable and
            // leaves the answer unresolvable, so nothing reaches Apply.
            if ($client !== null) {
                return self::fromLinkedClient($client, $documentType, $documentNumber, $latest);
            }
        }

        return self::fromSource($documentType, $documentNumber, $latest);
    }

    public function wasDecided(): bool
    {
        return $this->resolution !== 'source';
    }

    /** Whether the plan must create this client, as opposed to using one that already exists. */
    public function needsCreation(): bool
    {
        return $this->linkedClientId === null;
    }

    /**
     * §13's provenance: the decision and, where there was one, the client it pointed at.
     *
     * The linked id is included even though the document already names the client, because the
     * audit trail's job is to answer "who decided this and against whom", and a document is not
     * an answer to the second half of that.
     *
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return array_filter([
            'client_identity_resolution' => $this->resolution,
            'linked_client_id' => $this->linkedClientId,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
