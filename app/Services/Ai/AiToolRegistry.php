<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiTool;
use App\Domain\Ai\AiToolDefinition;
use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\Tools\ActivateAutomationRuleTool;
use App\Services\Ai\Tools\AutomationVocabularyTool;
use App\Services\Ai\Tools\ClientAccountTool;
use App\Services\Ai\Tools\ClientSearchTool;
use App\Services\Ai\Tools\ClientSummaryTool;
use App\Services\Ai\Tools\CreateArtifactTool;
use App\Services\Ai\Tools\CreateAutomationRuleTool;
use App\Services\Ai\Tools\CreateOperationalTaskTool;
use App\Services\Ai\Tools\CreateReportTool;
use App\Services\Ai\Tools\DeactivateAutomationRuleTool;
use App\Services\Ai\Tools\DocumentReadTool;
use App\Services\Ai\Tools\DocumentSearchTool;
use App\Services\Ai\Tools\OperationalTasksTool;
use App\Services\Ai\Tools\ReceivablesSummaryTool;
use App\Services\Ai\Tools\ReportPreviewTool;
use App\Services\Ai\Tools\SupportSummaryTool;
use App\Services\Ai\Tools\WebFetchTool;
use App\Services\Ai\Tools\WebSearchTool;

/**
 * The closed set of things the assistant may do.
 *
 * ## Why the list, not the capability
 *
 * A tool name that is not in this registry does not exist as far as the assistant
 * is concerned: it is never advertised to the model, and a call naming it is
 * refused before any argument is read. There is no code path from a model's
 * output to SQL, to a URL, to the shell or to a filesystem path, because the
 * values that reach a tool are the named keys that tool declared and nothing
 * else.
 *
 * That is the security boundary. The system prompt asks the model to treat
 * business data as data; the boundary does not depend on the model obeying.
 *
 * ## Authorization is re-checked at the moment of the call
 *
 * A tool runs as the person who asked, and checks the same permission the normal
 * application would check for the same action. A portal account therefore gets
 * exactly what its own portal screen would give it, and an assistant that asks
 * for something its caller may not do is refused rather than quietly narrowed.
 */
class AiToolRegistry
{
    /**
     * @var array<string, AiTool>
     */
    private array $tools;

    /**
     * @param  list<AiTool>  $tools
     */
    public function __construct(array $tools = [])
    {
        $byName = [];

        foreach ($tools as $tool) {
            $byName[$tool->name()] = $tool;
        }

        $this->tools = $byName;
    }

    /**
     * Build the registry from the container.
     *
     * Tools are constructor-injected rather than resolved per call so the set is
     * fixed for the request and cannot change under a conversation.
     */
    public static function make(): self
    {
        return new self([
            app(ClientSearchTool::class),
            app(ClientSummaryTool::class),
            app(ReceivablesSummaryTool::class),
            app(ClientAccountTool::class),
            app(OperationalTasksTool::class),
            app(ReportPreviewTool::class),
            app(SupportSummaryTool::class),
            app(CreateOperationalTaskTool::class),
            app(CreateReportTool::class),
            app(WebSearchTool::class),
            app(WebFetchTool::class),
            app(CreateArtifactTool::class),
            app(DocumentSearchTool::class),
            app(DocumentReadTool::class),
            app(AutomationVocabularyTool::class),
            app(CreateAutomationRuleTool::class),
            app(ActivateAutomationRuleTool::class),
            app(DeactivateAutomationRuleTool::class),
        ]);
    }

    /**
     * @return array<string, AiTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * The read tools, which run immediately when the caller is allowed.
     *
     * @return list<AiTool>
     */
    public function reads(): array
    {
        return array_values(array_filter($this->tools, fn (AiTool $tool): bool => ! $tool->isWrite()));
    }

    /**
     * The write tools, which only ever become proposals.
     *
     * @return list<AiTool>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->tools, fn (AiTool $tool): bool => $tool->isWrite()));
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): AiTool
    {
        return $this->tools[$name] ?? throw new AiToolRejected(
            "The assistant has no tool called '{$name}'."
        );
    }

    /**
     * The catalogue as the model sees it.
     *
     * Only the read tools. A write tool is offered to the model too — it may
     * legitimately decide a task should be created — but it is described as a
     * proposal, because that is what calling it produces.
     *
     * @return list<AiToolDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $description = $tool->description();

            if ($tool->isWrite()) {
                $description .= ' Calling this does not change anything: it proposes a change for a person to confirm.';
            }

            $definitions[] = new AiToolDefinition($tool->name(), $description, $tool->parameters());
        }

        return $definitions;
    }

    /**
     * Validate a call's arguments without running it.
     *
     * Used both when a proposal is made and again when it is confirmed, because a
     * proposal can sit for minutes and the world it referred to can change in
     * that time. The second check is not a formality: it is what stops a
     * conversation about deleting something from becoming a confirmation of a
     * delete of something that has since taken its place.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws AiToolRejected
     */
    public function validate(string $tool, array $arguments): AiTool
    {
        $instance = $this->get($tool);
        $instance->validate($arguments);

        return $instance;
    }

    /**
     * Run a read tool as this user.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function executeRead(string $tool, array $arguments, User $user): array
    {
        $instance = $this->get($tool);

        if ($instance->isWrite()) {
            throw new AiToolRejected("'{$tool}' changes something and cannot be run as a read.");
        }

        return $instance->execute($instance->validate($arguments), $user);
    }

    /**
     * Run a write tool as this user, from arguments the server stored.
     *
     * @param  array<string, mixed>  $arguments  the proposal's own arguments, not the browser's
     * @return array<string, mixed>
     */
    public function executeWrite(string $tool, array $arguments, User $user): array
    {
        $instance = $this->get($tool);

        if (! $instance->isWrite()) {
            throw new AiToolRejected("'{$tool}' does not change anything.");
        }

        // Validated again here, not only at proposal time.
        return $instance->execute($instance->validate($arguments), $user);
    }
}
