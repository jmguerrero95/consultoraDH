PROJECT: CONSULTORA DH

TASK:
A03-R2 — Close the remaining external audit findings

BASELINE COMMIT:

369c220465d8fc385c3d7203740f1819e49cc899  (A03-R1)

CLOSING COMMIT:

the single commit on `main` immediately after 369c220, whose message is
"A03-R2: close the remaining external audit findings".

Written this way on purpose: a commit cannot contain its own hash, so naming
it here would make the archive wrong the moment it is written.

IMPORTANT CONTEXT

A01, A02, PRE-A03 and A03-R1 are approved. This is the second
remediation pass, covering the thirteen findings the external review of the
R1 commit left open.

DO NOT start A04.
DO NOT rewrite A03.
DO NOT create branches or checkpoint commits.

One finding is about money reaching somebody who should not see it, one is
about a filter that answers a different question from the one asked, and one
was not in the audit at all — it was found by executing the audit's own
regressions and is recorded here as §14 because it would have made §11's fix
incomplete.

======================================================================
1. THE THIRTEEN FINDINGS, AND WHAT EACH ONE WAS
======================================================================

§1  The filtered list's header described the whole portfolio.
    `ReceivablesService::list()` published `portfolioSummary($asOf)`: an
    independent statement about every debtor in the system. Typing a name,
    choosing a semaphore band or ticking "only overdue" changed the table
    while the three cards above it kept describing the whole portfolio. The
    pager and the cards answered different questions on the one screen whose
    whole purpose is to describe the list.

§2  The cutoff rule list had no search, and a pager that lied.
    `cutoffRules()` read `scope`, `company_id` and `client_id` and nothing
    else, while the screen had always sent `search`. The field was
    decorative. It also used a hardcoded `paginate(50)` while the pager on
    screen said 25, so the range the operator read ("1–25 of 300") and the
    rows they were given came from different page sizes.

§3  The rate list searched tables that were not there.
    `rates()` emitted `lower(clients.first_names) LIKE ?` against a query
    over `client_company_rates`, whose client and company are loaded by
    `with()` — a second query, not a join. PostgreSQL answered "missing
    FROM-clause entry", so **any** non-empty search on the rate list was a
    500. A search that never worked looks exactly like a search that is
    ignored, which is why it survived.

§4  A client-scoped cutoff rule could not be saved.
    The rule is a client **and** an employer. `ruleCanSave` checked only the
    client, so the button was enabled with no company chosen and the save
    came back 422 from the request's own validator.

§5  Two period endpoints published money to the calendar-only role.
    `maySeeMoney()` gates four endpoints. `index()` passed the gate;
    `current()` and `show()` called `summarise()` and left `withMoney` at
    its default of `true`. A role holding `periods.view` alone could read
    `total_base_cop`, `total_paid_cop` and the balance from the one period
    the shell asks about on load.

§6  One employer had two names.
    The receivables screens read `companies.legal_name`; the period's
    obligations, the billing configuration, the company pickers and the
    client's company list all use `Company::displayName()`. The portfolio
    listed `Constructora Andina S.A.S.` and the client's statement for the
    same debt listed `Andina`.

§7  An unreadable reference date was dropped, not refused.
    `PeriodController::obligations()` and `ReceivableController::clientAccount()`
    read `$request->date('as_of')`, which answers `null` for anything it
    cannot parse, and both treated `null` as "today". A filter that is
    ignored shows nothing; a filter that is *silently replaced* shows a
    confident, plausible, wrong answer.

§8  The reversal response was an N+1.
    Five endpoints return the same payment detail. Four went through
    `loadForDetail()`. The reversal did not, so the client and every
    allocation's obligation graph — period, client, company — arrived
    unloaded and were fetched one at a time.

§9  `rates()` read the whole history to answer one month.
    `BatchedConfigResolver` says its rows are "bounded by the number of
    distinct keys rather than by the length of the configuration history",
    and the three cutoff queries did that with `DISTINCT ON`. `rates()` did
    not: it fetched every applicable rate for the pairs and kept the first
    per key in PHP. Correct answer, cost growing with history.

§10 `PaymentAllocation` documented a guarantee that had been dropped.
    The docblock said "the database refuses two live allocations of the same
    payment to the same obligation … a reversal frees the pair". A03-R1
    dropped that index. A model comment asserting a constraint the schema
    does not have tells a reader that instalments are impossible.

