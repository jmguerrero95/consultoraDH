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
    public function deactivate(SocialSecurityEntity $entity, User $actor): SocialSecurityEntity
    {
        if ($entity->status === RecordStatus::Inactive) {
            return $entity;
        }

        $open = $entity->activeAffiliations()->count();

        if ($open > 0) {
            throw new EntityHasOpenAffiliations($open);
        }

        return DB::transaction(function () use ($entity, $actor): SocialSecurityEntity {
            $from = $entity->status->value;

            $entity->forceFill(['status' => RecordStatus::Inactive->value])->save();

            event(SocialSecurityEntityChanged::deactivated($entity->refresh(), $actor, $from, RecordStatus::Inactive->value));

            return $entity->refresh();
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
