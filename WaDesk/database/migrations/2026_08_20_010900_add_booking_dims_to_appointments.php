<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the existing appointments table with the booking dimensions. Additive
 * + nullable → safe on existing rows. Key additions:
 *  - active_slot_key (UNIQUE) — the HARD double-book guard. Set while active
 *    ({type}:{epoch}:{seat}); NULL when cancelled/no_show (MySQL treats multiple
 *    NULLs as non-conflicting, so freed slots re-open).
 *  - checked_in_at — the ATTENDED signal (set by staff/host or a check-in);
 *    the lifecycle sweeper NEVER guesses no_show/completed from the clock.
 *  - manage_token (UNIQUE) — random capability token for the public manage page.
 *  - reminders_sent — per-offset dedupe so Node timer + sweeper never double-send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'booking_type_id')) {
                $table->foreignId('booking_type_id')->nullable()->after('workspace_id')
                    ->constrained('booking_types')->nullOnDelete();
            }
            if (! Schema::hasColumn('appointments', 'staff_id')) {
                $table->unsignedBigInteger('staff_id')->nullable()->after('booking_type_id')->index();
            }
            if (! Schema::hasColumn('appointments', 'invitee_timezone')) {
                $table->string('invitee_timezone', 64)->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('appointments', 'payment_status')) {
                $table->enum('payment_status', ['none', 'pending', 'paid', 'partial', 'refunded'])->default('none')->after('status');
            }
            if (! Schema::hasColumn('appointments', 'deposit_paid_minor')) {
                $table->unsignedBigInteger('deposit_paid_minor')->default(0)->after('payment_status');
            }
            if (! Schema::hasColumn('appointments', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->after('deposit_paid_minor')->index();
            }
            if (! Schema::hasColumn('appointments', 'answers')) {
                $table->json('answers')->nullable()->after('meta');
            }
            if (! Schema::hasColumn('appointments', 'meet_url')) {
                $table->string('meet_url', 255)->nullable()->after('google_calendar_id');
            }
            if (! Schema::hasColumn('appointments', 'manage_token')) {
                $table->string('manage_token', 64)->nullable()->after('meet_url');
            }
            if (! Schema::hasColumn('appointments', 'checked_in_at')) {
                $table->dateTime('checked_in_at')->nullable()->after('reminder_sent_at');
            }
            if (! Schema::hasColumn('appointments', 'reminders_sent')) {
                $table->json('reminders_sent')->nullable()->after('checked_in_at');
            }
            if (! Schema::hasColumn('appointments', 'source')) {
                $table->enum('source', ['chat', 'link', 'manual', 'api'])->default('chat')->after('reminders_sent');
            }
            if (! Schema::hasColumn('appointments', 'capacity_used')) {
                $table->unsignedSmallInteger('capacity_used')->default(1)->after('source');
            }
            if (! Schema::hasColumn('appointments', 'active_slot_key')) {
                $table->string('active_slot_key', 191)->nullable()->after('capacity_used');
            }
        });

        // Unique/index added separately, each guarded so a re-run can't fail on a
        // duplicate-index error (the columns above are freshly added on first run).
        Schema::table('appointments', function (Blueprint $table) {
            try { $table->unique('active_slot_key'); } catch (\Throwable $e) {}
            try { $table->unique('manage_token'); } catch (\Throwable $e) {}
            try { $table->index(['workspace_id', 'booking_type_id', 'starts_at']); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            foreach ([
                'booking_type_id', 'staff_id', 'invitee_timezone', 'payment_status',
                'deposit_paid_minor', 'order_id', 'answers', 'meet_url', 'manage_token',
                'checked_in_at', 'reminders_sent', 'source', 'capacity_used', 'active_slot_key',
            ] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    try { $table->dropColumn($col); } catch (\Throwable $e) {}
                }
            }
        });
    }
};
