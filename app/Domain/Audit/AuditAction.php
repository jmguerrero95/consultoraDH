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

    // --- A02: clients -------------------------------------------------
    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientActivated = 'client.activated';
    case ClientDeactivated = 'client.deactivated';

    // --- A02: companies -----------------------------------------------
    case CompanyCreated = 'company.created';
    case CompanyUpdated = 'company.updated';
    case CompanyActivated = 'company.activated';
    case CompanyDeactivated = 'company.deactivated';

    // --- A02: client/company relationships -----------------------------
    case RelationshipCreated = 'relationship.created';
    case RelationshipClosed = 'relationship.closed';
    case RelationshipTransferred = 'relationship.transferred';
    case RelationshipParallelAuthorized = 'relationship.parallel_authorized';

    // --- A02: affiliations --------------------------------------------
    case AffiliationCreated = 'affiliation.created';
    case AffiliationClosed = 'affiliation.closed';
    case AffiliationChanged = 'affiliation.changed';

    // --- A02: social security entity catalogue ------------------------
    case SocialSecurityEntityCreated = 'social_security_entity.created';
    case SocialSecurityEntityUpdated = 'social_security_entity.updated';
    case SocialSecurityEntityDeactivated = 'social_security_entity.deactivated';

    // --- A03: monthly periods ------------------------------------------
    case PeriodCreated = 'period.created';
    case PeriodClosed = 'period.closed';
    case PeriodReopened = 'period.reopened';

    // --- A03: cutoff configuration -------------------------------------
    case CutoffRuleCreated = 'cutoff_rule.created';
    case CutoffRuleUpdated = 'cutoff_rule.updated';

    // --- A03: monthly rates --------------------------------------------
    case RateCreated = 'rate.created';
    case RateUpdated = 'rate.updated';

    // --- A03: obligations and adjustments ------------------------------
    case ObligationGenerated = 'obligation.generated';
    case ObligationAdjusted = 'obligation.adjusted';
    case ObligationAdjustmentReversed = 'obligation.adjustment_reversed';

    // --- A03: payments and allocations ---------------------------------
    case PaymentCreated = 'payment.created';
    case PaymentAllocated = 'payment.allocated';
    case PaymentAllocationReversed = 'payment.allocation_reversed';
    case PaymentAutoAllocated = 'payment.auto_allocated';
    case PaymentVoided = 'payment.voided';

    // --- A04: imports --------------------------------------------------------
    //
    // §13: "Emitir eventos de auditoría por cambios de dominio importantes" and "La acción
    // batch `import.applied` debe incluir conteos agregados."
    //
    // The four A02/A03 domain actions an import performs already emit their own events —
    // `RelationshipCreated`, `AffiliationCreated`, `RateCreated` — through the same domain
    // services an operator's manual change goes through. What the batch needs on top is one
    // event that says *this batch* wrote them, with the counts and the plan revision, so
    // "which import put this relationship here" is answerable from the trail alone rather than
    // by guessing from timestamps.
    //
    // Before this, an import wrote nothing of its own: the audit trail recorded thousands of
    // domain events with no link to the batch that caused them, and no record that an import
    // had been applied at all.

    case ImportUploaded = 'import.uploaded';
    case ImportParsed = 'import.parsed';
    case IssueResolved = 'import.issue_resolved';
    case PlanBuilt = 'import.plan_built';
    case ImportApplied = 'import.applied';
    case ImportCancelled = 'import.cancelled';
    case ImportFailed = 'import.failed';
}
