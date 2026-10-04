<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffRuleInUse;
use App\Domain\Billing\CutoffScope;
use App\Domain\Billing\Events\CutoffRuleSaved;
use App\Domain\Billing\Events\RateSaved;
use App\Domain\Billing\RateInUse;
use App\Domain\Billing\RateRejected;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\Client;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
use App\Models\User;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Configuration writes, as four actions instead of controller CRUD.
 *
 * ## Why these are not `$rule->fill($data)->save()` in a controller
 *
 * A financial invariant owned by a controller is a financial invariant that A04 will not
 * have. The import this project is about to build calls application services, not HTTP
 * endpoints, and it has no `FormRequest` to validate with — so a rule that says "a rate a
 * generated obligation quotes cannot change" and lives inside a controller is a rule the
 * importer will not enforce. Everything a row of configuration must satisfy is therefore
 * asserted here, in code that has no knowledge of HTTP.
 *
 * Each action asserts, independently of any request object:
 *
 *   - the effective month is a real month (`FirstDayOfMonth`'s counterpart, in the domain);
 *   - the amount is a positive whole number of pesos, the day is 1..31, the offset is 0 or 1;
 *   - the scope's shape is consistent (a client rule names a company, a general one names
 *     neither);
 *   - the client and the company exist;
 *   - a decision already quoted by a generated obligation is immutable;
 *   - a named uniqueness conflict becomes a domain conflict, not a 500.
 *
 * ## The lock, and what it closes
 *
 * Every write takes the shared topology protocol first, then locks its own row, then
 * **re-reads** it, then decides whether it is still eligible.
 *
 * That order is the whole of §8. The race is:
 *
 *     T1  generation reads Rate #5 = 235000 and locks it
 *     T2  "is Rate #5 unused?" → yes, because T1 has not written yet
 *     T2  changes Rate #5 to 300000 and commits
 *     T1  writes base_amount_cop = 235000 with rate_id = 5
 *
 * The snapshot is stable but the evidence is now false: the row says it was billed 235000
 * and points at a row that says 300000. Two things close it. The protocol makes T2 wait
 * for T1, because both take the same advisory key. And the re-read after the row lock
 * means T2's "is it unused?" question is asked about the committed state rather than about
 * whatever it happened to read when it started.
 *
 * The inverse order is equally covered: an edit that commits first makes the rate in-use
 * from the moment generation starts, so generation either locks the new decision or is
 * refused — it can never lock a row whose value has already changed underneath the number
 * it is holding.
 *
 * ## What is deliberately absent
 *
 * No generic repository, no abstract CRUD layer, no base action class. Four actions with
 * four sets of rules, because the rules differ: a rate has an amount and a cutoff has a
 * day and an offset, and a shared abstraction would hide exactly that difference behind a
 * configuration array.
 */
final class ManageBillingConfiguration
{
    public function __construct(
        private readonly BillingTopologyLock $topology = new BillingTopologyLock,
    ) {}

