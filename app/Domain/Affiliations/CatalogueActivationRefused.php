<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * A catalogue write was refused because the actor may not perform it.
 *
 * ## Why it is its own type and not an `AuthorizationException`
 *
 * Because the reason is always a sentence written for the person who pressed the button. A refusal
 * like ««Ana» no tiene el permiso social_security_entities.manage» tells them what to ask for; a
 * bare 403 does not. The message travels with the exception so whichever layer catches it — an HTTP
 * response, a command, a test — can show it without reconstructing the rule.
 *
 * It extends `RuntimeException` so nothing that already catches that gains a new obligation, while
 * still being catchable by type for the tests that assert a refusal rather than a crash.
 */
final class CatalogueActivationRefused extends \RuntimeException {}
