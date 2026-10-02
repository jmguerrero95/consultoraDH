<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Actions;

use App\Domain\Affiliations\Events\SocialSecurityEntityChanged;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Shared\RecordStatus;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the catalogue of EPS, AFP, ARL and Cajas de Compensación Familiar.
 *
 * The catalogue starts empty on purpose. It is filled by authorised
 * administrators from an official source, because a list written from memory is
 * stale the moment it is written and looks exactly as trustworthy as one that was
 * checked.
 *
 * The duplicate rule is the one that needs care: "Nueva EPS" and "NUEVA EPS" must
 * not become two records. The comparison is done on a generated column, and the
 * unique index that enforces it is concurrency safe, so this class only has to
 * turn the resulting error into a message a person can act on.
 */
final class ManageCatalogueEntities
{
    /**
     * @param  array<string, string|null>  $attributes
     */
    public function create(array $attributes, User $actor): SocialSecurityEntity
    {
        $type = SocialSecurityEntityType::from($attributes['type']);

        return DB::transaction(function () use ($attributes, $actor, $type): SocialSecurityEntity {
            $entity = SocialSecurityEntity::query()->create([
                'type' => $type->value,
                'name' => $attributes['name'],
                'code' => $this->nullIfBlank($attributes['code'] ?? null),
                'tax_id' => $this->nullIfBlank($attributes['tax_id'] ?? null),
                'status' => RecordStatus::Active->value,
            ]);

            event(SocialSecurityEntityChanged::created($entity, $actor));

            return $entity;
        });
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    public function update(SocialSecurityEntity $entity, array $attributes, User $actor): SocialSecurityEntity
    {
        $changed = [];

        return DB::transaction(function () use ($entity, $attributes, $actor, &$changed): SocialSecurityEntity {
            // The type is part of the identity of a catalogue entry and is not
            // edited: moving an EPS to be an ARL would silently reinterpret years
            // of affiliations that point at it. Create a new entry instead.
            foreach (['name', 'code', 'tax_id'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $incoming = $this->nullIfBlank($attributes[$field]);

                if ($incoming === $entity->{$field}) {
                    continue;
                }

                $entity->{$field} = $incoming;
                $changed[] = $field;
            }

            if ($changed !== []) {
                $entity->save();

                event(SocialSecurityEntityChanged::updated($entity, $actor, $changed));
            }

            return $entity->refresh();
        });
    }

    /**
     * Deactivate a catalogue entry.
     *
     * The entry is kept. Affiliations from previous years still point at it and
     * have to keep resolving to the entity they were declared against.
     *
     * A new entry is rejected while any affiliation is still open, for the same
     * reason a company cannot be deactivated with clients attached: the
     * alternative is either leaving a dangling current affiliation or closing
     * somebody's EPS behind their back.
     */
    /**
     * Deactivates a catalogue entry.
     *
     * The count of open affiliations happens inside the transaction, after the
     * entity row is locked and re-read. Counting first left the decision stale: an
     * affiliation opened by another request in between was invisible here, so the
     * entity was deactivated while somebody was affiliated to it. That is the same
     * mistake the client and company sides had, and it is now the same fix.
     *
     * The lock order matches the one that opens an affiliation: client, entity,
     * history. This transaction only ever takes the entity, so it cannot deadlock
     * against an operation that takes it second.
     */
    public function deactivate(SocialSecurityEntity $entity, User $actor): SocialSecurityEntity
    {
        return DB::transaction(function () use ($entity, $actor): SocialSecurityEntity {
            $locked = SocialSecurityEntity::query()->lockForUpdate()->findOrFail($entity->id);

            if ($locked->status === RecordStatus::Inactive) {
                return $locked;
            }

            // Rows pinned and then counted: PostgreSQL refuses `FOR UPDATE` next to
            // `count()`, and holding the entity lock already serialises the
            // inserts that could change this number, since every one of them takes
            // that lock first.
            $open = $locked->activeAffiliations()
                ->lockForUpdate()
                ->get()
                ->count();

            if ($open > 0) {
                throw new EntityHasOpenAffiliations($open);
            }

            $from = $locked->status->value;

            $locked->forceFill(['status' => RecordStatus::Inactive->value])->save();

            event(SocialSecurityEntityChanged::deactivated($locked->refresh(), $actor, $from, RecordStatus::Inactive->value));

            return $locked->refresh();
        });
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
