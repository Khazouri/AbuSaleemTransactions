<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 99 — [D] Appendix 6 rows 8 and 11: the committee's own acts.
 *
 * `meetings.agenda_adopted_*` records Art. 84's «اعتماد جدول الأعمال» (row 8,
 * اللجنة «اعتماد تنظيمي»), which nothing recorded before. `meeting_minutes.
 * legal_review_*` is row 11's العضو القانوني «مراجعة عند الحاجة» — optional by
 * the cell's own wording, so nullable and never a gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('agenda_adopted_at')->nullable()->after('convened_by_user_id');
            $table->foreignId('agenda_adopted_by_user_id')->nullable()->after('agenda_adopted_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->text('legal_review_note')->nullable()->after('review_comment');
            $table->foreignId('legal_reviewed_by_user_id')->nullable()->after('legal_review_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('legal_reviewed_at')->nullable()->after('legal_reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_reviewed_by_user_id');
            $table->dropColumn(['legal_review_note', 'legal_reviewed_at']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agenda_adopted_by_user_id');
            $table->dropColumn('agenda_adopted_at');
        });
    }
};
