<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A03: payments and the allocations that say what each payment paid for.
 *
 * ## A payment belongs to a client, not to a company
 *
 * The header carries `client_id` and no company. That is a deliberate modelling
 * choice rather than an omission: a client may transfer between employers, pay
 * several months at once, and pay obligations that belong to different companies.
 * Binding the header to one company would force that payment to be split into
 * several, or to be refused, and either would be a lie about the money.
 *
 * What a payment *pays for* is entirely the business of the allocations.
 *
 * ## Money is never deleted
 *
 * There is no delete path. A payment that turns out to be wrong is voided, which
 * keeps every row and every allocation while removing the money from the economic
 * totals. A deleted payment is indistinguishable from one that was never received,
 * and the difference is the whole point of keeping a ledger.
 *
 * `voided_at`, `voided_by` and `void_reason` are written together, and the check
 * below refuses a half-voided row: a payment that is void with nobody responsible
 * for it is worse than one that is simply not void.
 *
 * ## Allocations are append-only
 *
 * The same rule as the adjustment ledger. A wrong allocation is reversed by a new
 * row and a reversal marker, never by deleting or editing the original, so the
 * sequence of what somebody believed and when is recoverable.
 *
 * A voided payment's allocations stay in place. They are excluded from the totals by
 * the payment's state, not by being removed, and that keeps "what did this payment
 * originally pay for?" answerable after the void.
 *
 * ## Amounts
 *
 * Whole pesos in BIGINT, positive. A payment of zero is not a payment, and a
 * negative payment is a refund, which is not a concept this module models: a
 * refund is an adjustment against the obligation or a new obligation, both of which
 * are recorded as what they are.
 *
 * Foreign keys RESTRICT. A payment is evidence that money arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // The client the money is for. Never a company: see the note above.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();

            // Whole Colombian pesos. Positive: see the note above.
            $table->bigInteger('amount_cop');

            $table->date('received_on');

            $table->string('method', 30);

            // An external reference from a bank or a receipt. Deliberately not
            // unique: institutions reuse references, the field is often empty, and a
            // constraint here would reject real money in exchange for tidiness. The
            // duplicate *warning* is a separate, non-blocking concern.
            $table->string('reference', 120)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->timestamps();

        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check '
            .'CHECK (amount_cop > 0)'
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_method_check '
            ."CHECK (method in ('cash', 'bank_transfer', 'deposit', 'other'))"
        );

        /*
         | Voids are complete or they do not happen.
         |
         | A payment that is void with nobody responsible for it, or with no reason,
         | is worse than one that is simply not void: it looks like money that was
         | taken back on purpose and cannot be reconstructed.
         */
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_void_consistency_check '
            .'CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) '
            .'OR (voided_at IS NOT NULL AND void_reason IS NOT NULL))'
        );

        Schema::table('payments', function (Blueprint $table): void {
            // The payments list: newest first, optionally for one client.
            $table->index(['client_id', 'received_on'], 'payments_client_received_index');
            $table->index(['received_on'], 'payments_received_index');

            // "Payments requiring reconciliation" is: not voided, and with an
            // unallocated balance. Both halves are read as columns.
            $table->index(['voided_at', 'received_on'], 'payments_void_received_index');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('monthly_obligations')->restrictOnDelete();

            // Whole Colombian pesos, positive. What this payment pays against this
            // obligation. Part of a payment against an obligation is ordinary.
            $table->bigInteger('amount_cop');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

        });

        DB::statement(
            'ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive_check '
            .'CHECK (amount_cop > 0)'
        );

        DB::statement(
            'ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_reversal_consistency_check '
            .'CHECK ((reversed_at IS NULL AND reversal_reason IS NULL) '
            .'OR (reversed_at IS NOT NULL AND reversal_reason IS NOT NULL))'
        );

        /*
         | Two live allocations of the same payment to the same obligation are
         | ambiguous: is this one allocation or two? Answering that from a sum would
         | be arithmetic on a question about meaning, so the database refuses it.
         | A reversal frees the pair for a new allocation, which is one of the
         | reasons reversals are markers rather than deletions.
         */

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_allocations_live_pair_unique
                ON payment_allocations (payment_id, obligation_id)
                WHERE reversed_at IS NULL
            SQL);

        Schema::table('payment_allocations', function (Blueprint $table): void {
            // "Every active allocation of this payment" and "of this obligation",
            // which are the two sums the balance formula reads.
            $table->index(['payment_id'], 'payment_allocations_payment_index');
            $table->index(['obligation_id'], 'payment_allocations_obligation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
