<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paper meeting, slice 2 (user decision 2026-10-18): the committee meets on
 * paper, so the in-system sitting and the execution leg are gone, and so is
 * what only they wrote — votes, the discussion feed, generated and signed
 * minutes, the presentation memo, conflict declarations, the study sequence,
 * convening and roll call, Art. 103's soundness card and the execution card.
 * `decisions` keeps every column: the register still reads past rows.
 *
 * Not reversible: the rows are gone, so a rollback restores from a backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children before parents: signatures hang off the minutes.
        Schema::dropIfExists('meeting_minute_signatures');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('votes');
        Schema::dropIfExists('meeting_discussion_notes');
        Schema::dropIfExists('presentation_memos');
        Schema::dropIfExists('conflict_of_interest_declarations');

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convened_by_user_id');
            $table->dropColumn(['convened_at', 'readiness_override_reason', 'voting_rules_snapshot']);
        });

        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->dropColumn('attended');
        });

        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->dropColumn(['study_sequence', 'study_sequence_completed_at']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('executed_by_user_id');
            $table->dropConstrainedForeignId('execution_soundness_checked_by_user_id');
            $table->dropColumn(['execution', 'execution_checklist', 'executed_at', 'execution_soundness', 'execution_soundness_checked_at']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('execution_evidence_type');
        });
    }

    public function down(): void {}
};
