<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Models\SocialSecurityEntity;

/**
 * An entity a mapping points at, which may or may not exist yet.
 *
 * ## Why this exists
 *
 * A dry run has to answer "does this mapping have a target?" **before** the entities it points at
 * have been written. The first attempt faked the model with
 * `(object) ['getKey' => fn () => 0]`, which puts a closure in a property named `getKey` — an object
 * that answers `$o->getKey()` by invoking a *property*, not a method. It happened to not crash,
 * because `stdClass` resolves `$o->getKey()` to that property and the closure is then cast to int,
 * which is `0`. That is the whole mechanism. It worked by accident, and an accidental mechanism is
 * one that changes silently the next time anything touches it.
 *
 * So a dry run names the target explicitly instead of pretending the row is there:
 *
 *     exists: false, key: 0
 *
 * and the caller branches on `exists`, never on a value that pretends to be a key. No fake model, no
 * method that does not exist, and no way for a projected entity to be written by accident — because
 * there is no object to write.
 */
final class ProjectedMappingTarget
{
    private function __construct(
        public readonly SocialSecurityEntity $entity,
        /** False while the row is only projected: the activation has not written it yet. */
        public readonly bool $exists,
    ) {}

    public static function of(SocialSecurityEntity $entity): self
    {
        return new self($entity, true);
    }

    /**
     * The entity this activation is about to create, named but not yet written.
     *
     * @param  string  $name  the canonical name the manifest will store
     */
    public static function projected(string $name): self
    {
        return new self(new SocialSecurityEntity(['name' => $name]), false);
    }

    public function key(): int
    {
        // A projected target has no key, and returning `0` from here would let it be written as
        // `social_security_entity_id = 0`. The callers below never write a projected target, and
        // this throws rather than pretend, so a future caller cannot do it by accident.
        if (! $this->exists) {
            throw new \LogicException(
                'Un destino proyectado no tiene clave. No se puede escribir un mapeo contra una entidad '
                .'que la activación todavía no ha creado.',
            );
        }

        return (int) $this->entity->getKey();
    }
}
