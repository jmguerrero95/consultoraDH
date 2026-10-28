# NOTIF-TG-02 — Milestone Result

**Branch:** `feat/notif-tg-02`
**Base SHA:** `2df9c9ec3b6af5fee00142117502ec815d1b2f3d`
**Final SHA:** `f1ac12b32d0891479fd151d4cb235e04ef82640b`
**PR:** https://github.com/jmguerrero95/consultoraDH/pull/new/feat/notif-tg-02
**Ready for external review:** YES

---

## R01 — Smart Spanish Business Queries

- Deterministic `TelegramIntentInterpreter` parses Spanish commands before any AI fallback.
- `TelegramBusinessQueryService` handles:
  - Client lookup by name → answers debt (`¿Cuál es la deuda de …?`).
  - Planillas filter by month and unpaid status.
  - Cartera filter by receivables permission.
- No AI provider dependency for recognised business reads.

**Tests:** 4/4 passed (R01 x4), 20 assertions.

---

## R02 — Real Tasks and Reminders

- `TelegramTaskProposal` stores `due_on` and `reminder_at` from interpreted dates.
- Confirmation under row lock creates `OperationalTask` exactly once.
- `ReminderDispatcher` processes due proposals; notification sent to employee.
- Ambiguous clock times are refused (“No pude determinar la hora”).

**Tests:** 3/3 passed (R02 x3), 16 assertions.

---

## R03 — Private Documents via `sendDocument`

- `TelegramDocumentDeliveryService` uploads bytes via Telegram `sendDocument`.
- Never exposes `stored_path` or URLs; filename is the original name.
- Authorization re-checked immediately before delivery: employee permission, not archived, file exists on `documents` disk, size < 50 MB.
- “Ajeno” enforcement: document belonging to a different client than named is rejected.
- Archived documents are refused even when the ID exists.

**Tests:** 3/3 passed (R03 x3), 15 assertions.

**Fixture fix:** `review_status => DocumentReviewStatus::Received` used instead of `Approved` to satisfy `client_documents_review_shape_check` constraint (`received` OR `reviewed_at IS NOT NULL`); `submitted_on` added to ContributionSheet factories.

---

## R04 — Persistent AI Conversation per Bound Telegram Chat

- `ai_conversation_id` on `TelegramEndpoint` separates the assistant thread from support `conversation_id`.
- `context` jsonb stores last client and last document for the chat.
- Consecutive messages of the same chat share one `AiConversation` (2 messages → 1 conversation; 4 messages → 1 conversation with 4 messages).
- Conversation is not reused when the chat moves employee or is rebound (`forgetAssistantContext`).
- Only the bound employee’s `user_id` on `ai_conversations` is the authorization boundary.

**Tests:** 2/2 passed (R04 x2), 12 assertions.

---

## R05 — Webhook Security and Idempotency

- `telegram_updates` table deduplicates by `update_id`; retrying the same id is refused.
- `TelegramController::webhook` catches handler `Throwable` and returns `error` (not `2xx`) so Telegram retries safely.
- `handleUpdate` claims the row before processing; on failure the claim is released.
- Confirmation `task_confirm:` claim is scoped to `chat_id` and `user_id`; a valid proposal id from another conversation is not a confirmation.
- Unbound or disabled chats cannot retrieve private data.

**Tests:** 5/5 passed (R05 x5), 29 assertions.

---

## Test Summary

| Metric | Count |
|---|---|
| Total new tests | 17 |
| Total passed | 17 |
| Total assertions | 80 |
| Existing `TelegramAssistantTest` | 11 passed, 59 assertions |
| Pint issues fixed | 2 style issues |
| `git diff --check` | clean (no whitespace offences) |

---

## LIVE Certification Status

- All tests use `Http::fake()` (hermetic, mock provider at `api.telegram.org/*`).
- No real AI provider or Telegram API calls are made.
- **LIVE certification is PENDING** — the code path is proven deterministic and hermetic, but an external review with a real provider and real Telegram bot is required to satisfy the `PRIVATE_TOOL_CAPABLE_PROVIDERS >= 3` and `FAILOVER_LIVE = PASS` gates of AGENTS.md.

---

## Residuals & Limitations

- `sendDocument` and AI provider not LIVE verified; evidence is `Http::fake()` hermetic tests only.
- Document `review_status` constraint (`client_documents_review_shape_check`) required fixture adjustment (`Received` instead of `Approved`); the constraint logic `(review_status = 'received' OR reviewed_at IS NOT NULL) AND (review_status IN ('received','rejected') OR reviewed_at IS NULL)` means `approved` cannot simultaneously satisfy both unless `reviewed_at` is both non-null and null. Tests use `received`; a legitimate review action would be needed to create `approved` docs in production.
- No destructive git operations performed; branch `feat/notif-tg-02` created from clean base SHA.
- `AiAttachment` model untouched; no Eloquent relations or methods were invented.

---

## Verification Commands (for reviewer)

```bash
# Re-run all notif-tg-02 tests
docker exec consultora-dh-queue sh -c 'cd /var/www/html && APP_ENV=testing DB_CONNECTION=testing php vendor/bin/pest tests/Feature/Notifications/TelegramNotifTg02Test.php --colors=never'

# Re-run existing assistant tests
docker exec consultora-dh-queue sh -c 'cd /var/www/html && APP_ENV=testing DB_CONNECTION=testing php vendor/bin/pest tests/Feature/Notifications/TelegramAssistantTest.php --colors=never'

# Verify Pint formatting
docker exec consultora-dh-queue sh -c 'cd /var/www/html && vendor/bin/pint --diff app/Services/Telegram/ tests/Feature/Notifications/TelegramNotifTg02Test.php'

# Verify no whitespace offences
git diff --check
```