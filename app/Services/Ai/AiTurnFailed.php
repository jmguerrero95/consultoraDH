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

    /**
     * The model answered with nothing (BUG-018).
     *
     * Raised before anything is persisted, for the same reason as the tool-round
     * case: an empty assistant turn stored now is history the next turn replays as
     * something the assistant said. The person sees an error, and the conversation
     * is left exactly as it was.
     */
    public static function emptyAnswer(): self
    {
        return new self('The model returned an empty answer. The turn produced no response.');
    }

    /**
     * A file was requested and generation was authorised, but no file was
     * produced after the bounded recovery.
     *
     * Raised rather than delivering the model's claim of a file that does not
     * exist: a reply that names a link the person cannot download is a
     * fabricated result, and storing it would make the next turn treat it as
     * something the assistant really delivered.
     */
    public static function missingArtifact(): self
    {
        return new self('The model claimed a file it did not generate. The turn produced no artifact.');
    }
}
