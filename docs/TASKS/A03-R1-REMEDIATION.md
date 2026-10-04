PROJECT: CONSULTORA DH

TASK:
A03-R1 — Financial Correctness, Concurrency, Contract and UI Closure

BASELINE COMMIT:

7af0f9f545ec94692c8c0c4301f872050c3c37c0

IMPORTANT CONTEXT

A01 is approved and frozen.
A02 is approved and frozen.
PRE-A03 is approved.

A03 exists and is substantially correct architecturally.
DO NOT rewrite A03.
DO NOT simplify the domain by deleting financial history.
DO NOT start A04.

This task is one comprehensive correction pass after an external review of the
REAL GitHub commit.

The review covered:

- database schema
- migrations
- monthly periods
- generation
- rates
- cutoff hierarchy
- obligations
- adjustments
- payments
- allocations
- receivables
- RBAC
- Vue/TypeScript contracts
- Playwright
- E2E isolation
- concurrency
- query growth

The objective is to CLOSE A03 in this round.

If a finding below exposes another directly-related defect while implementing
the fix, correct it in the same commit and document it.

Do not expand into unrelated A01/A02 refactors.

A02 may only be touched narrowly where a shared concurrency protocol is required
to protect A03 financial correctness.

======================================================================
0. NON-NEGOTIABLE RULES
======================================================================

Preserve the existing financial model:

- money is whole COP integers;
- no floats for money;
- obligation base snapshots are immutable;
- balances are derived;
- adjustments are append-only ledger entries;
- payments are never hard-deleted;
- allocations are historical records;
- voids/reversals preserve history;
- unapplied money remains credit;
- existing obligations are never silently recalculated;
- configuration uses effective history;
- missing business facts block generation instead of being invented.

DO NOT:

- mutate generated base amounts;
- rewrite financial history;
- auto-close or auto-delete data to satisfy a migration;
- silently repair inconsistent production data;
- introduce formulas for rates that were never specified;
- use SQLite to prove PostgreSQL locking/constraint behavior;
- edit already-published A03 migrations in place.

All schema changes in this remediation MUST use NEW migrations after
7af0f9f.

For migrations that cannot be safely rolled back after legitimate new data has
been created:

DO NOT destroy/merge data in `down()`.

Either:

- perform a safe preflight and restore only when possible; or
- fail the rollback loudly and explicitly with a clear exception.

======================================================================
1. FIX MONTH NORMALIZATION AND CURRENT-PERIOD RESOLUTION — BLOCKER
======================================================================

Inspect:

app/Domain/Periods/MonthlyPeriod.php
app/Domain/Periods/MonthlyPeriodResolver.php

`MonthlyPeriod::fromFirstDay()` currently drops the time but does NOT normalize
to the first day of the month.

Example:

MonthlyPeriod::fromFirstDay('2026-10-15')

must represent:

2026-10-01

not:

2026-10-15.

This currently makes the current-period resolver miss the exact current month
and fall back to the newest open period.

Reproduction:

September open
October open
November open
clock = 2026-10-15

Expected:
October

Never:
November

REQUIREMENTS

- Every MonthlyPeriod value object represents the first day of its month.
- Normalize full-date inputs with startOfMonth/startOfDay.
- `parse()` must preserve the same invariant.
- Do not silently accept impossible months.

TESTS

- fromFirstDay('2026-10-15') => 2026-10-01
- Carbon with time => first day, midnight
- Sep + Oct + Nov open, clock Oct 15 => Oct
- no Oct row, Sep + Nov open => documented fallback
- 2026-00 / 2026-13 / 2026-99 => validation error at HTTP boundary, never 500

======================================================================
2. CREATE-PERIOD CONCURRENCY — UNIQUE RACE
======================================================================

Inspect CreatePeriod.

The current pattern:

SELECT ... FOR UPDATE
if missing:
    INSERT

does not protect a row that does not exist.

Two concurrent requests can both see no period and both try to insert.

The database unique constraint protects the data, but the losing request must
not become a raw database 500.

REQUIREMENTS

- Keep the DB unique constraint authoritative.
- Translate the named period-month unique violation into the same useful domain
  conflict as the normal duplicate-period path.
- HTTP response should be deterministic, preferably 409.
- Preserve idempotent/clear behavior.

TEST:

two stale/concurrent creation paths for the same month
=> one period
=> second receives controlled domain/API conflict
=> no 500.

Use a real PostgreSQL two-connection test if practical.

======================================================================
3. FIX EMPTY HALF-OPEN RELATIONSHIP INTERSECTION — BLOCKER
======================================================================

A02 relationships are:

[started_on, ended_on)

Therefore:

[started_on = 2026-10-15,
 ended_on   = 2026-10-15)

is an EMPTY interval.

It covers no day.

The A03 candidate query currently can still count it as intersecting October.

This can happen around same-day transfers and can make both the source and the
destination company receive an obligation.

REQUIREMENTS

Make the candidate intersection mathematically consistent with the half-open
interval model.

An empty relationship segment must never produce a billing candidate.

TESTS

- start=end inside month => no candidate
- source closes and destination starts on same day =>
  only the destination effective segment is billed for that day
- ordinary mid-month non-empty relationship still counts
- relationship ending on first day of month does not count
- relationship starting first day next month does not count

======================================================================
4. RELATIONSHIP SEGMENTS WITH SAME CLIENT/COMPANY/MONTH
======================================================================

The candidate builder currently counts every relationship row intersecting the
month and interprets more than one row for a client/company pair as an ambiguous
relationship state.

That is too broad.

A client may legitimately:

work for Company A
leave
return to Company A

inside the same month.

Those rows may be NON-OVERLAPPING historical segments.

A03's economic identity remains:

period + client + company

and there must still be ONE monthly obligation for that economic pair.

REQUIREMENTS

Distinguish:

A. true overlapping relationship segments
B. multiple non-overlapping historical segments

Do not call B “two open relationships”.

Choose and document a deterministic provenance policy.

Preferred behavior:

- non-overlapping segments for same client/company/month collapse into one
  monthly candidate;
- true overlap remains a blocker because provenance is ambiguous.

If exactly one assignment produced the candidate:
store its assignment id.

If several legitimate non-overlapping assignments produced one monthly
obligation:
do NOT arbitrarily pick one and pretend it was the only source.

Use a deliberate provenance representation, for example:

- nullable assignment_id plus explicit source assignment IDs in audit metadata;
  OR
- a small provenance mapping table;
  OR another normalized solution.

Do not duplicate the monthly financial obligation merely because employment was
split into two segments.

TESTS

- leave and return same company in same month, no overlap =>
  one obligation
- true overlapping corrupt segments =>
  blocker
