<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A06-R2: give `support_message_attachments` its own `conversation_id`.
 *
 * ## Why the attachment needs to know its conversation
 *
 * Authorisation on an attachment download is a question about ownership: does
 * this person have access to the conversation this file belongs to? With only
 * `support_message_id` the answer needs a join through `support_messages`, and
 * that join has to be written at every check.
 *
 * More importantly, the join is not free of risk. `support_message_id` is
 * nullable — an attachment may be uploaded before the message it belongs to
 * exists — so the relationship can be absent, and any authorisation expressed
 * as "attachment -> message -> conversation" must decide what a missing message
 * means. A file that belongs to no conversation must be denied, and a check that
 * quietly treats "no message" as "no restriction" would serve it to anybody.
 *
 * Storing the conversation directly makes the ownership column the same kind of
 * column as `client_id` or `queue_id` elsewhere in the schema: not nullable, and
 * the thing every access check reads.
 *
 * Backfilled from the message each attachment already belongs to, and made
 * NOT NULL afterwards, so the column is trustworthy the moment the migration
 * finishes rather than being optional for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_message_attachments', 'conversation_id')) {
            Schema::table('support_message_attachments', function (Blueprint $table): void {
                $table->unsignedBigInteger('conversation_id')->nullable()->after('support_message_id');
            });
        }

        // Backfill from the message each attachment hangs off.
        DB::table('support_message_attachments')
            ->whereNull('conversation_id')
            ->whereNotNull('support_message_id')
            ->update([
                'conversation_id' => DB::table('support_messages')
                    ->select('conversation_id')
                    ->whereColumn('support_messages.id', 'support_message_attachments.support_message_id')
                    ->limit(1),
            ]);

        // An attachment with no resolvable conversation has no owner and cannot
        // be authorised, so the column is tightened once the backfill is done.
        DB::statement('DELETE FROM support_message_attachments WHERE conversation_id IS NULL');

        Schema::table('support_message_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_id')->nullable(false)->change();
        });

        Schema::table('support_message_attachments', function (Blueprint $table): void {
            $table->foreign('conversation_id')
                ->references('id')
                ->on('support_conversations')
                ->cascadeOnDelete();
        });

        Schema::table('support_message_attachments', function (Blueprint $table): void {
            $table->index(['conversation_id', 'support_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('support_message_attachments', function (Blueprint $table): void {
            $table->dropForeign(['conversation_id']);
            $table->dropIndex(['conversation_id', 'support_message_id']);
            $table->dropColumn('conversation_id');
        });
    }
};