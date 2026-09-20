<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resume cursor for the MailTrixy pull sync.
 *
 * The bridge was push-only, so mail predating a link was never delivered and
 * any push that blew its 8s timeout was lost for good. MailtrixySyncService
 * pulls instead; this column remembers the highest MailTrixy message id already
 * imported for the mailbox, so a repeat sync asks only for what is new rather
 * than re-walking the whole history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_email_accounts')) {
            return;
        }
        if (Schema::hasColumn('workspace_email_accounts', 'mtx_last_message_id')) {
            return;
        }

        Schema::table('workspace_email_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('mtx_last_message_id')
                ->nullable()
                ->after('mtx_workspace_id')
                ->comment('Highest MailTrixy message id already imported (pull cursor)');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspace_email_accounts')) {
            return;
        }
        if (! Schema::hasColumn('workspace_email_accounts', 'mtx_last_message_id')) {
            return;
        }

        Schema::table('workspace_email_accounts', function (Blueprint $table) {
            $table->dropColumn('mtx_last_message_id');
        });
    }
};