- different companies =>
  separate obligations
- provenance is explainable afterwards

======================================================================
5. SHARED STRUCTURAL CONCURRENCY BETWEEN A02 AND A03 — CRITICAL
======================================================================

A03 generation currently locks the period but does not serialize against A02
changes to the topology it is reading.

Affected operations include at least:

- relationship link
- relationship close
- relationship transfer
- client deactivation/reactivation where eligibility changes
- company deactivation/reactivation
- generation
- period close completeness check

REAL RACES

A. Generation reads a relationship that intersects October.
   Another transaction closes/transfers it retroactively before generation
   commits.
   Generation may still write a debt from stale topology.

B. Generation sees client/company active.
   Another transaction deactivates one before generation commits.
   Generation writes using stale eligibility.

C. Close-period checks “nothing missing”.
   Another request inserts/changes a relationship effective in that same month.
   Period closes incomplete.

The period row lock does NOT serialize these A02 master/history rows.

REQUIREMENTS

Implement one shared structural serialization protocol for authoritative billing
topology.

A PostgreSQL transaction-level advisory lock is acceptable and likely the
simplest solution at this scale.

For example, conceptually:

BILLING_TOPOLOGY_LOCK
→ PERIOD when applicable
→ CLIENT rows ascending id
→ COMPANY rows ascending id
→ relationship/history rows ascending id
→ RATE rows ascending id
→ CUTOFF rows ascending id

The exact implementation may differ, but it MUST have a documented global order
and prove there is no opposite-order acquisition.

A02 topology-changing write actions that can change an A03 candidate must acquire
the shared topology protocol BEFORE changing the topology.

A03 authoritative:

- generation
- close-period completeness

must use the same protocol.

Preview may remain advisory and stale by design.

Authoritative write/close may not.

Do NOT globally lock the database.
Do NOT use table-wide LOCK unless you can justify it.

TESTS

Use real PostgreSQL two-connection concurrency tests where practical:

- generation vs relationship close
- generation vs transfer
- generation vs deactivation
- close period vs retroactive relationship creation

Prove final state has no debt based on a topology that was already invalid when
the financial transaction committed.

If a particular concurrency case cannot be tested with two independent DB
connections in the harness, state that explicitly.
Do NOT describe a one-connection stale-object test as a concurrency test.

======================================================================
6. CLOSE PERIOD MUST PROVE COMPLETENESS — BLOCKER
======================================================================

Current ClosePeriod proves only:

- generation_performed_at exists;
- existing obligations are structurally sound.

That is insufficient.

Scenario:

1. October is generated.
2. generation_performed_at is set.
3. Another October-effective relationship appears.
4. No obligation exists for it.
5. October is closed.

Current behavior can freeze an incomplete month.

REQUIREMENTS

Inside the SAME authoritative structural/period lock used for close:

recompute missing-only generation state.

Close must be blocked if:

- a candidate still needs an obligation;
- a candidate that should be generated has a blocker;
- topology is ambiguous.

Do not create obligations during close.

The operator must:

fix config/topology
→ generate the missing obligations
→ close

A period that genuinely contains zero candidates remains closable AFTER an
explicit generation pass established that result.

TESTS

A. generated + later valid relationship
   => close blocked until missing debt generated

B. generated + new relation missing rate
   => close blocked, missing_rate visible

C. generated + new relation missing cutoff
   => close blocked, missing_cutoff visible

D. generated empty portfolio
   => close succeeds

E. existing obligations whose current configuration later changes/disappears
   must not make close depend on recalculating those immutable snapshots.

======================================================================
7. EXISTING OBLIGATIONS MUST NOT BE BLOCKED BY CURRENT CONFIGURATION
======================================================================

The candidate builder resolves current historical config even for candidates
whose obligation already exists.

In missing-only mode, inactive/ambiguous state becomes warning, but missing rate
and missing cutoff can still become blockers.

That violates the snapshot model.

Once an obligation exists, generating OTHER missing obligations must never
require the configuration behind the existing immutable obligation to still be
available as current input.

REQUIREMENTS

For an already-existing obligation:

- never regenerate it;
- never rewrite it;
- current rate/cutoff/status problems may be diagnostics/warnings;
- they must not block writing unrelated missing obligations.

Generation itself should be semantically ALWAYS missing-only/idempotent.

The UI currently exposes a “Solo las que faltan” checkbox even though existing
obligations are never actually regenerated.

Remove or redefine that misleading control.

Preferred:

- generation always writes only missing obligations;
- preview may optionally show existing rows for context;
- existing rows are never treated as pending writes.

TEST:

existing October debt whose old config is no longer current
+
new October relationship correctly configured

=> generating missing rows creates only the new debt.

======================================================================
8. SERIALIZE RATE/CUTOFF CONFIGURATION AGAINST GENERATION — CRITICAL
======================================================================

A referenced rate/cutoff is evidence for the generated snapshot.

Current race:

T1 generation reads Rate #5 = 235000
T2 checks Rate #5 unused
T2 changes Rate #5 = 300000
T2 commits
T1 creates:
    base_amount_cop = 235000
    rate_id = 5

Now Rate #5 says 300000.

The snapshot is financially stable but the referenced evidence is false.

Equivalent risk exists for cutoff rules.

REQUIREMENTS

Move rate/cutoff writes into explicit application/domain actions.

Controllers must not own financial invariants through direct Eloquent CRUD.

CONFIG UPDATE:

transaction
→ lock config row
→ re-read
→ determine in-use state
→ update only if still eligible

GENERATION:

under the authoritative generation locks
→ identify config rows
→ lock referenced rates in deterministic id order
→ lock referenced cutoffs in deterministic id order
→ re-read/re-resolve after locks
→ create snapshot

Invariant at commit:

obligation.rate_id
and
obligation.base_amount_cop

describe the SAME locked decision.

Likewise:

obligation.cutoff_rule_id
and
obligation.due_on.

TESTS

- stale rate instance cannot overwrite after generation
- stale cutoff instance cannot overwrite after generation
- edit wins first => generation sees new value
- generation wins first => later edit reports in-use
- no deadlock with deterministic order

Use true two-connection PostgreSQL tests where practical.

======================================================================
9. CONFIGURATION DOMAIN ACTIONS + STRICT INPUT
======================================================================

Create clean actions/services for:

- create cutoff
- update cutoff
- create rate
- update rate

They must enforce independent of FormRequest:

- valid effective month
- valid amount
- valid cutoff day
- valid offset
- valid scope shape
- client/company existence where applicable
- historical/in-use immutability
- locking
- named uniqueness conflicts

This matters because A04/import will eventually call application services without
an HTTP FormRequest.

Do not build generic repositories or abstract CRUD frameworks.

