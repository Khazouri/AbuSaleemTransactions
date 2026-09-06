<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 66, Track J — [D] Arts. 78–79's non-reopening rule: a closed appeal
 * may only be reopened for one of App\Services\ReopenReasonCatalog's
 * enumerated reasons. Unlike every other Track J milestone, `Appeal` has no
 * dedicated log table (RequestStageLog's equivalent for the original
 * Request) to carry a structured reason, so these columns are the only place
 * "why was this reopened" can live — AuditObserver picks the update up for
 * free since Appeal is already in AuditLog::AUDITED_MODELS. Records only the
 * most recent reopen, matching every other Track J who/when pair's
 * "latest occurrence, full history in AuditLog" precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('closed_at');
            $table->foreignId('reopened_by_user_id')->nullable()->after('reopened_at')
                ->constrained('users')->nullOnDelete();
            $table->string('reopen_reason_code')->nullable()->after('reopened_by_user_id');
            $table->text('reopen_reason_note')->nullable()->after('reopen_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by_user_id');
            $table->dropColumn(['reopened_at', 'reopen_reason_code', 'reopen_reason_note']);
        });
    }
};
