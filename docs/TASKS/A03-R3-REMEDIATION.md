PROJECT: CONSULTORA DH

TASK:
A03-R3 — Final closure of the remaining A03 audit findings

BASELINE COMMIT:

96be5f23e5eb65c4c51445d2dd120de415e48640
A03-R2: close the remaining external audit findings

CLOSING COMMIT:

The single A03-R3 commit on `main` immediately after
96be5f23e5eb65c4c51445d2dd120de415e48640.

Written this way on purpose, and R2's process note applies here too: a commit
cannot contain its own hash, so naming it in this file would make the archive
wrong the moment it is written. R2 amended twice and force-pushed twice trying
to fill that field in, which rewrote history that had already been published for
no benefit. The hash is reported in the final response instead.

IMPORTANT CONTEXT

A01, A02, PRE-A03, A03-R1 and the already-correct parts of A03-R2 are frozen.

DO NOT start A04.
DO NOT rewrite A03.
DO NOT reopen an issue already correctly solved in R1 or R2.
DO NOT create branches.
DO NOT amend, rebase or force-push.
There must be exactly ONE new commit on top of the baseline.

This is a small pass. Nine findings, one of which is a documentation defect
that had been reported and then deliberately left red, and one of which is an
explicitly approved exception to the rule about published migrations.

======================================================================
1. PERIOD CLOSE / REOPEN STILL LEAKED FINANCIAL FIGURES
======================================================================

FINDING

R2 §5 closed the monetary permission boundary on `current()` and `show()`.
Two more endpoints that publish a period were never given the gate.

ROOT CAUSE

`PeriodController::summarise()` takes `bool $withMoney = true`, and six of
its seven call sites passed `withMoney: $this->maySeeMoney(...)` explicitly.
`close()` and `reopen()` did not, so they fell through to the default.

A role holding `periods.close` — and that role holds nothing else — read
`obligation_count`, `total_base_cop`, `total_effective_cop`, `total_paid_cop`
and `total_balance_cop` for the month it had just closed. Same for
`periods.reopen`.

This is the leak R1 §37 exists to prevent, reached through the endpoint an
operator uses at the end of every month, so it was not a corner of the API.

FIX

Both calls pass `withMoney: $this->maySeeMoney($request->user())`. The same
rule, the same helper, no second policy, and no change to what `periods.close`
or `periods.reopen` authorise: the action still succeeds, the figures are still
withheld, and they are withheld by being **absent** rather than zero, because a
zero is a claim that the month is worth nothing.

A default of `true` on a parameter whose whole purpose is to withhold is the
kind of default that gets forgotten, so the regression test also reads the
controller's source and fails if any `summarise()` call appears without
`withMoney`. That is what catches the *next* endpoint rather than only the two
this round found.

TESTS

tests/Feature/A03/PeriodMoneyBoundaryTest.php

  A. `periods.close` alone closes the month, receives no obligation-derived keys
  B. `periods.reopen` alone reopens it, receives none
  C. `obligations.view` / `obligations.generate` still receive them, and the
     figures are real: 250000 base, 100000 applied, 150000 owed
  D. every `summarise()` call site in the controller passes the gate

C deliberately checks the numbers rather than their presence. A test that only
asks "is the key there" would pass against an endpoint returning zeros, which
is the other half of the defect.

======================================================================
2. THE CLIENT ACCOUNT COUNTED MONTHS AND CALLED THEM OBLIGATIONS
======================================================================

FINDING

The account derived one number and published it twice.

ROOT CAUSE

    $overduePeriods = distinct overdue period keys
    $overdueCount   = count($overduePeriods)

    'overdue_obligations_count' => $overdueCount,
    'traffic_light'            => TrafficLight::forOverdueCount($overdueCount)

A client owing two employers in the same March is **two** late obligations and
**one** late month. The account said one obligation. A card labelled "1
obligación" tells a collections operator one person has one problem when they
have two, and this is the screen where that decision is made.

The semaphore was reading the right number. Only the name and the number
carried by it disagreed, so both could not be right and the name a reader
trusts was the false one.

This is R1 §24's confusion in the sibling endpoint nobody revisited.
`groupedClients()` has published both names correctly since R1 — rows and
distinct keys — so the account was the odd one out, and the two screens could
disagree about the same client.

FIX

`clientAccount()` now counts the overdue **rows** and the overdue **months**
separately and publishes both:

    overdue_obligations_count   rows
    overdue_periods_count       distinct period keys

The semaphore is built from `overdue_periods_count`, which is R1 §24 and is
unchanged. `TrafficLight::forOverdueCount()` is called once into a variable
rather than three times, because the three call sites could otherwise have been
left reading different counts.