§12 The adjustment and reversal dialogs offered an action that could not
    succeed. Both require a reason of at least ten characters; both gated on
    "not empty". A word was enough to enable the button and not enough to
    save, and the refusal arrived as an error under the dialog being filled
    in.

§13 "Antigüedad" described age; the column held days past the due date.
    A debt that is not due yet read "Antigüedad: No vencida", which is a
    sentence about age that then denies having one.

§14 NOT IN THE AUDIT. Found by executing §11's own regressions.
    The test databases are created with the `C` collation, which case-folds
    ASCII and nothing else, so `lower('Única')` is `Única` while PHP's
    `mb_strtolower('Única')` is `única`. See §3 below.

======================================================================
2. WHAT WAS CHANGED
======================================================================

§1  `list()` now builds the summary from the grouped, filtered query
    captured **before** `forPage()` takes a page off it (`filteredSummary()`),
    so the cards, the rows and the pager cannot drift apart again. The
    payment figures are scoped to *the clients that population contains*,
    in one bounded query with the population handed over as a subquery — no
    loop, no N+1.

    The debt figures come from the filtered obligations and the money
    figures from `payments`, which have no row-level relationship to the
    filter; joining them would multiply every obligation by every payment
    and make every sum wrong.

    A latent 500 fell out of this: the period range filters called
    `parse()` on the Eloquent `MonthlyPeriod` rather than on the domain
    `MonthValue`, so `period_from` and `period_to` had never worked, on
    either boundary.

§2  `cutoffRules()` takes a search and `perPage()`. The search is a
    `whereHas` over the client and company relations — the condition in an
    `EXISTS`, so one row per rule and one query for the page. A `general`
    rule names nobody and is therefore not matched by a name search:
    searching for a company must not return the rule that applies to every
    company.

§3  Same shape for rates, which is what the 500 needed.

§4  `ruleCanSave` requires both identifiers for a `client` scope.

§5  `current()` and `show()` pass `withMoney: maySeeMoney(...)`.

§6  `obligationsQuery()` selects
    `coalesce(nullif(btrim(companies.trade_name), ''), companies.legal_name)`,
    which is `Company::displayName()` written in SQL because the query is
    also the source of the grouped aggregate, and an aggregate cannot call a
    PHP method. The `json_agg` repeats the expression in its `order by`,
    because Postgres orders the aggregate by its argument: ordering by
    `legal_name` while printing the trade name gave a column not in the
    order it appeared in.

    No migration: `monthly_obligations` does not snapshot the employer's
    name, so the query already joins `companies` live.

§7  Two changes. `clientAccount()` takes a `ListClientAccountRequest`
    (allowlisted, with `date_format:Y-m-d`) instead of a bare `Request`, and
    `PeriodController` validates its own reference date rather than
    null-coalescing to `now()`.

    A **blank** date stays absent rather than becoming an error: clearing a
    date input sends an empty parameter, and that has to mean "today".

§8  The reversal response goes through `loadForDetail()`.

§9  `rates()` uses the same `DISTINCT ON` the cutoff queries use, by
    calling the same private method — the shape is the same question in both
    places, and it is asked once rather than written out twice, because the
    way to get it wrong is to re-derive it.

§10 The docblock now states what actually guarantees the totals: not an
    index, which cannot express "sum of live allocations ≤ payment amount",
    but row locks plus a recomputed sum.

§12 One constant, `REASON_MIN_LENGTH`, used by both dialogs and shown in
    both hints, so the two cannot drift from the server or from each other.

§13 "Días de mora", on the list header, the list column, the account column,
    the filter label and the two explanatory paragraphs.

§14 `CREATE EXTENSION IF NOT EXISTS unaccent`, and `SafeSearch::match()`,
    which is now the only place a name comparison against a search term is
    written:

        lower(unaccent(column)) LIKE lower(unaccent(?)) ESCAPE '\'

    Every searchable surface now goes through it: clients, companies, social
    security entities, payments, the portfolio, the billing configuration,
    and the client-company picker.

======================================================================
3. §14 IN FULL, BECAUSE IT CHANGED THE SHAPE OF §11
======================================================================

