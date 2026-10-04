PROJECT: CONSULTORA DH

TASK:
A03-R4 — Close four residual contract/hardening defects

BASELINE COMMIT:

5e8f0e2c989216eb62471c5f0b5be60268d6b44e
A03-R3: close final A03 audit findings

CLOSING COMMIT:

The single A03-R4 commit on `main` immediately after
5e8f0e2c989216eb62471c5f0b5be60268d6b44e.

A commit cannot contain its own hash; the SHA is reported in the final
response instead.

A04 remains BLOCKED and was not started. This was a micro pass: four
findings, no search for a redesign, and no R1/R2/R3 behaviour reopened.

======================================================================
1. PERIOD-OBLIGATION AUTHORIZATION RAN AFTER VALIDATION
======================================================================

ROOT CAUSE

`ListPeriodObligationsRequest::authorize()` returned `true` and left the
`obligations.view` check to `authorizeFinancial()` in the controller body.
Laravel resolves a FormRequest — `authorize()`, then `rules()` — before the
controller is entered, so the order was:

    GET /periods/1/obligations                  -> 403
    GET /periods/1/obligations?as_of=garbage     -> 422 errors.as_of

Authorization depended on whether the caller who may not read this data
happened to guess the query format correctly. The 422 was also the more
revealing order: it confirmed the endpoint exists and what it validates
before deciding whether this caller may see anything.

FIX

`authorize()` now returns `$this->user()?->can('obligations.view') ?? false`.
The route middleware still enforces `periods.view`, so the endpoint contract
is unchanged — `periods.view` AND `obligations.view` — and only the ordering
is deterministic now. The controller keeps its own assertion, redundant on
purpose.

The strict `as_of` validation R2 §7 established is untouched: a caller who
may read this still gets a 422 for a date it cannot read.

TESTS

tests/Feature/A03/PermissionBoundaryTest.php

  - a `periods.view`-only user gets 403 for seven different queries, including
    `as_of=garbage`, `per_page=9999`, `page=abc` and an unknown filter. Every
    one must be 403; a 422 on any of them is the defect.
  - a caller with both permissions still gets 422 `errors.as_of` for a bad date
    and 200 for a good one.

Verified failing against the unfixed request.

======================================================================
2. THE ADJUSTMENT VOCABULARY HAD TWO PERMISSION CONTRACTS
======================================================================

ROOT CAUSE

The route is inside `can:obligations.adjust` and the interface only requests
the vocabulary when it holds `obligations.adjust`. `adjustmentVocabulary()`
checked `obligations.view`. The two disagreed, and neither side could see it:
a caller with `.adjust` passed the route and was then refused by the
controller, while a caller with `.view` never reached the route to be refused
by it.

FIX

The controller checks `obligations.adjust`. Not both permissions: the
vocabulary is the list of adjustment types and the direction each moves the
total, which is the minimum somebody needs in order to perform the action
they are already authorized to perform. Requiring `obligations.view` to read
it would stop a person who may correct a figure from opening the form that
corrects it — the same inversion R3 §8 found in the dialogs' gating.

The route is unchanged. The frontend is unchanged.

TESTS

tests/Feature/A03/ObligationAdjustmentTest.php

  - `.adjust` only: 200, with a non-empty `types` array whose entries carry
    `value`, `label` and `direction`. The non-empty assertion matters: an
    endpoint answering nothing to everybody would otherwise pass.
  - `.view` only: 403.
  - neither: 403.
  - the refusal does not depend on the query string, for the §1 reason.

Verified failing against the unfixed controller.

======================================================================
3. THE PERIOD SUMMARY STILL FAILED OPEN
======================================================================

ROOT CAUSE

`PeriodController::summarise()` declared `bool $withMoney = true`. Every
call site passed the gate explicitly, so there was no leak in the current
responses — but the helper was fail-open, so the next call site that forgot
the argument would have published `total_base_cop`, `total_effective_cop`,
`total_paid_cop`, `total_balance_cop` and `obligation_count`.

Three separate rounds have now found a leak of exactly that shape: R2 on
`current()` and `show()`, R3 on `close()` and `reopen()`. Every one was a
forgotten argument rather than a wrong gate, which is what makes the default
the thing worth fixing.

FIX

`bool $withMoney = false`. Only the default changed. `maySeeMoney()` is
untouched, every call site still passes the gate explicitly, the permission
policy is unchanged, and the responses are identical.

The distinction the finding draws is the right one: a regex over the source
can only *notice* the mistake after somebody has made it, whereas a closed
default makes the mistake cost a missing key on a screen that then renders
without a figure. One is a bug that shows nothing; the other is a bug that
shows money.

TESTS