The Vue card states both: "2 obligaciones · 1 periodo vencido", singular where
it is singular.

TESTS

tests/Feature/A03/ClientAccountOverdueTest.php

  - two employers in one month: 2 obligations, 1 period, yellow
  - two months × two employers: 4 obligations, 2 periods, orange
  - the account and the portfolio list agree, field for field
  - nothing late before the due date, under either name, while the debts are
    still reported as open
  - a settled debt is not counted as late

The second case is not redundant with the first. With one month the correct
answer is 2 and 1, which differ; but 1 and 1 do not, so a test using only the
first case would pass against an implementation where the counts had collapsed
to one number in some other arrangement. Two months makes every wrong answer
differ from every right one.

======================================================================
3. THE CLIENT CUTOFF ROW HIDED THE EMPLOYER
======================================================================

FINDING

R2 made the form require both a client and an employer for a `client` rule,
correctly. The table cell still read:

    rule.client_name ?? rule.company_name

FINDING, restated

For a `client` rule `client_name` always exists, so the employer never
appeared. The row read "José Guerrero", which suggests the exception applies to
that person everywhere — the one thing a client-and-company exception does not
do. Two rules for the same client at different employers were indistinguishable
on screen.

This is R1 §31's requirement, stated there and only half-delivered: the form
was fixed in R2, the list was not.

FIX

The cell branches on scope and prints both halves for a client rule:

    general  →  Todos los clientes
    company  →  <company display name>
    client   →  <client full name> — <company display name>

Both fields are already published by `describeRule()`, so this costs no extra
request and changes no contract.

TESTS

Vitest, tests/frontend/portfolio/a03FormGating.spec.ts, "names both the client
and the employer in a client-scoped row" — the row is located by its scope
label and each cell is asserted, including that a `company` rule is not given a
client.

Journey A, strengthened. It previously asserted only that the client's name
appeared in the row, which is exactly what the broken cell printed, so it could
not fail. It now asserts on the cell itself:

    await expect(clientRow.locator('td[data-label="Aplica a"]'))
        .toHaveText('Cobro 11 Prueba A03 — Comercial A03 11 S.A.S.');

Scoped to the cell rather than the row on purpose: the row also carries the
scope label and the effective month in other columns, so a row-level assertion
could be satisfied by the employer appearing in a column this change is not
about.

Both were verified to fail against the unfixed cell.

======================================================================
4. PERIOD-OBLIGATION `as_of` STILL DID NOT USE THE NORMAL CONTRACT
======================================================================

FINDING

R2 stopped `as_of` being silently replaced with today. It did it with a private
helper that called `validator()` and then `abort(422, $message)`.

ROOT CAUSE

That produces the right status and the right sentence and **no `errors`
envelope at all**. A client cannot tell which input was rejected. Two
endpoints validating the same field in two different shapes is the situation
R1 §26 warned about for permissions and R2 §7 for reference dates.

FIX

`app/Http/Requests/Periods/ListPeriodObligationsRequest.php`. The rules,
messages and attributes are identical to `ListClientAccountRequest`, so all
three reference-date endpoints answer the same shape. The controller's
`referenceDate()` is deleted rather than left unused.

One thing this deliberately does NOT do: extend `ListQueryRequest`. That base
class fixes page sizes at 25/50/100, and this endpoint has always accepted 1 to
100 — `QueryCountTest` asks for `per_page=10`. Extending the base would have
changed what the endpoint accepts as a side effect of a change about a date,
and `QueryCountTest` caught exactly that when it was first tried. The four
reference-date rules are copied instead, with a comment saying so.

That the existing suite caught this, rather than this review noticing it, is the
argument for having run it.

TESTS

tests/Feature/A03/AsOfStrictTest.php now asserts
`assertJsonValidationErrors('as_of')` on the period endpoint instead of checking
the body contains the word "fecha", and a new example asserts that all three
endpoints return the same field, the same envelope and the same sentence.

The unreadable-date dataset is unchanged: impossible months and days, words,
SQL, a time, a timestamp, slashes and a month name.

======================================================================
5. APPROVED EXCEPTION: THE `unaccent` ROLLBACK
======================================================================

This is the one approved edit to a published migration. Only `down()` changed.

FINDING

`up()` runs `CREATE EXTENSION IF NOT EXISTS unaccent`. That statement is
idempotent and uninformative: the database does not record whether it created
the extension or found it already there. `down()` ran
`DROP EXTENSION IF EXISTS unaccent`.