STRICT MONTH VALIDATION

These must be 422:

2026-00-01
2026-13-01
2026-99-01

Never raw Carbon/DB 500.

If an unused cutoff rule is moved onto another rule's scope/effective month and a
named unique constraint is hit:

return controlled 409/422, not 500.

======================================================================
10. DATABASE EVIDENCE CONSTRAINTS — NEW MIGRATION ONLY
======================================================================

Inspect the published A03 obligations migration.

Generated obligations are intended to be explainable by their configuration.

Yet the schema currently allows:

rate_id NULL
cutoff_rule_id NULL

and cutoff_rule_id uses nullOnDelete.

The comments say evidence is retained, but the FK behavior can erase the
reference.

Use a NEW migration.

For source='generated':

- rate_id MUST be non-null;
- cutoff_rule_id MUST be non-null.

Preserve configuration evidence:

- rate remains RESTRICT;
- cutoff rule should not disappear from a referenced generated obligation;
  use RESTRICT or equivalent evidence-preserving behavior.

Relationship provenance must follow the deliberate multi-segment policy from
section 4.

Do not blindly require one assignment id if several legitimate source segments
can produce the one monthly obligation.

Before tightening:

- preflight existing rows;
- fail loudly with IDs if incompatible records exist;
- do not invent missing references;
- do not delete/repair economic history automatically.

Add DB-level tests bypassing Eloquent.

======================================================================
11. FIX GENERATION PREVIEW COUNTS AND MONEY
======================================================================

Current preview has semantic bugs.

`blocker_count` counts FINDINGS, not blocked candidate rows.

A single candidate missing both:

rate
cutoff

may produce:

candidate_count = 1
blocker_count = 2

The UI derives:

existing =
candidate_count - creatable_count - blocker_count

which can become negative.

Also `total_amount_cop` includes resolved existing rows, even if zero rows would
actually be created.

REQUIREMENTS

Publish explicit non-overloaded fields, e.g.:

candidate_count
existing_candidate_count
creatable_count
blocked_candidate_count
blocker_finding_count
creatable_amount_cop

Optionally retain a separate total resolved portfolio amount if useful, but name
it accurately.

Do not derive one semantic count by subtracting unrelated counts.

Generation result must publish amount ACTUALLY created in that execution.

Add tests:

- one candidate with two blockers
- mixed existing + blocked + creatable
- zero new obligations
- existing obligation must not inflate “amount to be created”

Fix UI summary accordingly.

======================================================================
12. BATCH GENERATION CONFIG RESOLUTION — PERFORMANCE
======================================================================

The candidate preview currently performs approximately:

1 rate query per candidate
up to 3 cutoff queries per candidate

A portfolio with 100 relationships can issue hundreds of queries.

Close-period completeness would inherit this cost.

Refactor candidate config resolution into bounded/batched queries.

Preserve exactly:

client+company cutoff
→ company cutoff
→ general cutoff

and newest applicable effective_month <= billing month.

Do not load the entire historical table if a bounded SQL solution is possible.

Add query-count regression:

10 candidates
100 candidates

Query count should stay approximately constant/bounded, not grow linearly by
3–4 queries per candidate.

======================================================================
13. PAYMENT ALLOCATION MODEL: SAME PAYMENT/SAME DEBT MUST SUPPORT
    INCREMENTAL APPLICATION — CRITICAL
======================================================================

Current DB partial unique index:

payment_allocations_live_pair_unique
(payment_id, obligation_id)
WHERE reversed_at IS NULL

prevents a normal workflow.

Example:

payment = 300000
obligation = 200000

apply 80000
remaining debt = 120000
remaining payment = 220000

Later applying another 120000 from THE SAME payment to THE SAME obligation is
currently refused.

This is not compatible with append-only financial actions.

It also causes a worse auto-allocation bug:

- oldest debt has a previous partial allocation from this payment;
- auto-allocate tries to finish oldest debt;
- unique index rejects it;
- code catches the conflict and CONTINUES;
- newer debt can be paid before older debt.

That violates oldest-first.

REQUIREMENTS

Use a NEW migration to remove the live-pair uniqueness restriction.

Multiple live allocation rows from one payment to one obligation are legitimate
financial actions.

Do NOT update the old allocation in place.

The existing sums naturally support multiple rows.

Keep conservation guarantees through row locks:

total live allocations on payment <= payment amount

total live non-voided money on obligation <= effective obligation amount

Every allocation remains individually auditable/reversible.

Remove domain logic that interprets same payment+obligation as automatically
duplicate.

Do not silently skip a DB collision and continue to a newer obligation.

TESTS

- payment 300k
- allocate 80k to 200k obligation
- allocate another 120k from same payment
- obligation paid
- payment still has 100k unapplied

- reverse only the second allocation
- first remains applied

- manual partial oldest debt
- auto-allocate same payment
- oldest debt completed before newer debt

- concurrent allocations serialize and never exceed either balance

ROLLBACK

If new legitimate data contains multiple live allocations for a pair, a rollback
cannot safely recreate the old unique index.

Do not merge/delete those rows.

Make rollback preflight/fail loudly if necessary.

======================================================================
14. AUTO-ALLOCATION MUST DECIDE AFTER LOCKS
======================================================================

Current `applyOldestFirst()` identifies outstanding obligations BEFORE acquiring
all obligation locks.

An obligation that was excluded during the stale pre-lock scan may become
outstanding before the transaction commits.

Refactor.

Required conceptual order:

CLIENT
→ PAYMENT
→ all relevant client OBLIGATION rows locked in ascending ID order
→ batch current adjustments/paid totals AFTER locks
→ decide which debts are outstanding
→ business sort:
      oldest period
      due date
      id
→ allocate

LOCK ORDER and BUSINESS ORDER are different concepts.

Locks:
ascending IDs.

Payment application:
oldest financial period first.

Do not perform per-obligation aggregate queries after locking.

Batch totals.

Add stale-state regression and query-count test.

======================================================================
15. AUTO-ALLOCATION PREVIEW MUST MATCH EXECUTION
======================================================================

The preview shown to an operator must describe the plan the subsequent action
would execute if no intervening change occurs.

Currently preview does not account correctly for the same-pair allocation
restriction and its own query path differs significantly from execution.

After section 13/14:

centralize the allocation planning arithmetic so preview and execution share the
same deterministic ordering/remaining-balance rules.

Preview writes nothing.

Execution must still re-read authoritatively under locks.

TEST

manual partial on oldest
→ preview says it will top up oldest
→ execute
→ exactly that happens.

======================================================================
16. MANUAL PAYMENT APPLICATION UX
======================================================================

Current payment modal defaults:

