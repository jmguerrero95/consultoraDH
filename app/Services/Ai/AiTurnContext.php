<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Per-turn context a tool needs but cannot receive as an argument.
 *
 * ## Why this exists
 *
 * A tool runs as `(array $arguments, User $user)`. That is deliberate: the tool
 * reads named keys it declared, and nothing else, so there is no parameter that
 * can be a table name, a path or an URL. But two new capabilities need to know
 * *which* turn they are serving — the table exporter has to find the attachment
 * the person attached to *this* conversation, and a per-turn cache wants the
 * turn's id — and neither fact may come from the model's arguments, because the
 * model could then name somebody else's conversation.
 *
 * So the assistant sets this once at the top of a turn, exactly as it sets the
 * tool deadline and the outbound provenance, and a tool reads it. The value is
 * the conversation the turn belongs to, which the server already knows and the
 * model cannot forge. It is cleared when the turn ends so a later caller in the
 * same worker process does not inherit a stale scope.
 *
 * It carries no user: a tool is already handed the authenticated user. The
 * conversation is the only thing that is not reachable any other way, and it is
 * not a permission — it narrows where a tool may look, never widens it.
 */
final class AiTurnContext
{
    private ?int $conversationId = null;

    private ?string $turn = null;

    public function set(?int $conversationId, ?string $turn = null): void
    {
        $this->conversationId = $conversationId;
        $this->turn = $turn;
    }

    public function reset(): void
    {
        $this->conversationId = null;
        $this->turn = null;
    }

    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    public function turn(): ?string
    {
        return $this->turn;
    }
}
