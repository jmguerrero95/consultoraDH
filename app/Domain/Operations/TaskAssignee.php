<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\User;

/**
 * §R1 — who may hold an internal operational task.
 *
 * ## Why the restriction exists
 *
 * A task is internal work: it appears on the operations board, in the calendar, and it
 * produces a reminder notification addressed to whoever is responsible. A portal client
 * account is a login for somebody to see **their own** data — it holds no staff
 * permission at all, and it is not a member of the organisation.
 *
 * Assigning one would put an internal work item on a portal account that has no way to
 * open the screen it lives on, and route a staff reminder — "call this client about the
 * planilla" — at somebody who should never have received it. The two facts are unrelated
 * enough that neither one alone explains the problem.
 *
 * ## Why this is checked on the backend
 *
 * The interface's picker can be wrong, stale, or bypassed by a crafted request. A rule
 * that decides who receives an internal notification is enforced where the assignment is
 * written, not where it is chosen.
 */
final class TaskAssignee
{
    /** Only a colleague can hold internal work, and only while the account is active. */
    public static function assertAssignable(User $user): void
    {
        if ($user->account_type !== 'staff') {
            throw OperationNotApplicable::assigneeNotStaff($user->account_type);
        }

        if (! $user->isActive()) {
            throw OperationNotApplicable::assigneeNotActive();
        }
    }
}
