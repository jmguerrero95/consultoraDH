<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DomainException;

/**
 * An attachment the sender asked to attach cannot be attached to this message.
 *
 * The request is rejected as a whole: a message is never created with only some
 * of the attachments the sender named, because that would silently drop a file
 * the operator believes was sent. The HTTP layer maps this to 422.
 */
final class SupportAttachmentRejected extends DomainException
{
    public function __construct(
        public readonly string $reason = 'attachment_not_linkable',
        string $message = 'Uno o más adjuntos no pertenecen a esta conversación o ya fueron enviados.',
    ) {
        parent::__construct($message);
    }
}