apply amount = entire unallocated payment

Example:

payment available = 300000
selected obligation balance = 200000

The modal defaults to 300000, which backend correctly refuses.

REQUIREMENTS

When an obligation is selected:

suggest/default:

min(
    payment unallocated amount,
    selected obligation balance
)

If the selected debt changes, refresh the suggested amount appropriately.

Client-side validation must also say when requested amount exceeds selected debt.

Server remains authoritative.

TEST

300k payment + 200k debt
→ modal proposes 200k
→ successful apply
→ 100k remains credit.

======================================================================
17. PAYMENT ALLOCATION HISTORY + REVERSAL UI
======================================================================

The backend supports reversing payment allocations, but the interface gives the
operator no complete workflow for it.

That makes a core correction action effectively API-only.

Add a payment detail/history surface, modal or page.

It must show:

- payment header
- allocations
- obligation period/company
- amount
- active/reversed status
- reversal reason
- relevant timestamps

For an active allocation and a user with payments.allocate:

offer:

Reverse allocation

with:

- explicit confirmation
- required reason
- useful success/error message

A reversed allocation remains visible.

Do not hide historical rows.

Optimize the detailed endpoint so displaying 30 allocations does not issue
presenter queries per allocation.

Use batch obligation presentation.

Vitest + Playwright:

allocate
→ inspect history
→ reverse allocation through UI
→ balance/credit return
→ historical allocation still visible as reversed.

======================================================================
18. ADJUSTMENT API CONTRACT IS CURRENTLY BROKEN — BLOCKER
======================================================================

Backend list currently returns approximately:

{
    items: [...],
    adjustments_total_cop: ...
}

Frontend service expects:

{
    adjustments: [...]
}

PeriodObligationsPage reads `.adjustments`.

This means the actual adjustment-history modal can receive undefined even though
the backend request succeeded.

Fix one canonical contract.

Prefer the backend's standard:

items
adjustments_total_cop

unless there is a strong reason otherwise.

Update:

- TypeScript
- api.ts
- component
- frontend tests
- ResponseShapeTest
- E2E

Open the history modal in a real browser test.

======================================================================
19. ADJUSTMENT REVERSAL STATE IS MODELED INCORRECTLY IN THE API/UI
======================================================================

The database does NOT mark an adjustment row with:

reversed_at
reversed_by
reversal_reason

The real ledger design is:

original adjustment
+
new reversal adjustment with:
    reverses_adjustment_id = original.id
    opposite delta
    reversal reason

That design is good.

But the API/UI pretends marker fields exist on the original.

As a result the original can continue to appear “vigente” and offer a second
Revertir button even after it has been reversed.

Also inspect/fix `ObligationAdjustment::isActive()`; its current boolean logic
does not correctly identify an original that has a reversal row.

REQUIREMENTS

Model the actual ledger truth.

Useful response fields:

is_reversal
is_reversed
reversed_by_adjustment_id
reversal_created_at
reversal_reason

Names may differ, but their meaning must be derived from the reversal relation,
not nonexistent columns.

For a reversal row:

show that it reverses adjustment #X.

For an original that has been reversed:

- mark as reversed;
- no second reversal button.

No N+1 per adjustment.

Use eager/batch mapping.

TESTS

original
→ live

reverse original
→ original is_reversed=true
→ reversal row points to original
→ effective sum correct
→ UI shows both
→ UI no longer offers second reversal.

======================================================================
20. ADJUSTMENT TYPES / SIGN SEMANTICS
======================================================================

Backend enum currently includes:

discount
surcharge
correction
credit
reversal

Frontend offers:

discount
surcharge
correction

and omits credit.

Frontend also uses `parseInt`, so input such as:

50000abc

can be interpreted as:

50000.

Additionally, frontend forces:

discount => negative
everything else => positive

so a negative correction cannot be represented through UI.

Make one explicit semantic contract.

Preferred contract:

discount:
    must reduce amount

credit:
    must reduce amount

surcharge:
    must increase amount

correction:
    may increase OR reduce; UI must make direction explicit

reversal:
    system-only, never selectable

Enforce the same contract in application/domain code so API and UI cannot
disagree.

Do not accept:

discount +50000
surcharge -50000

if labels claim the opposite.

Use the shared strict `pesosDesdeTexto` parser.

No parseInt garbage acceptance.

Expose adjustment vocabulary from backend if that avoids another duplicated
frontend enum.

TESTS

- credit visible
- reversal never user-selectable
- positive/negative correction
- bad money text refused
- invalid adjustment enum => 422, never ValueError/500.

======================================================================
21. FIX DEFAULT CARTERA OVERDUE FILTER — BLOCKER
======================================================================

Frontend unchecked checkbox sends:

overdue=false

Query serializer keeps false.

Controller parses false.

Service currently applies overdue-only whenever the key is non-null.

Therefore:

overdue=true
and
overdue=false

both filter to overdue debts.

The normal Cartera screen hides pending debts that have not yet reached due date.

SEMANTICS

overdue absent/false:
    no overdue-only restriction

overdue true:
    only currently overdue outstanding debt

TESTS

one not-yet-due debtor
one overdue debtor

GET /receivables
=> both

GET /receivables?overdue=false
=> both

GET /receivables?overdue=true
=> overdue only

Vitest + Playwright default screen proof.

======================================================================
22. CARTERA EXACT OUTSTANDING PERIODS AND COMPANIES
======================================================================

Current `owed_periods` aggregates all obligation period keys, including paid
obligations.

Example:

January paid
February pending

must report:

owed_periods = [2026-02]

not:

[2026-01, 2026-02]

Also:

client owes Company B
old Company A obligation is fully paid

the “Empresas” field in the debtor row must not imply Company A is part of the
current outstanding debt.

REQUIREMENTS

Debt-specific display fields derive ONLY from balance > 0:

- owed_periods
- debt company names/ids
- oldest_due_on
- aging basis

Deduplicate periods.

Two companies both owed in March:

owed_periods = [2026-03]

not twice.

Keep counts that intentionally count obligations named accordingly.

Align client account and cartera list semantics.

======================================================================
23. OLDEST OUTSTANDING DEBT USES MIN, NOT MAX
======================================================================

Current grouped query uses:

max(due_on) as oldest_due_on

That is the newest date.

UI says:

desde <date>

and aging is based on it.

Use earliest OUTSTANDING due date:

MIN(due_on)
FILTER (WHERE balance_cop > 0)

or equivalent.

Paid historical debts must not become the oldest current debt.

TESTS

old unpaid + recent unpaid
=> old date

old paid + recent unpaid
=> recent unpaid date.

======================================================================
24. TRAFFIC LIGHT COUNTS PERIODS, NOT OBLIGATION ROWS
======================================================================