§11 was "a person's full name spans two columns, so searching the columns
separately finds nobody by their full name". Fixing it meant writing the
comparison, which is when the real defect surfaced:

    select lower('Única'), ('Única' like '%única%');
    -- Única, false

The `C` collation case-folds ASCII and nothing else. PHP's
`mb_strtolower` folds `Ú` to `ú`. The two sides disagreed about exactly one
class of character.

It hid well because it is *uneven*. `Gómez` searches fine: the `ó` is
already lowercase in the column and PHP lowercases it in the needle, so
nothing has to be folded and the two agree by accident. Only the **capital**
accented letter breaks — and the capital accented letter is nearly always
the first letter of a name:

    Única, Ñoño, Épsilon, Ángel, Óscar, Iñigo

so an accented person was findable by surname and unsearchable by first
name. Neither spelling found them either: typing `unica` misses `Única`,
because there is no accent on either side of the comparison to strip.

Two decisions worth recording:

**Both sides are folded in SQL.** `lower(unaccent(...))` on the column *and*
on the needle. Transliterating the needle in PHP would mean two
implementations of the same rules, and the day they disagreed a person would
vanish with nothing to look at. One implementation cannot disagree with
itself. (`likeNeedle()` still lowercases — redundant, harmless, and it means
a needle handed to some other kind of comparison is still folded.)

**`unaccent` before `lower`, never after.** In that order the value is ASCII
by the time `lower()` sees it, which is what makes the C collation's
ASCII-only folding sufficient. The other way round does not work.

Two things this deliberately does not do:

- `unaccent` is `STABLE`, not `IMMUTABLE`, so it cannot be indexed. That
  costs nothing here: these searches lead with `%` and cannot use a `btree`
  index regardless.
- The `C` collation itself is left alone. It also decides how names *sort* —
  `Zapata` before `Álvarez`, byte by byte — and changing `datcollate`
  would reach every `ORDER BY`, every index and every existing sort order in
  the product. Recorded, not taken. The name-sorting consequence is left
  visible in this report rather than silently inherited.

While doing this, one more defect surfaced in the same code and was fixed in
the same commit: `searchPattern()` escaped the term and then handed the
escaped string to `SafeSearch::likeNeedle()`, which escaped it again.
`a_b` arrived at the database as `a\\_b` — a literal backslash followed by
any character — and matched nothing. There is now no escaping helper in
`ListQueryRequest` at all, and none in `ClientController`: two escaping
paths in a codebase means one of them is applied twice somewhere, and
"escaped, then escaped again" fails by matching nothing rather than by
raising.

======================================================================
4. QUERY BEHAVIOUR, ASSERTED
======================================================================

Three N+1-shaped fixes, each asserted as a *comparison* rather than a number,
because an absolute count has to be re-tuned whenever anything unrelated
changes and a test that must be adjusted stops being read:

- Configuration search: five queries for a page of 2 and a page of 25 —
  count, page, company names, client names, batched `in_use` (§34).
- Reversal response: the same query count for two allocations as for ten.
- Preview with a corrected rate history: unchanged by three years of
  corrections behind the same pairs.

For §9 the query count cannot see the defect at all — it is one query either
way — so the test asserts the number of **rows** the resolver returns
instead: one per pair, whatever the history behind it.

======================================================================
5. A DECISION TAKEN DELIBERATELY
======================================================================

`FinancialSanityTest` asserted that a portfolio whose obligations are all
settled still reported the money collected. Under the debtor-only default
those clients are **not in the list**, and a summary that follows the filter
therefore reads zero.

That is §1 working as intended: before it, "collected 2 115 000" sat above an
empty screen. Both halves are now asserted — the default gives an empty
result *and* an empty summary, and `outstanding_only=false` gives figures
that agree with SQL. Fixing only one half would have reintroduced the defect
in a subtler form.

======================================================================
6. WHAT WAS DELIBERATELY NOT DONE
======================================================================

- No A04. No branches, no checkpoint commits. One commit, as asked.
- The `C` collation, and therefore name sorting (§14).
- No E2E / Playwright run. The browser suite needs the e2e Compose stack and
  a separate database, and these thirteen findings are all reachable through
  the HTTP layer, which is what the feature tests drive directly.