TWO CASES THAT ARE INDISTINGUISHABLE FROM `down()`, NEEDING OPPOSITE ROLLBACKS

  1. This migration created it → dropping it restores the database.
  2. It already existed, or another feature depends on it → dropping it breaks
     that consumer irreversibly, from inside a rollback nobody was thinking
     about.

The migration chose case 1 on no evidence. That is the destructive branch.

FIX

`down()` is a documented no-op. `up()` is untouched, and `unaccent` is still in
the application's search.

A preflight was considered and rejected: "is anything else using `unaccent`?" is
not answerable from inside the migration. PostgreSQL has no catalogue view of
which columns or other schemas reference an extension, and `SafeSearch::match()`
being one caller cannot prove it is the only one. Leaving the extension behind
costs an inert, unowned object that one statement can remove; dropping a shared
one costs a restore.

TESTS

tests/Feature/A03/UnaccentMigrationSafetyTest.php

  - the migration contains no `DROP EXTENSION`, in any case, and `down()`'
    executable body contains no `DB::unprepared` or `DB::statement`
  - `down()` is genuinely an empty body, matched as a pattern so the assertion
    is about the braces and not the indentation
  - the decision is documented at the point of decision, with its specific reason
  - `up()` still creates the extension — so "there is no DROP" cannot be
    satisfied by gutting the file
  - the extension really is installed and the searches really work, including
    `Única` through the HTTP layer

The comment-stripping is the same idea as `EndToEndIsolationTest`'s: this
migration's docblock names `DROP EXTENSION IF EXISTS unaccent` several times
while explaining why it is absent, so a search over the whole file cannot
distinguish the decision from the reasoning about it. Only full-line comments are
stripped; a trailing `// DROP EXTENSION` after real code is still real code.

======================================================================
6. THE VOID DIALOG STILL ARMED A DESTRUCTIVE ACTION
======================================================================

`VoidPaymentRequest` requires `min:10`. The dialog enabled "Anular pago" on any
non-empty reason, so one character armed it and the server refused after the
round trip.

§7, §8 and this one are the same defect in three dialogs, and R2 had already
fixed two of them with a constant local to one page. Three dialogs across two
pages, each with its own idea of "enough", is how the backend minimum ends up
changed in one place and three others.

FIX

`resources/js/validation/reasons.ts` holds `REASON_MIN_LENGTH`,
`reasonIsLongEnough()` and the hint sentence. All four reason dialogs import it:
void, allocation reversal, adjustment, reopen. The period obligations page's
local constant is gone.

The check trims, because the server counts the value after normalisation and ten
spaces is not a reason.

The backend is unchanged and remains authoritative. A hand-written `curl`
bypasses all of this, which is why the rule is on both sides.

TESTS

Vitest, tests/frontend/portfolio/a03FormGating.spec.ts, three new examples:
empty, short, and valid; the exact 9/10-character boundary for reopen; the hint
text; and that a role without the permission is offered no action at all. Each
was verified to fail against the unfixed gate.

======================================================================
7. THE REVERSAL HINT DESCRIBED A DIFFERENT RULE
======================================================================

R2 correctly blocked allocation reversal under ten characters. The visible hint
still said "Sin motivo no se revierte", which is a different rule: the reason
*was* there, it was not yet long enough, and the operator could not tell that from
the screen. A hint that does not describe the rule the form applies leaves a
disabled button looking broken.

FIX

The hint is `REASON_MIN_LENGTH_HINT` — "Explique el motivo con al menos 10
caracteres. Quedará en la auditoría." — shared with the other three dialogs, so
the copy and the gate cannot disagree.

The existing R2 regression was extended rather than duplicated, and now also
asserts the old sentence is gone.

======================================================================
8. REOPENING A PERIOD HAD THE SAME MISMATCH
======================================================================

`ReopenPeriodRequest` requires `min:10`; the dialog gated on non-empty. Same fix
as §6. Closing is deliberately untouched: it takes no reason, so it has no
minimum, and a test asserts that a `periods.close` role is offered no reopen
action rather than growing a field the server has no rule for.

======================================================================
9. "CORTE DE LA CONSULTA" MISREPRESENTED `as_of`
======================================================================

FINDING

R1 §26 established that `as_of` is an aging reference date and not a historical
statement: today's payments, adjustments, reversals and voids still participate,
and the only thing the date chooses is which debts count as late.

The visible paragraph on the client account said so correctly. The
**accessibility label** on the date input still read "Corte de la consulta",
which tells a screen-reader user the date is a statement cutoff — so one page
said two different things about the same control to two different people.

