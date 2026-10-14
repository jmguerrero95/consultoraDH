<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\SupportMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageCreated implements AuditableEvent, ShouldBroadcast, SubjectAware
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportMessage $message
    ) {}

    public function action(): string
    {
        return match ($this->message->sender_kind->value) {
            'client' => 'support.message.client_received',
            'staff' => $this->message->message_kind->value === 'note'
                ? 'support.note.created'
                : 'support.message.staff_sent',
            default => 'support.message.created',
        };
    }

    public function subject(): object
    {
        return $this->message;
    }

    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;

        if (! $conversation) {
            return [];
        }

        // Internal notes only go to staff channel (separate from client-visible channel)
        if ($this->message->message_kind->value === 'note') {
            return [
                new PrivateChannel('support.staff.conversation.'.$conversation->id),
            ];
        }

        return [
            new PrivateChannel('support.conversation.'.$conversation->id),
        ];
    }

    /**
     * The name Echo listens on.
     *
     * ## Why this exists
     *
     * Without it, Laravel broadcasts a `ShouldBroadcast` event under its fully qualified class
     * name — `App.Domain.Support.Events.SupportMessageCreated` — and Echo delivers it to a
     * listener registered for that string. The frontend registers `message.created` and
     * `note.created`, so **nothing** was ever delivered: the conversation worked over HTTP and
     * appeared to work in realtime only because the sender's own tab already had the message
     * from the POST response. A second session subscribed to the same authorised conversation
     * received the event and dropped it on the floor.
     *
     * `broadcastWith()` also carried `'event' => 'message.created'` in the payload, which is
     * what made this look wired: the name was present in the body, just not as the thing Echo
     * dispatches on.
     *
     * ## Why the name differs for a note
     *
     * Notes are broadcast **only** to `support.staff.conversation.{id}`, and the staff
     * subscription listens for `note.created`. Broadcasting a note as `message.created` would
     * deliver it to a channel nobody is listening on, so a note would silently stop appearing.
     * With this method, a note is published on the staff channel under the name that channel's
     * listener actually handles.
     *
     * The separation of channels is unchanged and is the point: a note never reaches
     * `support.conversation.{id}`, so a client session cannot receive one.
     */
    public function broadcastAs(): string
    {
        return $this->message->message_kind->value === 'note'
            ? 'note.created'
            : 'message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->load(['author', 'attachments']),
            // Kept so a consumer that reads the envelope rather than the event name still finds
            // it. `broadcastAs()` is what Echo dispatches on; this is only the body.
            'event' => $this->broadcastAs(),
        ];
    }

    public function auditAction(): AuditAction
    {
        return match ($this->message->sender_kind->value) {
            'client' => AuditAction::SupportMessageClientReceived,
            'staff' => $this->message->message_kind->value === 'note'
                ? AuditAction::SupportNoteCreated
                : AuditAction::SupportMessageStaffSent,
            default => AuditAction::SupportMessageCreated,
        };
    }

    public function auditActor(): ?Authenticatable
    {
        return $this->message->author;
    }

    public function auditSubject(): ?Model
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'conversation_id' => $this->message->conversation_id,
            'sender_kind' => $this->message->sender_kind->value,
            'message_kind' => $this->message->message_kind->value,
            'has_attachments' => $this->message->attachments->isNotEmpty(),
        ];
    }
}