The TrafficLight domain language says:

periodos vencidos

But ReceivablesService counts overdue obligation rows.

A client with TWO companies owing in the SAME March currently looks like two
late periods.

That can incorrectly move:

yellow → orange

even though only one monthly period is late.

REQUIREMENTS

Traffic light count:

DISTINCT overdue period/month

Keep separately named:

overdue_obligations_count

if useful.

Traffic-light filtering and displayed reason must use the distinct overdue
period count.

TEST

two overdue obligations in same month across two companies
=> 1 overdue period
=> yellow

two different overdue months
=> orange.

======================================================================
25. DUE-TODAY CONSISTENCY
======================================================================

ObligationTotals normalizes the reference date to calendar day.

SQL cartera compares DATEs.

But ReceivablesService's individual hydration can compare:

now at 11:00

against:

due_on at 00:00

and call a debt overdue during its own due date.

A debt due today must have ONE answer everywhere.

Required rule already used elsewhere:

due today is NOT overdue.

Normalize the aging reference to a calendar date/startOfDay in every path.

TEST with clock:

2026-04-10 14:30 America/Bogota
due_on=2026-04-10

Assert consistent across:

- ObligationTotals
- ObligationPresenter
- clientAccount
- receivables list
- dashboard summary

not overdue.

======================================================================
26. `as_of` IS NOT CURRENTLY A HISTORICAL FINANCIAL SNAPSHOT
======================================================================

Current `as_of` changes aging/overdue calculation.

It does NOT reconstruct balances as they existed historically because current:

- adjustments
- allocations
- reversals
- voids

still participate regardless of when they happened.

The UI wording can imply a historical account statement.

Do not pretend this is historical accounting.

LOW-SCOPE CORRECT FIX

Keep the existing calculation but explicitly define `as_of` as:

aging reference date

Rename UI wording to something like:

“Calcular antigüedad al”
“Antigüedad evaluada al”

Do not say:

“saldo al día de”
“estado histórico al”

unless the service is actually rewritten to time-filter every financial event.

Document the contract.

A true historical ledger view is out of scope unless already explicitly required.

======================================================================
27. CARTERA AGGREGATE FILTERS AND PAGINATION MUST AGREE
======================================================================

A cartera row represents ONE CLIENT.

Therefore filters shown as client-row filters must operate at the grouped client
level where appropriate.

FIX:

A. traffic_light

Currently applied after `$total` is calculated.

`total`
`last_page`
range label

must describe the rows AFTER traffic-light filtering.

B. minimum_balance / maximum_balance

Current logic filters individual obligations.

Example:

600000 Jan
600000 Feb

client total = 1200000

minimum_balance=1000000

must include the client.

C. outstanding_only

Define exact semantics.

If false allows zero-balance clients, then:

total
pagination
summary

must use the same selected population.

D. settlement_state + outstanding_only

Avoid silently contradictory default semantics.

If asking for paid obligations, the default debtor-only aggregation must not make
the filter look broken.

E. summary

The summary cards must explicitly follow the active filter contract.

Prefer list and summary answering the same filtered question.

TEST all combinations needed to prove consistency.

======================================================================
28. STRICT RECEIVABLE FILTER VALIDATION
======================================================================

Do not call Enum::from / Carbon::parse / month parser directly on arbitrary user
input.

Use a dedicated FormRequest/validated DTO.

Validate:

search
client_id
company_id
period_from
period_to
settlement_state
aging_bucket
traffic_light
minimum_balance
maximum_balance
aging/as_of date
overdue
outstanding_only
page
per_page

Unknown enum:
422.

Unknown traffic light:
422.

Never map unknown traffic light to red.

Invalid date/month:
422.

Cross-field:

period_from <= period_to
minimum_balance <= maximum_balance

when both provided.

IDs should be validated appropriately without leaking unnecessary record data.

======================================================================
29. STRICT PAYMENT LIST FILTER VALIDATION
======================================================================

Dedicated validated request for:

search
method
state
date_from
date_to
requires_reconciliation
page
per_page

Invalid enum/date:
422.

date_from > date_to:
422.

Fix case normalization.

Current query:

lower(column) LIKE raw needle

can fail for:

Ana

against:

Ana María.

Normalize the needle and consistently escape LIKE wildcards, using the project's
existing safe-search approach where available.

======================================================================
30. PAYMENT PAGE BOOLEAN FILTER SEMANTICS
======================================================================

Audit the same pattern as Cartera for:

requires_reconciliation=false

The frontend sends the boolean even when unchecked.

Ensure:

false/absent:
    no “only unreconciled” restriction

true:
    only payments requiring reconciliation.

Add regression test so the Cartera bug is not duplicated in Payments.

======================================================================
31. BILLING SETTINGS CLIENT EXCEPTION UI — BLOCKER
======================================================================

Backend correctly models client cutoff scope as:

CLIENT + COMPANY

because a client can be attached to several companies.

Frontend currently asks only for client and sends company_id=null.

Fix.

For scope=client show:

- client picker
- company picker

Require both.

Send company_id for:

- company scope
- client scope

List representation for client-specific rule must make both visible:

Client · Company

or equivalent.

Do not make the exception appear to apply to every employer.

======================================================================
32. BILLING SETTINGS SEARCH ACTUALLY MUST SEARCH
======================================================================

Frontend sends:

cutoffRules({ search })
rates({ search })

Backend ignores it.

Implement server-side search.

Cutoffs may reasonably match:

- client name/document
- company name
- notes where useful

Rates:

- client name/document
- company name

Use safe LIKE/ILIKE escaping.

Search must combine correctly with scope/company/client filters.

======================================================================
33. BILLING SETTINGS PAGINATION
======================================================================

Backend paginates 50.

Frontend reads only `.items` and exposes no navigation.

After 50 records, configuration silently disappears from the operator's view.

This is especially bad for cutoff rules because list order can put the newest
rules on another page.

Add independent pagination state for:

- cutoff rules
- rates

with:

page
per_page
last_page
range/total

Search resets to page 1.

Backend accepts bounded per_page.

Do not fetch thousands of historical config rows just to avoid pagination.

======================================================================
34. BILLING SETTINGS N+1 `in_use`
======================================================================

`describeRule()` performs an `exists()` query per cutoff rule.

`describeRate()` performs an `exists()` query per rate.

A 50-row page can cost approximately 50 extra queries.

Batch this.

Use withExists/grouped lookup.

Add query-count regression at 50/100 rows.

======================================================================
35. BILLING SETTINGS PERMISSION COMPOSITION
======================================================================

Route `/settings/billing` currently uses only:

cutoffs.view

But the page contains TWO independent domains:

cutoffs
rates

and always loads both.

Consequences:

- rates.view-only user cannot navigate there;
- cutoffs.view-only user enters but background rate request returns 403;
- a page can show a tab the user has no permission to read.

Fix route and component composition.

The application router currently understands only one `permission`.

Extend route metadata cleanly with something equivalent to:

permissionsAny
permissionsAll

Do not scatter custom conditional hacks.

For billing settings:

route accessible when user can see at least one:
cutoffs.view
rates.view

Component:

- render only permitted tabs;
- load only permitted endpoint;
- select first permitted tab;
- never make a hidden forbidden background request.

Tests for:

cutoffs-only
rates-only
both
neither.

======================================================================
36. PERIOD / OBLIGATIONS PERMISSION COMPOSITION
======================================================================

Frontend route to:

/periods/:id/obligations

checks only:

obligations.view

but the page also requests period detail requiring periods.view.

Backend period-obligation endpoint itself effectively depends on both.

Make this contract explicit.

Preferred:

frontend route:
permissionsAll:
    periods.view
    obligations.view

and backend chain matches.

For GENERATION PREVIEW clarify permissions.

A user with authority to generate should not be forced into a nonsensical state
where:

generate permission exists
but preview needed for safe generation is forbidden.

Choose and document the intended permission combination.

Do not weaken financial read permissions accidentally.

Tests with custom minimal roles.

======================================================================
37. PERIOD LIST FINANCIAL DATA PERMISSION LEAK
======================================================================

`periods.view` currently exposes:

obligation_count
total_base_cop
total_effective_cop
total_paid_cop
total_balance_cop

even without:

obligations.view.

A03 deliberately separates permissions.

Preferred contract:

periods.view:
    period metadata/state/generation metadata

obligations.view:
    obligation-derived counts/money

When obligations.view is absent:

omit obligation-derived keys.

Do not return fake zeroes.

Make TypeScript fields optional.

UI conditionally renders the financial columns/cards.

Custom-role tests.

======================================================================
38. PERIOD LIST FRONTEND PAGINATION
======================================================================

Backend paginates periods at 25.

PeriodListPage currently ignores pagination.

After 25 months, historical periods silently disappear.

A monthly financial application WILL reach that threshold.

Add proper:

page
per-page
total
last-page
range
previous/next

or an equivalent usable paginator.

Do not just request 10,000 rows.

Test with >25 periods.

======================================================================
39. FRONTEND DATE-ONLY FORMATTER IS TIMEZONE-UNSAFE — BLOCKER
======================================================================

The shared formatter currently does:

new Date('YYYY-MM-DD')

then local toLocaleDateString.

JavaScript parses the bare ISO date at UTC midnight.

In Colombia:

UTC-05

that instant is the previous evening locally.

A financial due date such as:

2026-04-03

can therefore render as:

2026-04-02

depending on runtime/browser behavior.

Do NOT convert calendar-only dates through an instant.

Separate:

A. DATE-ONLY values:
YYYY-MM-DD
due date
received date
effective date

Preserve their calendar components exactly.

B. TIMESTAMP values:
created_at
reversed_at
etc.

Those are real instants and may be timezone-converted.

Fix shared formatter.

Remove duplicated unsafe date formatting such as ClientDetailPage where
possible.

TEST explicitly:

fecha('2026-04-03')
under America/Bogota semantics

=> April 3
never April 2.

Add Playwright date-display assertion with timezoneId America/Bogota or
equivalent.

======================================================================
40. DASHBOARD: “RECAUDADO” IS NOT THE CURRENT METRIC
======================================================================

Dashboard `total_paid_cop` is derived from allocations applied against
obligations.

That is NOT total money received.

Example:

payment received = 300000
allocated = 0

Dashboard currently may say:

Recaudado = 0

even though 300000 arrived.

Do not label applied money as “Recaudado”.

Preferred financial summary:

total_received_cop
total_applied_cop
unallocated_credit_cop
outstanding_balance_cop
overdue_balance_cop

with conservation for non-voided payments:

received = applied + unallocated

If retaining only the current metric:

rename it accurately to:

Aplicado a obligaciones

But explicit received/applied/credit values are preferable.

Do not count voided payments as received/current money.

TEST:

300k unapplied payment

dashboard:
received 300k
applied 0
credit 300k.

======================================================================
41. DASHBOARD LINK PERMISSIONS
======================================================================

Financial dashboard is shown with:

receivables.view.

One of its cards links to:

payments

which requires:

payments.view.

A receivables-only role should not be given an active navigation link to a route
the router immediately forbids.

If payments.view absent:

render the metric as a non-link stat.

Likewise review every A03 Dashboard link against its own target permission.

Frontend test with minimal role.

======================================================================
42. PAYMENT PAGE MUST NOT SECRETLY REQUIRE RECEIVABLES.PERMISSION
======================================================================

PaymentListPage uses the receivables vocabulary endpoint to populate payment
methods.

Therefore a user who legitimately has:

payments.view/payments.create

but not:

receivables.view

gets an empty payment-method select.

The backend payment list already returns method vocabulary.

Use the payments domain's own response/endpoint for payment vocabulary.

Likewise manual allocation currently loads:

clientAccount

through receivables.view.

Do not make payments.allocate secretly depend on receivables.view unless that is
an explicit documented permission dependency.

Preferred:

provide a minimal payment-authorized endpoint for:

eligible outstanding obligations for this payment/client

or another payment-specific read contract.

It must reveal only the data needed to allocate.

Also review the client picker dependency on `clients.view`.

Either:

- explicitly make the read permission dependency part of the role contract;
  OR
- provide a minimal authorized picker endpoint.

Do not leave a granted write permission unusable from the UI because another
unrelated page permission is absent.

Custom-role tests.

======================================================================
43. PAYMENT DETAIL TYPES / API CONTRACT DRIFT
======================================================================

Perform a complete A03 API ↔ TypeScript contract audit.

Known drift includes:

GenerationResultPayload expects one shape while backend returns another.

PaymentAllocationSummary expects fields such as:

payment_id
obligation_label
reversed_by
created_at

but PaymentController detailed allocation currently returns a different shape
including:

is_reversed
obligation

and omits several declared fields.

Adjustment history has another mismatch described earlier.

REQUIREMENTS

For every A03 frontend-consumed response:

- define one canonical backend shape;
- TypeScript mirrors it exactly;
- api.ts mirrors it exactly;
- Vue uses actual keys;
- tests/mocks mirror the real backend;
- ResponseShapeTest verifies keys the UI actually consumes.

Do not retain fictional optional keys just to make TypeScript quiet.

======================================================================
44. PAYMENT DETAIL QUERY GROWTH
======================================================================