The receivables filter's label was "Al día de", which is the phrase R1 §26 named
and forbade: "saldo al día de".

FIX

Both are "Mora evaluada al", which is the wording R2 already used in the
visible paragraph on the account. The name of the measurement, not the name of a
cutoff the system does not take.

R2 §13 had already renamed the aging column from "Antigüedad" to "Días de mora"
on the strength of the same argument, so this is that correction carried to the
last two places it had not reached.

======================================================================
10. AN ISOLATION TEST LEFT DELIBERATELY RED
======================================================================

FINDING

R2 reported one failing test and declined to fix it as "not one of the thirteen".
That was the wrong call and the instruction not to leave a known red test is
right: a safety assertion that cries wolf stops being read by whoever next adds
something genuinely dangerous.

ROOT CAUSE

`EndToEndIsolationTest` searched the raw text of `scripts/run-e2e.sh` for
`artisan cache:clear`. Every occurrence of that string, and of
`consultora-dh:e2e-cleanup`, is inside a `#` comment explaining that the command
was **removed** and why. The test could not tell a dangerous invocation from the
documentation of its own absence.

FIX

A helper strips full-line comments, and the dangerous-command assertions run
against the executable content. Full-line only: a trailing `#` after code is not
stripped, because the code before it still runs. `run-e2e.sh` has no heredocs,
which is the one construct where a `#` line is data rather than a comment; the
self-test below would fail if that ever changed.

The comments are **not** deleted. Deleting them would have made the test pass
and removed the record of why a developer's cache is not being flushed, which is
the reasoning someone needs before re-adding the command. The test now asserts
both halves: the command never appears in executable content, and the
explanation of its removal is still in the file.

A second example proves the assertion can still fail. It takes the real runner,
splices in `app-e2e php artisan cache:clear`, and requires the same check to
reject it; it requires a trailing `#` comment not to launder it; and it requires
a comment that merely mentions the command not to trip it, which is the original
defect. A test that only asserts an absence is only worth something if it can
fail, and "we removed a comment so the test passes" and "the test still works"
are both easy to believe and only one of them is true.

The first example also asserts the stripped copy still contains the guard's own
message and `DB_E2E_DATABASE`, and is more than a quarter of the original size —
so a stripper bug that removed everything would fail the test rather than
silently making every `not->toContain` assertion vacuous.

======================================================================
11. DOCUMENTATION WAS INTERNALLY STALE
======================================================================

README.md

  - the banner said "Estado actual: A01 — base técnica" and that the business
    modules **do not exist**. Both false; A02 and A03 are implemented, and the
    same document describes A03 in §6 and §9.2. The banner now says A03, and
    names what genuinely does not exist yet: planillas, documentos, portal,
    automatizaciones.
  - §1 repeated "A01 no implementa ninguno de esos módulos". Replaced with what
    is true: A02 implements the first group, A03 the financial core of it, and
    the client service and analytics are A08 to A14.
  - §8 said the E2E runner creates **two** temporary accounts; §9.1 said three.
    The runner creates three — Super Admin, Read Only, Collections
    (`run-e2e.sh` lines 239, 248, 257). §8 now says three.
  - §9.2 was headed "Los ocho flujos de A03" and listed A–H. The module has
    **nine** acceptance journeys (A, B, C, E, F, G, H, I, J — there is no D) and
    nine in `billing.spec.ts`, eighteen in total. The table now lists the nine
    real ones, described by the risk each covers.
  - the directory map described A01's models. It now lists all fifteen, grouped
    by module.
  - the documentation table did not list the remediation archives. It does now.
  - an unbalanced code fence at the end of §9.2 is fixed.

docs/ROADMAP.md

  - A02 and A03 were both marked complete while sitting under "Tareas
    pendientes", and the "Tareas completadas" heading listed only A01. Sections
    moved; "Tareas pendientes" now begins at A04.
  - A03-R2 and A03-R3 recorded, each as a short account of what it closed.
  - A03's E2E flow count corrected from nineteen to eighteen, which is what the
    repository contains.
  - "Las pruebas accompanyan" → "acompañan".

tests/e2e/a03-acceptance.spec.ts

  Two comments described the R2-fixed full-name defect as current: "the search
  is a fragment of one name column" and "a whole name spanning first and last
  names matches nothing at all". Both were true when written and false after
  R2 §11 and §14. They now describe what the search does — each column, plus the
  whole name, folded for accents — and the first explains that the assertion
  rests on the client having been paid off rather than on the search failing.

  No test logic in the E2E suite was changed except Journey A's assertions,
  which §3 required. Every other journey passed unchanged.

