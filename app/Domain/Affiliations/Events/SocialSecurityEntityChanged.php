<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalogue entry was created, edited or deactivated.
 *
 * One class with an explicit action, because the three operations share the same
 * shape and splitting them into three near identical classes would add files
 * without adding information.
 */
final readonly class SocialSecurityEntityChanged implements AuditableEvent, SubjectAware
{
    private function __construct(
        public SocialSecurityEntity $entity,
        public User $actor,
        public AuditAction $action,
        /** @var list<string> */
        public array $changed,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * @param  list<string>  $changed
     */
    public static function created(SocialSecurityEntity $entity, User $actor): self
    {
        return new self($entity, $actor, AuditAction::SocialSecurityEntityCreated, []);
    }

    /**
     * @param  list<string>  $changed
     */
    public static function updated(SocialSecurityEntity $entity, User $actor, array $changed): self
    {
        return new self($entity, $actor, AuditAction::SocialSecurityEntityUpdated, $changed);
    }

    public static function deactivated(
        SocialSecurityEntity $entity,
        User $actor,
        string $from,
        string $to,
    ): self {
        return new self($entity, $actor, AuditAction::SocialSecurityEntityDeactivated, [], $from, $to);
    }

    public function auditAction(): AuditAction
    {
        return $this->action;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->entity;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        $metadata = [
            'type' => $this->entity->type->value,
            'name' => $this->entity->name,
            'code' => $this->entity->code,
        ];

        if ($this->changed !== []) {
            $metadata['changed_fields'] = $this->changed;
        }

        if ($this->from !== null && $this->to !== null) {
            $metadata['from'] = $this->from;
            $metadata['to'] = $this->to;
        }

        return $metadata;
    }
}
