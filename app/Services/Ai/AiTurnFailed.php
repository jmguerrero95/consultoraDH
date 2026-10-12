<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * A turn that did not produce an answer.
 *
 * ## Why this exists rather than an error string
 *
 * The alternative — catching the provider failure and replying with a sentence
 * describing it — writes that sentence into `ai_messages` as an assistant turn.
 * The next turn loads it as conversation history and sends it to the provider as
 * something the assistant said, so a transport blip becomes part of what the
 * model believes was discussed, and the person sees a normal-looking reply to a
 * question that was never answered.
 *
 * Failing loudly instead means the caller learns the turn produced nothing, the
 * conversation is left exactly as it was, and the existing history stays
 * legitimate.
 */
class AiTurnFailed extends RuntimeException
{
    public static function toolRoundsExhausted(int $rounds): self
    {
        return new self(
            'The model kept requesting tools without producing an answer after '
            .$rounds.' rounds. The turn produced no response.'
        );
    }
}
