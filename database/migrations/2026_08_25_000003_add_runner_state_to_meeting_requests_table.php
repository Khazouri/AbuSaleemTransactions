<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 34 — the live runner's per-item progress. `state_changed_at` is what
 * the runner's timer panel reads (elapsed = now - state_changed_at), so any
 * attendee polling GET /meetings/{id} sees the same clock the chair does
 * with no separate timer-sync mechanism needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->string('item_state', 20)->default('presented')->after('estimated_minutes');
            $table->timestamp('state_changed_at')->nullable()->after('item_state');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->dropColumn(['item_state', 'state_changed_at']);
        });
    }
};
