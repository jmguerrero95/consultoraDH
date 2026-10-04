/**
 * The shortest reason the server will accept, in one place.
 *
 * ## The contract this file exists to hold
 *
 * Four dialogs in this product ask for a reason before a financial action: recording a
 * payment as void, reversing an application of a payment, recording an adjustment, and
 * reopening a period. All four are governed by the same server rule:
 *
 *     'reason' => ['required', 'string', 'min:10', 'max:1000']
 *
 * in `VoidPaymentRequest`, `ReverseAllocationRequest`, `StoreAdjustmentRequest` and
 * `ReopenPeriodRequest`.
 *
 * Each of those dialogs used to gate on its own idea of "enough" — three of them on "not
 * empty" — so a single word enabled a button whose action the server would refuse. The
 * failure is not cosmetic: the operator presses a destructive or irreversible button,
 * waits for a round trip, and is told by the server something the form already knew.
 *
 * ## Why a shared constant and not four local ones
 *
 * Four copies of `10` is four places for the backend minimum to change without the
 * interface changing, and the failure is silent: the dialog still enables the button, the
 * server still refuses, and the only visible symptom is an error message that arrives after
 * the fact. A constant with a single definition can be changed in one edit, and the tests
 * assert against this value rather than against a literal repeated in each spec.
 *
 * ## What the backend still does
 *
 * Nothing is decided here. This is the interface's half of the rule; the server remains
 * authoritative, and these four dialogs are not the only possible callers of those requests
 * — a hand-written `curl` bypasses all of this, which is exactly why the validation lives on
 * both sides rather than being "handled" on one.
 *
 * ## Why ten
 *
 * Ten is not arbitrary and is not negotiable per dialog. It is long enough that
 * "se aplicó de más" does not qualify as a reason — the whole point of asking is the audit
 * trail, and an audit trail of one word is not one — and short enough that nobody who wants
 * to explain themselves is stopped mid-sentence. It has been the server's rule for all four
 * requests from the start; this file is the interface agreeing to it rather than proposing it.
 */
export const REASON_MIN_LENGTH = 10;

/**
 * Whether a reason satisfies the server's minimum.
 *
 * Trimmed, because the server counts the value after the request has been through the same
 * normalisation, and because a row of spaces is not a reason: `'          '` has length 10
 * and is nothing at all.
 */
export function reasonIsLongEnough(value: string): boolean {
    return value.trim().length >= REASON_MIN_LENGTH;
}

/**
 * The sentence the dialogs show, so the visible copy and the disabled state agree.
 *
 * Written once because the previous copy was the defect: "Sin motivo no se revierte" was true
 * and useless, because the reason *was* there — it was just not yet ten characters — and the
 * operator could not tell that from the screen. A hint that does not describe the actual rule
 * leaves a disabled button unexplained, which is indistinguishable from a broken form.
 */
export const REASON_MIN_LENGTH_HINT =
    `Explique el motivo con al menos ${REASON_MIN_LENGTH} caracteres. Quedará en la auditoría.`;