Nothing in docs/TASKS/A03.md, A03-R1-REMEDIATION.md or A03-R2-REMEDIATION.md was
edited. The R2 archive is a record of what R2 did; §5's description of that
migration's rollback is left as R2 wrote it, and this document is where the
correction is recorded.

======================================================================
12. WHAT WAS DELIBERATELY NOT DONE
======================================================================

- No A04. No branches. No amend, no rebase, no force push. One commit.
- No change to what `periods.close`, `periods.reopen`, `obligations.view` or
  `obligations.generate` authorise. §1 withholds data from callers who already
  had the action.
- No change to the backend minimum for any reason. Four requests keep `min:10`.
- The `C` collation, and therefore byte-order name sorting. Left as R2 §14
  recorded it.
- No unrelated refactors, and no previously-correct R1/R2 behaviour weakened.
- `PeriodController::perPage()` still bounds page size at 1..100 and the new
  request matches it. `ListQueryRequest`'s 25/50/100 contract was not adopted
  here.
- The whole E2E repository was not run for ceremony: only the two A03 files
  whose behaviour this round touches (`a03-acceptance.spec.ts` for §3 and the
  stale comments, `billing.spec.ts` for close/reopen and `as_of`). Both are
  included in full because the runner is easiest invoked that way, and the task
  allows it.

======================================================================
13. FINAL QA
======================================================================

Every command runs inside Docker, which is this project's execution
environment. No host `vendor/`, no host `node_modules`, no `npm ci`.

  docker compose exec -T app vendor/bin/pint --test
    PASS  344 files

  docker compose exec -T app vendor/bin/pest \
      tests/Feature/A02 tests/Feature/A03 tests/Unit
    Tests:  828 passed (3688 assertions)

  docker compose exec -T node npm run test
    Test Files  17 passed (17)
    Tests  161 passed | 1 skipped (162)

  docker compose exec -T node npm run typecheck
    clean

  docker compose exec -T node npm run lint
    clean

  docker compose exec -T node npm run build
    built in 1.60s

  ./scripts/run-e2e.sh tests/e2e/a03-acceptance.spec.ts
    9 passed

  ./scripts/run-e2e.sh tests/e2e/billing.spec.ts
    9 passed

The PHP suite is fully green, including `EndToEndIsolationTest`, which had been
red since R2.

NEW TEST FILES

  tests/Feature/A03/PeriodMoneyBoundaryTest.php        §1
  tests/Feature/A03/ClientAccountOverdueTest.php       §2
  tests/Feature/A03/UnaccentMigrationSafetyTest.php    §5

EXTENDED

  tests/Feature/A02/EndToEndIsolationTest.php          §10
  tests/Feature/A03/AsOfStrictTest.php                 §4
  tests/Feature/A03/QueryCountTest.php                 §4 (caught the page-size regression)
  tests/frontend/portfolio/a03FormGating.spec.ts       §3, §6, §7, §8
  tests/e2e/a03-acceptance.spec.ts                     §3 (Journey A), §11 (comments)

Every new assertion was verified to fail against the code it is about: §3's
Vitest example and Journey A against the old cell, §6/§7/§8's five Vitest
examples against the old gates. Assertions that have never failed have not been
shown to work.

======================================================================
14. FILES
======================================================================

APPLICATION

  app/Http/Controllers/Api/PeriodController.php            §1, §4
  app/Http/Requests/Periods/ListPeriodObligationsRequest.php  §4  (new)
  app/Domain/Receivables/ReceivablesService.php            §2

  resources/js/validation/reasons.ts                       §6, §7, §8  (new)
  resources/js/types/api.ts                                §2

INTERFACE

  resources/js/pages/settings/BillingSettingsPage.vue      §3
  resources/js/pages/receivables/ClientAccountPage.vue     §2, §9
  resources/js/pages/receivables/ReceivablesPage.vue       §9
  resources/js/pages/payments/PaymentListPage.vue           §6, §7
  resources/js/pages/periods/PeriodListPage.vue            §8
  resources/js/pages/periods/PeriodObligationsPage.vue     §6, §8 (shared constant)

MIGRATION — the approved exception

  database/migrations/2026_10_03_130000_enable_unaccent_extension.php
    up    unchanged: CREATE EXTENSION IF NOT EXISTS unaccent
    down  now a documented no-op

DOCUMENTATION

  README.md                                    §11
  docs/ROADMAP.md                              §11
  tests/e2e/a03-acceptance.spec.ts            §3, §11 (comments)

TESTS

  as listed in §13