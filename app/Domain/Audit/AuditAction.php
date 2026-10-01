<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Catalogue of auditable actions in A01.
 *
 * Names are dotted and grouped by domain so that they stay predictable when the
 * business modules are added (`payments.register`, `periods.close`, ...).
 */
enum AuditAction: string
{
    // Authentication
    case LoginSucceeded = 'auth.login.succeeded';
    case LoginFailed = 'auth.login.failed';
    case LoginRejectedInactive = 'auth.login.rejected_inactive';
    case SessionRejectedInactive = 'auth.session.rejected_inactive';
    case Logout = 'auth.logout';
    case SessionChecked = 'auth.session.checked';

    // Password recovery
    case PasswordResetRequested = 'auth.password.reset_requested';
    case PasswordResetCompleted = 'auth.password.reset_completed';

    // Profile
    case ProfileUpdated = 'profile.updated';
    case EmailAddressChanged = 'profile.email_changed';
    case PasswordChanged = 'profile.password_changed';

    // Administration
    case AdministratorCreated = 'admin.created';
}