When payment detail includes multiple allocations, it currently presents each
allocation's obligation independently.

Avoid N+1 presenter work.

Load/present allocation obligations in batches.

Add query-count test:

payment with 1 allocation
payment with 30 allocations

response query count remains bounded, not +2/+3 per allocation.

======================================================================
45. RECEIVABLES / CLIENT ACCOUNT QUERY SEMANTICS
======================================================================

After correcting Cartera, ensure one financial formula is used everywhere for:

effective
paid
balance
overdue
aging
settlement state

No surface-specific arithmetic drift.

Parity-test:

- individual presenter
- client account
- cartera list
- period obligation list
- dashboard aggregates

for a scenario including:

base
positive adjustment
negative adjustment
partial allocation
reversed allocation
voided payment.

======================================================================
46. QUERY GROWTH: AUTO-ALLOCATION
======================================================================

`applyOldestFirst()` currently calls balance aggregate helpers repeatedly per
candidate.

A client with long history can cause hundreds of queries.

While fixing post-lock correctness:

batch fetch:

- adjustments by obligation
- active allocations by obligation
- current payment allocations

after obligation locks.

Do not call two aggregate queries per obligation.

Add query-count regression:

5 obligations
50 obligations

The number of queries should remain bounded/near-constant.

======================================================================
47. STRICT ADJUSTMENT/PAYMENT/CONFIG ENUM VALIDATION
======================================================================

At HTTP boundary:

unknown adjustment type
unknown payment method
unknown payment state
unknown settlement state
unknown aging bucket
unknown traffic light

must never reach `Enum::from()` unchecked.

422 with useful field errors.

No ValueError 500.

Use Rule::enum or explicit allowed enum values.

System-only enum cases such as adjustment reversal must be excluded from user
input.

======================================================================
48. E2E: RESTORE THE ORIGINAL BUSINESS-RISK JOURNEYS
======================================================================

Keep the current A03 browser journeys.

They are useful.

DO NOT delete existing adjustment/void/auto-allocation coverage just to make room.

Add focused browser journeys for the missing acceptance risks.

A. CUTOFF HIERARCHY THROUGH REAL UI

general cutoff
→ company override
→ client+company override
→ preview/generate
→ most specific rule wins
→ due_on snapshot verified

The three rule levels must be configured through BillingSettingsPage, not only
direct API helpers.

B. MANUAL PARTIAL PAYMENT

debt 200000
payment 80000
manual allocate through UI
→ partial
→ 120000 remains

C. SAME PAYMENT INCREMENTAL APPLICATION

payment 300000
debt 200000
apply 80000
then another 120000 FROM SAME PAYMENT
→ paid
→ 100000 credit remains

D. PAYMENT ALLOCATION REVERSAL

open payment history
reverse one allocation
→ debt returns
→ credit returns
→ historical row remains visible

E. COMPLETE PAYMENT / CARTERA

finish a debt
→ client/period no longer appears as outstanding debt

F. PREPAYMENT ACROSS PERIODS

payment greater than current debt
→ settle current debt
→ remainder remains unapplied
→ later obligation generated
→ explicitly apply old remainder
→ conservation verified

G. READ ONLY

may read every authorized A03 screen
cannot:

create period
generate
close/reopen
create/edit rates
create/edit cutoffs
create payment
allocate
reverse allocation
void
adjust/reverse adjustment

Direct API mutation => 403.

H. CARTERA

default view shows not-yet-due outstanding debt
overdue toggle narrows correctly
exact owed periods
oldest outstanding due
traffic light counts distinct months
text explanation visible

I. ADJUSTMENT HISTORY

create adjustment
open history modal
reverse
original visibly becomes reversed
reversal row visible
no second reverse action

J. TIMEZONE

date-only due/payment date shown unchanged in America/Bogota.

======================================================================
49. E2E DEVELOPMENT-SAFETY FINGERPRINT MUST INCLUDE A03
======================================================================

Current development fingerprint primarily covers A02 rows.

Extend it to include at minimum:

monthly_periods
cutoff_rules
client_company_rates
monthly_obligations
obligation_adjustments
payments
payment_allocations

Keep the existing A02 tables too.

The guarantee is now:

E2E never writes directory OR financial development data.

Do not use destructive cleanup against development.

No substring deletion.

No guessed record deletion.

======================================================================
50. E2E FINGERPRINT MUST TARGET THE ACTUAL CONFIGURED DEV DB
======================================================================

`development_fingerprint()` currently hardcodes values such as the default
development DB.

Use the validated project environment values.

Do not claim the configured development database remained untouched while
actually fingerprinting another hard-coded database.

Validate identifiers before interpolation.

Fail closed on unsafe/unexpected DB names.

======================================================================
51. E2E FAILURE PATH MUST STILL RUN SAFETY CHECKS
======================================================================

`run-e2e.sh` uses:

set -euo pipefail

and currently runs Playwright directly before:

status=$?

A Playwright failure may terminate the script before:

- development AFTER fingerprint;
- comparison;
- intended E2E cleanup/report;
- diagnostic safety output.

Capture Playwright failure deliberately:

if playwright ...; then
    status=0
else
    status=$?
fi

or equivalent.

Then ALWAYS execute critical safety verification.

Finally exit with appropriate status.

If both tests and safety verification fail, safety failure must not be hidden.

Test the failure path intentionally against disposable E2E state.

======================================================================
52. E2E REDIS ISOLATION
======================================================================

Keep:

development Redis DB/prefix
separate from E2E Redis DB/prefix

and keep the E2E-specific API rate-limit override.

Normal API limit remains:

120/minute

E2E override remains:

2000/minute

Do not loosen production/default policy.

Where practical, prove E2E reset/run does not flush the development Redis
namespace.

No `cache:clear` against development.

======================================================================
53. PERFORMANCE REGRESSION SUITE
======================================================================

Keep existing query-count tests and add focused ceilings/growth checks for:

- period list >25 months
- cutoff list 50/100
- rate list 50/100
- generation preview 10 vs 100 candidates
- payment detail 1 vs 30 allocations
- auto-allocation 5 vs 50 obligations
- cartera 15/100 debtors where practical

Use ceilings or growth invariants, not brittle exact query counts.

The important property:

query count should not grow one/two/three queries PER displayed business row.

======================================================================
54. RESPONSE SHAPE TESTS MUST REPRESENT REAL UI CONTRACTS
======================================================================

Expand ResponseShapeTest to cover:

- actual generation result keys
- actual adjustment-list keys
- derived reversal state
- actual payment allocation detail keys
- payment method vocabulary source
- optional period monetary keys under RBAC
- dashboard received/applied/credit keys
- receivable distinct overdue period count if exposed

