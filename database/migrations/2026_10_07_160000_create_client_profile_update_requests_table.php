<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_profile_update_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();

            $table->jsonb('proposed_changes');

            $table->string('status', 15)->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status'], 'client_profile_update_requests_client_status_index');
            $table->index(['status'], 'client_profile_update_requests_status_index');
        });

        DB::statement("ALTER TABLE client_profile_update_requests ADD CONSTRAINT client_profile_update_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
        DB::statement(<<<'SQL'
            ALTER TABLE client_profile_update_requests ADD CONSTRAINT client_profile_update_requests_review_shape_check
            CHECK (
                (status = 'pending' OR reviewed_at IS NOT NULL)
                AND
                (status <> 'approved' OR (applied_at IS NOT NULL))
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE client_profile_update_requests ADD CONSTRAINT client_profile_update_requests_rejection_reason_check
            CHECK (status <> 'rejected' OR (review_note IS NOT NULL AND btrim(review_note) <> ''))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('client_profile_update_requests');
    }
};
