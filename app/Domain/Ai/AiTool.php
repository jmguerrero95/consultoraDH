<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Models\User;

/**
 * One tool the assistant is allowed to call.
 *
 * ## The shape of what a tool can be
 *
 * A tool has a fixed name, a JSON Schema, and a method that takes already-typed
 * arguments and the authenticated user it is running as. There is no parameter
 * that is a class name, a table, a URL, a shell string or a file path, because
 * there is nowhere to put one: `arguments` is an array that only the tool itself
 * reads, and it reads named keys it declares.
 *
 * That is why "run this SQL" cannot be expressed. It is not that a prompt asks
 * the model not to; it is that no such tool exists to be asked for.
 *
 * ## `mutates`
 *
 * A tool that mutates does not run on the model's say-so. `mutates` marks it, and
 * the assistant turns the request into a proposal a person confirms. A read tool
 * runs immediately, because reading something the caller may already read is not
 * an action anybody needs to approve.
 *
 * ## The user is not optional
 *
 * A tool executes as the person who asked, never as a service identity with more
 * authority. That is the authorization model: the tool asks the same questions the
 * controller would have asked, of the same user.
 */
interface AiTool
{
    /**
     * The stable name the model calls.
     */
    public function name(): string;

    /**
     * What the model is told this tool does.
     */
    public function description(): string;

    /**
     * JSON Schema for the arguments.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Whether calling this tool changes something.
     */
    public function isWrite(): bool;

    /**
     * A sentence describing what calling it would do, shown to the person who is
     * asked to confirm.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function describe(array $arguments): string;

    /**
     * Reject arguments that are not usable, before anything is read or written.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws AiToolRejected
     */
    public function validate(array $arguments): array;

    /**
     * Do the work, as this user.
     *
     * @param  array<string, mixed>  $arguments  already validated
     * @return array<string, mixed>  a result to hand back to the model as data
     */
    public function execute(array $arguments, User $user): array;
}