    // --- rates ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RateRejected
     */
    public function createRate(array $attributes, User $actor): ClientCompanyRate
    {
        return $this->topology->run(fn (): mixed => DB::transaction(function () use ($attributes, $actor): ClientCompanyRate {
            $clientId = $this->requireId($attributes['client_id'] ?? null, 'cliente');
            $companyId = $this->requireId($attributes['company_id'] ?? null, 'empresa');
            $month = $this->requireMonth($attributes['effective_month'] ?? null);
            $amount = $this->requireAmount($attributes['amount_cop'] ?? null);
            $notes = $this->requireNotes($attributes['notes'] ?? null);

            $this->requireClient($clientId);
            $this->requireCompany($companyId);

            try {
                $rate = ClientCompanyRate::query()->create([
                    'client_id' => $clientId,
                    'company_id' => $companyId,
                    'effective_month' => $month->startsOn(),
                    'amount_cop' => $amount,
                    'notes' => $notes,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                throw $this->translateRateConflict($e, $clientId, $companyId, $month);
            }

            event(new RateSaved($rate, $actor, AuditAction::RateCreated, ['client_id', 'company_id', 'effective_month', 'amount_cop']));

            return $rate->refresh();
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RateRejected
     */
    public function updateRate(ClientCompanyRate $rate, array $attributes, User $actor): ClientCompanyRate
    {
        return $this->topology->run(fn (): mixed => DB::transaction(function () use ($rate, $attributes, $actor): ClientCompanyRate {
            // Lock, then re-read: every decision below is made about the committed state
            // rather than about the instance the caller was handed, which may have been
            // loaded before it was locked.
            $locked = ClientCompanyRate::query()->lockForUpdate()->find($rate->id);

            if ($locked === null) {
                throw RateRejected::rateNotFound();
            }

            $changed = [];

            if (array_key_exists('amount_cop', $attributes)) {
                $amount = $this->requireAmount($attributes['amount_cop']);

                if ($amount !== $locked->amount_cop) {
                    $changed[] = 'amount_cop';
                }

                $locked->amount_cop = $amount;
            }

            if (array_key_exists('notes', $attributes)) {
                $notes = $this->requireNotes($attributes['notes']);

                if ($notes !== $locked->notes) {
                    $changed[] = 'notes';
                }

                $locked->notes = $notes;
            }

            // In-use is checked **after** the lock and against the re-read row, so a
            // generation that committed while this request was queued is taken into
            // account. Asking before the lock is the bug: the answer can be stale by the
            // time it is acted on.
            $inUse = array_diff($changed, ['notes']) !== [];

            if ($inUse) {
                RateInUse::assertEditable($locked, $actor);
            }

            if ($changed === []) {
                return $locked;
            }

            $locked->save();

            event(new RateSaved($locked, $actor, AuditAction::RateUpdated, $changed));

            return $locked->refresh();
        }));
    }

    // --- cutoff rules -----------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RateRejected
     */
    public function createCutoffRule(array $attributes, User $actor): CutoffRule
    {
        return $this->topology->run(fn (): mixed => DB::transaction(function () use ($attributes, $actor): CutoffRule {
            $scope = $this->requireScope($attributes['scope'] ?? null);
            $companyId = $this->optionalId($attributes['company_id'] ?? null);
            $clientId = $this->optionalId($attributes['client_id'] ?? null);
            $month = $this->requireMonth($attributes['effective_month'] ?? null);
            $day = $this->requireCutoffDay($attributes['cutoff_day'] ?? null);
            $offset = $this->requireOffset($attributes['month_offset'] ?? null);
            $notes = $this->requireNotes($attributes['notes'] ?? null);

            // The shape of the scope, enforced here rather than only in the FormRequest, so
            // an importer cannot write a client rule that names no company.
            if ($scope->requiresCompany() && $companyId === null) {
                throw RateRejected::scopeNeedsCompany($scope);
            }

            if (! $scope->requiresCompany() && $companyId !== null) {
                throw RateRejected::scopeForbidsCompany($scope);
            }

            if ($scope->requiresClient() && $clientId === null) {
                throw RateRejected::scopeNeedsClient($scope);
            }

            if (! $scope->requiresClient() && $clientId !== null) {
                throw RateRejected::scopeForbidsClient($scope);
            }

            if ($companyId !== null) {
                $this->requireCompany($companyId);
            }

            if ($clientId !== null) {
                $this->requireClient($clientId);
            }

            try {
                $rule = CutoffRule::query()->create([
                    'scope' => $scope->value,
                    'company_id' => $companyId,
                    'client_id' => $clientId,
                    'effective_month' => $month->startsOn(),
                    'cutoff_day' => $day,
                    'month_offset' => $offset->value,
                    'notes' => $notes,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                throw $this->translateCutoffConflict($e, $scope, $companyId, $clientId, $month);
            }

            event(new CutoffRuleSaved($rule, $actor, AuditAction::CutoffRuleCreated, ['created']));

            return $rule->refresh();
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RateRejected
     */
    public function updateCutoffRule(CutoffRule $rule, array $attributes, User $actor): CutoffRule
    {
        return $this->topology->run(fn (): mixed => DB::transaction(function () use ($rule, $attributes, $actor): CutoffRule {
            $locked = CutoffRule::query()->lockForUpdate()->find($rule->id);

            if ($locked === null) {
                throw RateRejected::ruleNotFound();
            }

            $changed = [];

            if (array_key_exists('cutoff_day', $attributes)) {
                $day = $this->requireCutoffDay($attributes['cutoff_day']);

                if ($day !== $locked->cutoff_day) {
                    $changed[] = 'cutoff_day';
                }

                $locked->cutoff_day = $day;
            }

            if (array_key_exists('month_offset', $attributes)) {
                $offset = $this->requireOffset($attributes['month_offset']);

                if ($offset !== $locked->month_offset) {
                    $changed[] = 'month_offset';
                }

                $locked->month_offset = $offset->value;
            }

            if (array_key_exists('notes', $attributes)) {
                $notes = $this->requireNotes($attributes['notes']);

                if ($notes !== $locked->notes) {
                    $changed[] = 'notes';
                }

                $locked->notes = $notes;
            }

            // Moving a decision to a different effective month is not an edit. It changes
            // which months the rule answers for, and the months in between would quietly
            // lose their cutoff, so only a later month is accepted.
            if (array_key_exists('effective_month', $attributes)) {
                $month = $this->requireMonth($attributes['effective_month']);

                if (! $month->isSameAs($locked->month())) {
                    if ($month->isBefore($locked->month())) {
                        throw RateRejected::effectiveMonthCannotGoBackwards($locked);
                    }

                    $changed[] = 'effective_month';

                    $locked->effective_month = $month->startsOn();
                }
            }

            // A quoted rule is evidence: the day and the offset behind an obligation's
            // `due_on` may not move.
            $structural = array_diff($changed, ['notes']) !== [];

            if ($structural) {
                CutoffRuleInUse::assertEditable($locked, $changed);
            }

            if ($changed === []) {
                return $locked;
            }

            $locked->save();

            event(new CutoffRuleSaved($locked, $actor, AuditAction::CutoffRuleUpdated, $changed));

            return $locked->refresh();
        }));
    }

    // --- conflicts --------------------------------------------------------

    private function translateRateConflict(QueryException $e, int $clientId, int $companyId, MonthValue $month): \Throwable
    {
        if (! UniqueViolation::isFor($e, SchemaConstraint::RATE_CLIENT_COMPANY_MONTH)) {
            return $e;
        }

        return RateRejected::duplicateRate($clientId, $companyId, $month);
    }

    private function translateCutoffConflict(
        QueryException $e,
        CutoffScope $scope,
        ?int $companyId,
        ?int $clientId,
        MonthValue $month,
    ): \Throwable {
        $expected = match ($scope) {
            CutoffScope::General => SchemaConstraint::CUTOFF_RULE_GENERAL_MONTH,
            CutoffScope::Company => SchemaConstraint::CUTOFF_RULE_COMPANY_MONTH,
            CutoffScope::Client => SchemaConstraint::CUTOFF_RULE_CLIENT_MONTH,
        };

        if (! UniqueViolation::isFor($e, $expected)) {
            return $e;
        }

        return RateRejected::duplicateCutoffRule($scope, $companyId, $clientId, $month);
    }

    // --- input ------------------------------------------------------------

    /**
     * A real calendar month, from `YYYY-MM` or a first-of-month date.
     *
     * The domain's own refusal, not a validation rule's: an importer has no FormRequest.
     */
    private function requireMonth(mixed $value): MonthValue
    {
        if ($value instanceof MonthValue) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return MonthValue::fromFirstDay(Carbon::instance($value));
        }

        if (! is_string($value) || trim($value) === '') {
            throw RateRejected::effectiveMonthRequired();
        }

        $trimmed = trim($value);

        try {
            return MonthValue::parse($trimmed);
        } catch (\Throwable) {
            throw RateRejected::effectiveMonthInvalid($trimmed);
        }
    }

    /**
     * A positive whole number of pesos.
     *
     * Rejects a float outright rather than rounding it: this system has never represented
     * centavos, and accepting one would mean inventing the difference between what was
     * typed and what was stored.
     */
    private function requireAmount(mixed $amount): int
    {
        if (is_int($amount)) {
            $value = $amount;
        } elseif (is_string($amount) && preg_match('/^\d+$/', trim($amount)) === 1) {
            $value = (int) trim($amount);
        } elseif (is_float($amount) && floor($amount) === $amount) {
            $value = (int) $amount;
        } else {
            throw RateRejected::amountMustBeWholePesos($amount);
        }

        if ($value <= 0) {
            throw RateRejected::amountMustBePositive($value);
        }

        return $value;
    }

    private function requireCutoffDay(mixed $day): int
    {
        if (! is_int($day) && ! (is_string($day) && preg_match('/^\d+$/', trim($day)) === 1)) {
            throw RateRejected::cutoffDayRequired();
        }

        $value = (int) $day;

        if ($value < 1 || $value > 31) {
            // 31 is accepted on purpose: the resolver clamps it to the last day of a
            // short month, so "the 31st" means "the end of the month" everywhere.
            throw RateRejected::cutoffDayOutOfRange($value);
        }

        return $value;
    }

    private function requireOffset(mixed $offset): CutoffMonthOffset
    {
        if ($offset instanceof CutoffMonthOffset) {
            return $offset;
        }

        if (! is_int($offset) && ! (is_string($offset) && preg_match('/^\d+$/', trim($offset)) === 1)) {
            throw RateRejected::offsetRequired();
        }

        return CutoffMonthOffset::tryFrom((int) $offset)
            ?? throw RateRejected::offsetOutOfRange((int) $offset);
    }

    private function requireScope(mixed $scope): CutoffScope
    {
        if ($scope instanceof CutoffScope) {
            return $scope;
        }

        if (! is_string($scope)) {
            throw RateRejected::scopeRequired();
        }

        return CutoffScope::tryFrom(trim($scope)) ?? throw RateRejected::scopeInvalid($scope);
    }

    private function requireNotes(mixed $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        if (! is_string($notes)) {
            throw RateRejected::notesInvalid();
        }

        $trimmed = trim($notes);

        if (mb_strlen($trimmed) > 2000) {
            throw RateRejected::notesTooLong(mb_strlen($trimmed));
        }

        return $trimmed === '' ? null : $trimmed;
    }

    private function requireId(mixed $value, string $label): int
    {
        $id = $this->optionalId($value);

        if ($id === null) {
            throw RateRejected::identifierRequired($label);
        }

        return $id;
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function requireClient(int $clientId): void
    {
        if (! Client::query()->whereKey($clientId)->exists()) {
            throw RateRejected::clientNotFound($clientId);
        }
    }

    private function requireCompany(int $companyId): void
    {
        if (! Company::query()->whereKey($companyId)->exists()) {
            throw RateRejected::companyNotFound($companyId);
        }
    }
}