tests/Feature/A03/PeriodMoneyBoundaryTest.php

  - new: a `ReflectionMethod` assertion that `summarise()`'s `withMoney`
    parameter exists, has a default, and that default is `false`. Asserted on
    the parameter rather than by reading the file, because the parameter *is*
    the invariant.
  - kept: R3's source-inspection example that fails on any `summarise()` call
    omitting the argument. It is a backstop for a caller that passes the
    argument wrongly, which the default cannot catch, so both earn their place.

Verified failing against the baseline (whose default was `true`, confirmed by
reflection on the stashed tree).

======================================================================
4. THE ALLOCATION REVERSAL STILL HARDCODED THE MINIMUM
======================================================================

ROOT CAUSE

`PaymentListPage`'s `reversalProblem` read

    reversalReason.value.trim().length < 10

while the same file already imported `reasonIsLongEnough()` for the void
flow. Both values were 10, so it was functionally correct and structurally a
second implementation of one rule: the next change to `REASON_MIN_LENGTH`
would have moved the void dialog and left this one behind, and the reversal
would have started accepting a reason the server refuses.

FIX

`reversalProblem` is now a call to the shared predicate, returning the shared
sentence. The `REASON_MIN_LENGTH` import is gone from the page. No backend
minimum changed.

There is no independent literal `10` implementing this rule anywhere in the
frontend's financial reason dialogs.

TESTS

tests/frontend/portfolio/a03FormGating.spec.ts

  - extended boundary case: nine trimmed characters disabled, exactly ten
    enabled, asserted on the void dialog and the reversal dialog **in the same
    example**, because the claim is that they agree — two examples each proving
    its own boundary would pass even if the two disagreed with each other.
  - new structural example asserting both reason fields go through the shared
    predicate and that the page contains no bare-number comparison and no
    re-declared constant.

On the structural example: the boundary case **cannot** catch this defect, and
it is worth saying why rather than pretending otherwise. A literal `< 10` and
`reasonIsLongEnough()` behave identically for every possible input while both
values are 10, so no input separates them. I checked — with the literal
restored, all fourteen behavioural examples still passed. The duplication is
only dangerous the day the constant changes, and on that day the failure is a
dialog that quietly starts accepting what the server refuses. So this asserts
the shape of the code, not its behaviour; the alternative is finding the drift
in production.

It strips comments first, for the reason `EndToEndIsolationTest` does: the
paragraph above explains the defect by quoting the code it removed, so a scan
reading the comments would trip over its own explanation. Only full-line
comments go.

The source is read with a Vite `?raw` import rather than `node:fs`, because
`@types/node` is deliberately not a dependency of this project and must not
become one for a test. `vite/client` types `*?raw` and is already in
`tsconfig.app.json`.

Verified failing against the unfixed page.

======================================================================
5. TESTS RUN
======================================================================

Docker only. No host `vendor/`, no host `node_modules`, no `npm ci`.

  docker compose exec -T app vendor/bin/pint --test <6 touched PHP files>
    PASS  6 files

  docker compose exec -T app vendor/bin/pest \
      tests/Feature/A03/PermissionBoundaryTest.php \
      tests/Feature/A03/PeriodMoneyBoundaryTest.php \
      tests/Feature/A03/AsOfStrictTest.php
    Tests:  79 passed (389 assertions)

  docker compose exec -T app vendor/bin/pest \
      tests/Feature/A03/ObligationAdjustmentTest.php
    Tests:  18 passed (77 assertions)

  docker compose exec -T node npm run test -- \
      tests/frontend/portfolio/a03FormGating.spec.ts
    Test Files  1 passed (1)
    Tests  15 passed (15)

  docker compose exec -T node npm run typecheck
    clean

  docker compose exec -T node npm run lint
    clean

  docker compose exec -T node npm run build
    built in 1.52s

No Playwright and no full backend suite, per the instruction that none of
these four corrections changes a business journey. §4 touches a reason dialog,
but only by routing an already-tested minimum through the shared helper, and
its Vitest coverage is the boundary itself.

Every new assertion was verified to fail against the code it is about, except
where that is impossible and the reason is recorded above: the §4 behavioural
boundary cannot distinguish the duplicated implementation, and the structural
example was added because of that.

======================================================================
6. FILES
======================================================================

  app/Http/Requests/Periods/ListPeriodObligationsRequest.php     §1
  app/Http/Controllers/Api/BillingConfigurationController.php   §2
  app/Http/Controllers/Api/PeriodController.php                 §3
  resources/js/pages/payments/PaymentListPage.vue                §4

  tests/Feature/A03/PermissionBoundaryTest.php                   §1
  tests/Feature/A03/ObligationAdjustmentTest.php                 §2
  tests/Feature/A03/PeriodMoneyBoundaryTest.php                 §3
  tests/frontend/portfolio/a03FormGating.spec.ts                §4

README.md was not touched: nothing here made an existing statement false.