Do not test keys the UI no longer reads.

Do not keep dead TypeScript interfaces merely because old tests expect them.

======================================================================
55. SECURITY / PRIVACY PERMISSION PROBES
======================================================================

Use minimal custom users, not only seeded roles.

Probe at least:

periods.view only
obligations.view only
periods.view + obligations.view
cutoffs.view only
rates.view only
receivables.view only
payments.view only
payments.allocate without receivables.view
Read Only
Collections
Operations

Verify:

- no forbidden background API calls;
- no hidden financial totals;
- no forbidden RouterLinks;
- server still returns 403 even if UI is bypassed.

======================================================================
56. DOCUMENTATION MUST MATCH THE REAL MODEL
======================================================================

Update docs/TASKS/A03.md and important class comments.

Fix stale claims.

Known examples:

- there are 16 A03 permissions, not “Fifteen”;
- generation lock documentation must mention the real shared topology/config
  protocol;
- adjustment reversal is a new row, not marker columns;
- traffic light counts overdue periods, not obligations;
- `as_of` is aging reference unless true historical reconstruction is added;
- one monthly obligation may come from several legitimate non-overlapping
  relationship segments if that policy is adopted;
- allocation model permits repeated explicit partial allocation of the same
  payment to the same obligation;
- payment received/applied/unallocated terminology must be precise.

Do not document aspirational behavior that code does not actually implement.

======================================================================
57. MIGRATION TESTING
======================================================================

Use NEW migrations only.

Test:

A. fresh empty database from zero
B. upgrade exact baseline 7af0f9f → R1
C. upgrade a realistic A03 development dataset
D. incompatible rows for new evidence constraints:
   migration fails loudly and identifies them
E. allocation unique removal
F. legitimate repeated allocations after migration
G. DB CHECK/FK constraints by direct SQL/Eloquent bypass where relevant

Do not edit old migration timestamps to make tests green.

Do not auto-delete or auto-close user data.

======================================================================
58. FULL REGRESSION
======================================================================

Run all of:

vendor/bin/pint --test
vendor/bin/pest

npm run test
npm run typecheck
npm run lint
npm run build

./scripts/run-e2e.sh

./scripts/test-reset-test-db.sh
./scripts/test-reset-e2e-db.sh

composer validate --strict
composer audit
npm audit

Also verify:

- fresh install
- upgrade from 7af0f9f
- application boot
- no unexplained HTTP 500 in A03 probes
- no browser console errors
- no hidden failed requests
- development DB unchanged by E2E
- development Redis namespace unchanged
- E2E database disposable
- no development data cleanup logic reintroduced

Run a tracked-secret scan over committed files.

Do not print secret values into the report.

======================================================================
59. GIT RULES
======================================================================

PRESERVE:

7af0f9f545ec94692c8c0c4301f872050c3c37c0

and every commit before it.

DO NOT:

git commit --amend
rebase published history
force push
force-with-lease

Create ONE remediation commit unless a migration/tooling reason genuinely
requires more:

A03-R1: close financial correctness and integration gaps

Push normally to:

origin/main

Use the restored WSL credential helper.

Verify after push:

HEAD == origin/main

and:

git status
=> working tree clean

A04 must remain untouched.

======================================================================
60. REVIEW ARCHIVE
======================================================================

After the final commit and successful push:

./scripts/export-review.sh

Verify the produced archive with the script.

The archive must come from committed HEAD.

Do not manually zip the project directory.

Return its path.

======================================================================
61. FINAL REPORT FORMAT
======================================================================

STOP AFTER A03-R1.

DO NOT START A04.

Return:

A03-R1 STATUS:
PASS / PARTIAL / FAIL

BASELINE:
7af0f9f...

FINAL COMMIT:
...

MONTH/PERIOD:
- month normalization
- current period
- duplicate-period concurrency
- period pagination

RELATIONSHIP BILLING:
- empty interval behavior
- sequential same-company segments
- true overlap behavior
- provenance model

STRUCTURAL CONCURRENCY:
- shared topology protocol
- lock order
- generation vs A02
- close vs A02
- true two-connection tests performed

PERIOD CLOSE:
- completeness rule
- missing candidates
- blockers
- empty month

CONFIGURATION:
- rate actions
- cutoff actions
- strict month validation
- row locks
- generation serialization
- evidence constraints
- hierarchy

GENERATION:
- existing obligation semantics
- preview counts
- preview amounts
- batch resolution
- query counts

ADJUSTMENTS:
- response contract
- reversal model
- derived status
- sign semantics
- credit type
- UI history
- reversal UI

PAYMENTS:
- repeated same-pair allocations
- migration
- manual partial
- manual prepayment
- auto-allocation
- preview parity
- allocation reversal UI
- conservation
- payment detail query count

CARTERA:
- overdue=false
- owed periods
- outstanding companies
- oldest due
- due today
- traffic distinct periods
- aggregate balance filters
- traffic pagination total
- outstanding_only semantics
- filter validation
- aging-reference wording

DASHBOARD:
- received
- applied
- unallocated
- labels
- target-link permissions

DATES:
- date-only timezone handling
- America/Bogota browser proof

RBAC:
- permission any/all support
- period monetary visibility
- billing settings split permissions
- payment page dependencies
- minimal-role probes

FRONTEND/API CONTRACT:
- GenerationResult
- adjustments
- payment allocations
- vocabulary
- ResponseShapeTest

PAGINATION:
- periods
- cutoff rules
- rates

PERFORMANCE:
report query-count results for:
- generation 10/100
- cutoffs 50/100
- rates 50/100
- payment allocations 1/30
- auto-allocation 5/50
- receivables

E2E:
- previous flows retained
- new flows added
- exact Playwright count
- cutoff hierarchy UI proof
- manual partial
- same-payment incremental
- reversal
- prepayment
- Read Only
- Cartera
- adjustment history
- Bogota date proof

E2E SAFETY:
- A02 fingerprint tables
- A03 fingerprint tables
- actual configured development DB
- failure-path verification
- Redis isolation

MIGRATIONS:
- new migration names
- fresh
- upgrade from baseline
- realistic upgrade
- rollback limitations if any

TEST RESULTS:
exact command + exact result for:
Pint
Pest
Vitest
typecheck
lint
build
Playwright
reset safety scripts

SECURITY AUDITS:
composer audit
npm audit
tracked-secret scan

GIT:
HEAD
origin/main
push result
working tree

REVIEW ARCHIVE:
absolute path
verification result

KNOWN ISSUES:
Every remaining issue, even if non-blocking.

If none:
"None known within A03 scope."

NEXT:
A04 remains blocked pending external GitHub review of A03-R1.