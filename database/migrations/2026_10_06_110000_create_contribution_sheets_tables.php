<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A05.2 — contribution sheets ("planillas").
 *
 * ## What this is and is not
 *
 * This is an **internal operational record**: which people were covered for which company in which
 * month, which operator was selected, what each person's liquidated amount was, what reference and
 * payment evidence exists, and who decided what.
 *
 * It is **not** a PILA submission. There is no authoritative contract in this repository for the
 * legal PILA file format, for a SIMPLE or ARUS API, or for the statutory percentage tables, so none
 * of those are invented here. The generated XLSX/PDF are labelled internal and say so on their face.
 *
 * ## Why the lines are a snapshot and not a view
 *
 * A planilla is evidence of a decision made once, about a month that has already happened. If the
 * lines were derived live from `client_company_assignments`, then correcting a person's name or
 * moving a job title would silently rewrite every historical planilla, and nobody could answer "what
 * did we actually pay him for" without reconstructing a past from mutable masters. §9.2 forbids
 * rewriting history as a shortcut; a snapshot is the same principle applied to a new table.
 *
 * The snapshot columns are therefore *not* a second master database. They are a copy made at one
 * moment, and every line keeps the ids it was built from so the copy can be traced back.
 *
 * ## Why the sheet is immutable once it leaves `draft`
 *
 * `paid` is history. The status machine in the application refuses to walk it backwards, and the
 * checks below make the *shape* of each terminal state a database fact: a `paid` row without a
 * payment date, or a `cancelled` row without a reason, cannot be written at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_sheets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('monthly_period_id')->constrained('monthly_periods')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // §10: the operator is an operational selection made by a person. It is never guessed
            // from the company, because a company's filing route is not derivable from its data.
            $table->string('operator', 20);
            $table->string('operator_other_name', 120)->nullable();

            // What the external operator hands back. Either may legitimately be absent depending on
            // the operator, so neither is mandatory in isolation.
            $table->string('sheet_number', 80)->nullable();
            $table->string('reference', 120)->nullable();

            $table->string('status', 20)->default('draft');

            $table->date('submitted_on')->nullable();
            $table->date('paid_on')->nullable();

            $table->text('notes')->nullable();

            // Every state change names who and when. §9.8.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('returned_to_draft_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_to_draft_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // §20: the identity of the evidence this sheet was built from. The create path recomputes
            // it under the topology lock and refuses a sheet whose digest no longer matches, which is
            // what stops a preview and a creation describing different people.
            $table->char('source_digest', 64);

            // Bumped whenever an editable operational value changes, so two updates racing cannot
            // silently merge into one row.
            $table->unsignedInteger('revision')->default(1);

            $table->timestamps();

            $table->index(['monthly_period_id', 'company_id', 'status'], 'contribution_sheets_period_company_status_index');
            $table->index(['operator', 'status'], 'contribution_sheets_operator_status_index');
            $table->index(['status', 'submitted_on'], 'contribution_sheets_status_submitted_index');
        });

        DB::statement("ALTER TABLE contribution_sheets ADD CONSTRAINT contribution_sheets_operator_check CHECK (operator IN ('simple', 'arus', 'other'))");

        DB::statement(<<<'SQL'
            ALTER TABLE contribution_sheets ADD CONSTRAINT contribution_sheets_status_check
            CHECK (status IN ('draft', 'ready', 'submitted', 'paid', 'cancelled'))
            SQL);

        // §16: "If `other`, require a non-empty operator name." The database refuses to record an
        // operator nobody could name, so an unlabelled sheet is not a state this table can be in.
        DB::statement(<<<'SQL'
            ALTER TABLE contribution_sheets ADD CONSTRAINT contribution_sheets_other_named_check
            CHECK (operator <> 'other' OR (operator_other_name IS NOT NULL AND btrim(operator_other_name) <> ''))
            SQL);

        // The shape of each non-draft state, enforced rather than trusted.
        DB::statement(<<<'SQL'
            ALTER TABLE contribution_sheets ADD CONSTRAINT contribution_sheets_state_shape_check
            CHECK (
                (status <> 'submitted' OR submitted_on IS NOT NULL)
                AND
                (status <> 'paid' OR (paid_on IS NOT NULL AND submitted_on IS NOT NULL))
                AND
                (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL
                                           AND btrim(cancellation_reason) <> ''))
            )
            SQL);

        // One live sheet per period/company/operator reference.
        //
        // Partial, and only over `reference`: an operator's reference is the thing that identifies a
        // filing with them, so two sheets claiming the same one for the same month and company is the
        // duplication worth refusing. Cancelled sheets keep their reference for history and must not
        // collide with a replacement.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contribution_sheets_reference_unique
            ON contribution_sheets (monthly_period_id, company_id, reference)
            WHERE reference IS NOT NULL AND status <> 'cancelled'
            SQL);

        Schema::create('contribution_sheet_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contribution_sheet_id')->constrained('contribution_sheets')->cascadeOnDelete();

            // The live references the snapshot was taken from. Restrict rather than cascade: a
            // relationship that A02 history still owns must not disappear out from under a planilla.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('client_company_assignment_id')->constrained('client_company_assignments')->restrictOnDelete();

            // --- the snapshot -------------------------------------------------------
            $table->string('document_type', 10);
            $table->string('document_number', 40);
            $table->string('client_name', 200);

            $table->string('company_tax_id', 40);
            $table->string('company_name', 200);

            $table->date('relationship_started_on');
            $table->string('relationship_started_on_precision', 10);
            $table->date('relationship_ended_on')->nullable();
            $table->string('relationship_ended_on_precision', 10)->nullable();

            // Providers as they applied to this line's operational period. Nullable on purpose:
            // §9.6 forbids inventing a provider, and a missing one is a validation finding rather
            // than a fabricated value.
            $table->string('eps_name', 200)->nullable();
            $table->string('afp_name', 200)->nullable();
            $table->string('arl_name', 200)->nullable();
            $table->string('ccf_name', 200)->nullable();

            $table->string('arl_risk_class', 20)->nullable();
            $table->string('job_title', 120)->nullable();

            // §9.3: integer COP. Never a float. Null until a person states the operational figure,
            // because the system does not compute it.
            $table->bigInteger('liquidated_amount_cop')->nullable();

            $table->boolean('included')->default(true);
            $table->text('exclusion_reason')->nullable();

            // §18: enough evidence to audit why this person was on the sheet.
            $table->jsonb('source_evidence')->nullable();

            $table->timestamps();

            $table->index(['contribution_sheet_id'], 'contribution_sheet_lines_sheet_index');
            $table->index(['client_id'], 'contribution_sheet_lines_client_index');
        });

        // §18: a person appears once per sheet. This is what makes "duplicate included line" a
        // database fact rather than a validation routine that could be forgotten.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contribution_sheet_lines_sheet_assignment_unique
            ON contribution_sheet_lines (contribution_sheet_id, client_company_assignment_id)
            SQL);

        DB::statement('ALTER TABLE contribution_sheet_lines ADD CONSTRAINT contribution_sheet_lines_amount_non_negative_check CHECK (liquidated_amount_cop IS NULL OR liquidated_amount_cop >= 0)');

        // §23: excluding a generated candidate requires a reason, and including one without a
        // reason is refused so the two halves cannot be confused by a later import.
        DB::statement(<<<'SQL'
            ALTER TABLE contribution_sheet_lines ADD CONSTRAINT contribution_sheet_lines_exclusion_reason_check
            CHECK (included OR (exclusion_reason IS NOT NULL AND btrim(exclusion_reason) <> ''))
            SQL);

        Schema::create('contribution_sheet_files', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contribution_sheet_id')->constrained('contribution_sheets')->cascadeOnDelete();

            // §25.
            $table->string('kind', 30);

            // The operator's own filename is metadata. The physical name is generated and never
            // derived from user input, so a crafted name cannot escape the directory or overwrite a
            // neighbouring file.
            $table->string('original_name', 255);
            $table->string('stored_path', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['contribution_sheet_id'], 'contribution_sheet_files_sheet_index');
        });

        DB::statement("ALTER TABLE contribution_sheet_files ADD CONSTRAINT contribution_sheet_files_kind_check CHECK (kind IN ('operator_pdf', 'payment_receipt', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_sheet_files');
        Schema::dropIfExists('contribution_sheet_lines');
        Schema::dropIfExists('contribution_sheets');
    }
};
