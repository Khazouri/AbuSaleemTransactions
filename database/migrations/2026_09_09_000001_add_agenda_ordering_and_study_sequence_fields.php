<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 82 — [D] Arts. 83 and 85, Appendices 24 and 25.
 *
 * Three additions to an agenda item and one to the meeting it sits on:
 *
 * - `priority_reason` — Appendix 24 closes its priority-levels section with
 *   "ولا يجوز استخدام الأولوية لتجاوز ترتيب المعاملات دون مبرر إداري موثق",
 *   so a declared high priority that no derived ground supports has to carry
 *   the justification that makes it legitimate. Appendix 33's five enumerated
 *   urgent reasons are Stage 83's; this column is Appendix 24's own free-text
 *   مبرر, and that stage is where it should be structured.
 * - `study_sequence` — Art. 85's nine-step per-item sequence (النموذج 11's own
 *   card), one entry per attested step recording when it was marked and by
 *   whom. The two derived steps (التصويت، إثبات النتيجة) are never stored:
 *   they are read from the item's own votes and decision.
 * - `study_sequence_completed_at` — denormalised from the JSON above so
 *   DecisionEligibility's worklist SQL and its per-item guard can read the
 *   same fact. A JSON predicate would have had to be written twice, once per
 *   driver, and the whole point of that service is that the two halves cannot
 *   disagree.
 * - `meetings.agenda_order_justification` — Art. 83's fifth rule lets the
 *   chair order the agenda as they see fit "بما لا يخل بالمساواة وسلامة
 *   الإجراءات"; Appendix 24 requires the departure to be documented. This is
 *   that record, and MeetingReadinessService refuses to convene a sitting
 *   whose agenda departs from the computed order without one.
 *
 * The `priority` column keeps its type and gains no constraint: Appendix 24
 * defines exactly two levels (عالية / عادية), replacing Stage 31's invented
 * high/medium/low, so the existing values are folded down rather than a new
 * column being added beside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->string('priority_reason')->nullable()->after('priority');
            $table->json('study_sequence')->nullable()->after('state_changed_at');
            $table->timestamp('study_sequence_completed_at')->nullable()->after('study_sequence');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->text('agenda_order_justification')->nullable()->after('description');
        });

        // Appendix 24 has two levels, not three. Stage 31's `medium`/`low`
        // were its own invention and both mean the appendix's أولوية عادية.
        DB::table('meeting_requests')
            ->whereIn('priority', ['medium', 'low'])
            ->update(['priority' => 'normal']);
    }

    public function down(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->dropColumn(['priority_reason', 'study_sequence', 'study_sequence_completed_at']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('agenda_order_justification');
        });
    }
};
