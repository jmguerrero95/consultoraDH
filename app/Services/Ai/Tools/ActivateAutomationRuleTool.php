<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\AuditEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleCursor;
use App\Models\User;

/**
 * Activates an automation rule.
 *
 * Produces a proposal that must be confirmed by a person.
 */
final class ActivateAutomationRuleTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'activate_automation_rule';
    }

    public function description(): string
    {
        return 'Propone activar una regla de automatización existente. Requiere confirmación.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rule_id' => ['type' => 'integer', 'description' => 'Identificador de la regla a activar.'],
            ],
            'required' => ['rule_id'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function validate(array $arguments): array
    {
        $ruleId = $this->requireId($arguments, 'rule_id');

        return ['rule_id' => $ruleId];
    }

    public function describe(array $arguments): string
    {
        return "Activar regla de automatización ID: {$arguments['rule_id']}";
    }

    public function execute(array $arguments, User $user): array
    {
        $this->denyPortalAccounts($user);
        $this->authorize($user, 'automations.manage');

        $rule = AutomationRule::find($arguments['rule_id']);
        if ($rule === null) {
            throw new AiToolRejected('Regla de automatización no encontrada.');
        }

        if ((int) $rule->owner_user_id !== (int) $user->id) {
            throw new AiToolRejected('No tiene permiso para activar esta regla.');
        }

        if ($rule->active) {
            throw new AiToolRejected('La regla ya está activa.');
        }

        // Initialize cursor for audit_action triggers
        if ($rule->trigger_type === 'audit_action') {
            $maxId = AuditEvent::max('id') ?? 0;
            AutomationRuleCursor::updateOrCreate(
                ['automation_rule_id' => $rule->id, 'source' => 'audit_events'],
                ['last_source_id' => $maxId]
            );
        }

        $rule->activate();

        return [
            'activated' => true,
            'rule_id' => $rule->id,
            'name' => $rule->name,
        ];
    }
}
