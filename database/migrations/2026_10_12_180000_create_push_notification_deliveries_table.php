<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record that one notification was already sent, for one person, once.
 *
 * ## Why the queue is not enough
 *
 * A queued notification can be delivered twice for reasons that have nothing to
 * do with the person: a worker is killed after the push service accepted the
 * message but before the acknowledgement is recorded, and the job is redelivered.
 * The second run has no way to tell that from a first run.
 *
 * The unique key is what makes "tell" possible. It is written *before* the send
 * rather than after, so a crash between the two loses a notification instead of
 * sending one twice - which is the right way round for a message that interrupts
 * somebody.
 *
 * ## What is keyed on
 *
 * The thing the notification is *about*, plus the person it is for. Two people
 * waiting on two tasks are two deliveries; the same person told twice about the
 * same task is one, no matter how many workers noticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_notification_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Kept loose rather than a foreign key to `ai_tasks`: this ledger is
            // about delivery, and it must keep working for notifications about
            // work that has since been cleaned up.
            $table->string('notification_type', 64);

            $table->string('dedupe_key', 191);

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // The guarantee itself, not a lookup.
            $table->unique(['user_id', 'dedupe_key']);

            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notification_deliveries');
    }
};