- `EndToEndIsolationTest` is failing and was left failing. It fails
  identically on the clean baseline: the test asserts `scripts/run-e2e.sh`
  does not contain the string `artisan cache:clear`, and the script mentions
  that string three times **in comments explaining that it is no longer
  used**. The test cannot tell an invocation from an explanation of its
  absence. It is a defect in that test, not in this work, and fixing it is
  neither one of the thirteen findings nor part of A03's behaviour.

======================================================================
7. TEST RESULTS
======================================================================

All commands run through Docker, which is the project's execution
environment. No `npm ci`, no host `node_modules`.

PINT
  docker compose exec -T app vendor/bin/pint --test
  → PASS  340 files

PHP
  docker compose exec -T app vendor/bin/pest tests/Feature/A02 tests/Feature/A03 tests/Unit
  → Tests:  1 failed, 810 passed (3556 assertions)
  → the one failure is EndToEndIsolationTest, pre-existing, see §6

  docker compose exec -T app vendor/bin/pest tests/Feature/A03
  → Tests:  419 passed (2026 assertions)

FRONTEND
  docker compose exec -T node npm run test
  → Test Files  17 passed (17)
  → Tests  153 passed | 1 skipped (154)

  docker compose exec -T node npm run typecheck
  → clean

  docker compose exec -T node npm run lint
  → clean

NEW TEST FILES

  tests/Feature/A03/FilteredSummaryTest.php        §1
  tests/Feature/A03/ConfigurationSearchTest.php    §2, §3
  tests/Feature/A03/AsOfStrictTest.php              §7
  tests/Feature/A03/NameSearchTest.php              §11
  tests/Feature/A03/NameFoldingTest.php             §11, §14
  tests/Feature/A03/CompanyNamingTest.php           §6
  tests/frontend/portfolio/a03FormGating.spec.ts    §4, §12

EXTENDED

  tests/Feature/A03/PermissionBoundaryTest.php      §5
  tests/Feature/A03/PaymentAllocationTest.php       §8
  tests/Feature/A03/QueryCountTest.php              §9
  tests/Feature/A03/FinancialSanityTest.php         §1, §5 of this document
  tests/Feature/A03/ReceivablesTest.php             §1

MIGRATIONS

  2026_10_03_130000_enable_unaccent_extension.php
    up   CREATE EXTENSION IF NOT EXISTS unaccent
    down DROP EXTENSION IF EXISTS unaccent

  The first and only migration in this round, and it was approved rather
  than assumed: §14 cannot be fixed without it, and no alternative folds a
  column in SQL. The `down()` is safe — nothing outside a `LIKE` comparison
  depends on the extension, and dropping it while the application is live
  makes those searches fail loudly rather than quietly.

  Every feature test above runs against real PostgreSQL with the extension
  installed. None of these defects was reachable through a mock: the 500 in
  §3, the collation in §14 and the rows in §9 are all things only the
  database can show.

======================================================================
8. FILES
======================================================================

MIGRATION
  database/migrations/2026_10_03_130000_enable_unaccent_extension.php

APPLICATION
  app/Support/Validation/SafeSearch.php                     §11, §14
  app/Domain/Receivables/ReceivablesService.php             §1, §6, §11, §14
  app/Domain/Billing/BatchedConfigResolver.php              §9
  app/Models/PaymentAllocation.php                          §10
  app/Http/Controllers/Api/BillingConfigurationController.php §2, §3, §14
  app/Http/Controllers/Api/ClientController.php             §11, §14
  app/Http/Controllers/Api/CompanyController.php            §14
  app/Http/Controllers/Api/PaymentController.php            §8, §11, §14
  app/Http/Controllers/Api/PeriodController.php             §5, §7
  app/Http/Controllers/Api/ReceivableController.php         §7
  app/Http/Controllers/Api/SocialSecurityEntityController.php §14
  app/Http/Requests/ListQueryRequest.php                    §11, §14
  app/Http/Requests/Receivables/ListClientAccountRequest.php §7

INTERFACE
  resources/js/pages/settings/BillingSettingsPage.vue       §4
  resources/js/pages/periods/PeriodObligationsPage.vue      §12
  resources/js/pages/receivables/ReceivablesPage.vue        §13
  resources/js/pages/receivables/ClientAccountPage.vue      §13

TESTS
  as listed in §7
