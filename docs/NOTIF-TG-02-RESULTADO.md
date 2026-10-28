# NOTIF-TG-02 — Milestone Result

**Branch:** `feat/notif-tg-02`
**Base SHA:** `2df9c9ec3b6af5fee00142117502ec815d1b2f3d`
**Previous published HEAD:** `56b727d9f41edbc81381d879fbc18989d4d784e3`
**R05 repair implementation SHA:** `529eff50e11030cf7a48424e20af2a2d4098389a`
**R05 concurrency adjustment based on:** `81032fd1d7d1360cc851795b84f35afa36aaf403` (published in the commit that contains this update)
**Documentation commit:** the commit that contains this file
**Ready for external review:** YES for the hermetic code path; LIVE certification remains PENDING.

---

## R01 — Smart Spanish Business Queries

- Deterministic `TelegramIntentInterpreter` parses Spanish commands before any AI fallback.
- `TelegramBusinessQueryService` handles:
  - Client lookup by name → answers debt (`¿Cuál es la deuda de …?`).
  - Planillas filter by month and unpaid status.
  - Cartera filter by receivables permission.
- No AI provider dependency for recognised business reads.

**Tests:** 4/4 passed.

---

## R02 — Real Tasks and Reminders

- `TelegramTaskProposal` stores `due_on` and `reminder_at` from interpreted dates.
- Confirmation under row lock creates `OperationalTask` exactly once.
- `ReminderDispatcher` processes due proposals; notification sent to employee.
- Ambiguous clock times are refused (“No pude determinar la hora”).

**Tests:** 3/3 passed.

---

## R03 — Private Documents via `sendDocument`

- `TelegramDocumentDeliveryService` uploads bytes via Telegram `sendDocument`.
- Never exposes `stored_path` or URLs; filename is the original name.
- Authorization re-checked immediately before delivery: employee permission, not archived, file exists on `documents` disk, size < 50 MB.
- “Ajeno” enforcement: document belonging to a different client than named is rejected.
- Archived documents are refused even when the ID exists.

**Tests:** 5/5 passed (3 base + 2 negative authorization tests).

---

## R04 — Persistent AI Conversation per Bound Telegram Chat

- `ai_conversation_id` on `TelegramEndpoint` separates the assistant thread from support `conversation_id`.
- `context` jsonb stores last client and last document for the chat.
- Consecutive messages of the same chat share one `AiConversation`.
- Conversation is not reused when the chat moves employee or is rebound (`forgetAssistantContext`).
- Only the bound employee’s `user_id` on `ai_conversations` is the authorization boundary.

**Tests:** 2/2 passed.

---

## R05 — Webhook Idempotency, Atomic Claim and External Effects

### Syntax repair

The published commit `56b727d` did not parse:

```text
Errors parsing app/Services/Telegram/TelegramBotService.php
PHP Parse error: Unmatched '}' in app/Services/Telegram/TelegramBotService.php on line 234
```

The extra braces were removed. Every modified PHP file now passes `php -l` inside the `app` container.

### Claim mechanism (PostgreSQL/Laravel, not `withLock()` alone)

`telegram_updates` now carries:

| Column | Purpose |
|---|---|
| `claim_token` | Random token that proves which worker owns the turn |
| `claimed_at` | Start of the claim lease |
| `external_started_at` | Durable marker written before any outbound call |
| `attempt_count` | Number of real claim attempts |
| `failure_code` | Failure class or `external_outcome_unknown` |

Claim order:

1. `INSERT ... ON CONFLICT DO NOTHING` on the unique `update_id`; the winner owns the turn.
2. Otherwise, one conditional `UPDATE ... WHERE state IN ('pending','failed_recoverable') AND external_started_at IS NULL AND (claim_token IS NULL OR claimed_at <= now() - lease)`.
3. A live `pending` claim returns non-2xx so Telegram retries after the 15-minute lease.
4. A live claim that already has `external_started_at` set is still treated as busy: the duplicate never rewrites the row or invalidates the first worker's `claim_token`.
5. Only after the lease expires is a `pending` row with `external_started_at` moved to `failed_external`, using an atomic `UPDATE` conditioned on `state = 'pending'`, `external_started_at IS NOT NULL` and the expired `claimed_at`. It is never replayed.

The claim token is required for every later transition (`succeeded`, `failed_recoverable`, `failed_external`, external marker). A worker that lost the claim cannot overwrite another worker's row.

### State transitions

- `succeeded`: `processed_at` is recorded and the claim is released.
- `failed_recoverable`: persisted before any external effect; a retry reclaims the row and performs the real work.
- `failed_external`: persisted when an outbound call had already started; automatic replay is blocked until manual conciliation.
- External effects are marked in their own committed `UPDATE` before the HTTP call.
- HTTP calls (`sendMessage`, `sendDocument`, provider turns) are made outside database transactions.

### Private payload logging

