<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Assistant\Tasks\AiTaskHandler;
use App\Domain\Assistant\Tasks\IngestDocumentTask;

/**
 * Which handler runs a given kind of task.
 *
 * A registry rather than a chain of `instanceof` checks, so adding a kind of
 * work is one entry here and nowhere else - and so a kind with no handler is a
 * visible "nobody can do this" rather than a fatal error at dispatch time.
 */
final class AiTaskHandlerResolver
{
    /**
     * @param  list<class-string<AiTaskHandler>>  $handlers
     */
    public function __construct(private readonly array $handlers = []) {}

    /**
     * Resolve a handler, or null when the kind is not supported.
     */
    public function for(string $kind): ?AiTaskHandler
    {
        foreach ($this->all() as $handler) {
            if ($handler->kind() === $kind) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function supportedKinds(): array
    {
        return array_map(fn (AiTaskHandler $h): string => $h->kind(), $this->all());
    }

    /**
     * @return list<AiTaskHandler>
     */
    private function all(): array
    {
        return array_map(
            fn (string $class): AiTaskHandler => app($class),
            $this->handlers !== [] ? $this->handlers : [IngestDocumentTask::class],
        );
    }
}
