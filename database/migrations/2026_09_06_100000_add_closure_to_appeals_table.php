<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 65, Track J — Art. 75 point 6 + [D] Arts. 34–37's closure-field
 * list. No existing closure-record precedent to mirror (verified by grep —
 * see AGENT_NOTES.md), so these are new columns rather than a reuse of any
 * prior stage's shape. `closure` carries the 6 fields with no dedicated
 * column of their own (final result code, final decision number, approving
 * body, execution date, executing body, file storage location, notice
 * status); `closed_at` is the closure date itself, matching every other
 * Track J milestone's own `*_at`/`*_by_user_id` pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->json('closure')->nullable()->after('outcome_redo_stage_id');
            $table->foreignId('closed_by_user_id')->nullable()->after('closure')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn(['closure', 'closed_at']);
        });
    }
};