- The invalid-`update_id` log no longer writes the update body.
- Webhook, Telegram transport and document-delivery errors log the exception class, not its message or URL.
- No message text, bot token or chat id is written by the R05 log path.

### Tests

The seven `ok(true)` placeholders were removed and replaced with real assertions:

1. invalid `update_id` executes nothing;
2. repeated `update_id` creates one proposal and no task;
3. a valid message creates one proposal and **no** operational task before confirmation;
4. a forced pre-external failure persists `failed_recoverable`, then the real retry succeeds;
5. a `pending` row actively claimed by another worker is not processed;
6. `succeeded` records `processed_at` and releases the claim;
7. the external marker is present before HTTP and no extra transaction is open;
8. a stale `pending` with an external marker becomes `failed_external` without replay;
9. a failed `sendDocument` becomes `failed_external` and is not sent twice on retry;
10. an existing `failed_external` does not process or send anything;
11. logs contain neither private text nor the bot token;
12. HTTP statuses match success, duplicate and recoverable-failure outcomes;
13. a second delivery while the first worker still holds a live lease after `external_started_at` is treated as busy, the first `claim_token` survives and the first worker can finish `succeeded`.

The previous test that expected `OperationalTask::count() === 1` after an unconfirmed proposal was corrected to expect `0`.

---

## Test Summary

| Suite / check | Result |
|---|---|
| `php -l` on modified PHP files | PASS |
| `TelegramNotifTg02Test.php` | **32 passed, 167 assertions** |
| `TelegramAssistantTest.php` | **11 passed, 59 assertions** |
| Pint on changed files | PASS |
| `git diff --check` | clean |

The previous whole-directory notifications run (61 passed, 295 assertions) predates the concurrency adjustment; this adjustment was verified with the two focused suites above.

### Baseline evidence before the repair

After fixing only the parse error, the real suite was executed and failed:

```text
Tests: 8 failed, 22 passed (97 assertions)
```

The eight failures were exactly the seven `ok(true)` / missing `TelegramUpdate` tests plus the test that expected an operational task without confirming the proposal.

---

## Verification Commands

```bash
# Syntax
docker compose exec -T app sh -c '
  php -l app/Services/Telegram/TelegramBotService.php &&
  php -l app/Services/Telegram/TelegramDocumentDeliveryService.php &&
  php -l app/Services/Telegram/TelegramExternalEffectFailed.php &&
  php -l app/Models/TelegramUpdate.php &&
  php -l app/Http/Controllers/Api/TelegramController.php &&
  php -l database/migrations/2026_10_28_150000_add_claim_columns_to_telegram_updates.php
'

# Focused suite (run sequentially; the test database is shared)
docker compose exec -T app sh -c '
  cd /var/www/html &&
  APP_ENV=testing DB_CONNECTION=testing php vendor/bin/pest \
    tests/Feature/Notifications/TelegramNotifTg02Test.php --colors=never
'

# Existing Telegram assistant suite
docker compose exec -T app sh -c '
  cd /var/www/html &&
  APP_ENV=testing DB_CONNECTION=testing php vendor/bin/pest \
    tests/Feature/Notifications/TelegramAssistantTest.php --colors=never
'

# Whole notifications area
docker compose exec -T app sh -c '
  cd /var/www/html &&
  APP_ENV=testing DB_CONNECTION=testing php vendor/bin/pest \
    tests/Feature/Notifications --colors=never
'

# Pint
docker compose exec -T app sh -c '
  cd /var/www/html &&
  vendor/bin/pint --test \
    app/Services/Telegram/ app/Models/TelegramUpdate.php \
    app/Http/Controllers/Api/TelegramController.php \
    database/migrations/2026_10_28_150000_add_claim_columns_to_telegram_updates.php \
    tests/Feature/Notifications/TelegramNotifTg02Test.php
'
```

---

## LIVE Certification Status

- All Telegram and AI calls in these tests use `Http::fake()`; they prove the code path, not external availability.
- No real Telegram bot or AI provider call was made for this repair.
- **LIVE certification remains PENDING** under AGENTS.md. This document does not claim `VERIFIED_LIVE`.

---

## Residuals & Limitations

- The concurrency tests use real PostgreSQL rows already claimed by another worker, both with a live lease and with an expired lease; they do not run two OS processes under load. The atomicity itself is enforced by the unique `update_id` insert and the conditional `UPDATE`, not by PHP locking.
- `failed_external` requires manual conciliation. The bot intentionally stops automatic retries after an outbound effect started, so a document or message may need to be checked by an operator.
- A worker that dies after starting an external effect is blocked by `external_started_at` on the next retry; this is deliberate and may require manual intervention.
- The test suites must run sequentially against `consultora_dh_test`. An accidental parallel run collided on the shared test database during verification and is not used as evidence; the results above are from sequential runs.
- No R01–R04 production code was changed and `main` was not touched.
- No destructive git operation was used.
