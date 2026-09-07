<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة, plus the quorum/majority
 * rules Appendix 64 says the system may not invent for itself ("ولا يجوز
 * للدليل إنشاء نسبة نصاب أو أغلبية من تلقاء نفسه").
 *
 * Every column is nullable and NOTHING is backfilled: a committee whose
 * formation decision has not been transcribed yet has no quorum, and the
 * readiness gate reports that rather than computing one. Seeding the old
 * `ceil(activeMembers / 2)` here would re-assert the invented ratio as
 * though it were sourced, which is the single thing Appendix 64 forbids.
 *
 * `meetings.voting_rules_snapshot` is the answer to "what happens to a
 * meeting already held?" — convening freezes the rules that were in force,
 * so a later edit to the committee card cannot retroactively change what a
 * held meeting's readiness or محضر says (Art. 88's محضر is a record, not a
 * live view).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committees', function (Blueprint $table) {
            // Appendix 65's descriptive half — transcribed from قرار التشكيل.
            $table->string('formation_decision_number')->nullable()->after('rapporteur_votes');
            $table->date('formation_decision_date')->nullable()->after('formation_decision_number');
            $table->string('term_note')->nullable()->after('formation_decision_date');
            $table->text('legal_basis')->nullable()->after('term_note');
            $table->string('minutes_approval_body')->nullable()->after('legal_basis');
            $table->text('voting_rights_note')->nullable()->after('minutes_approval_body');
            $table->text('minutes_signature_rule')->nullable()->after('voting_rights_note');
            $table->text('recusal_rules')->nullable()->after('minutes_signature_rule');

            // Appendix 64's computable half. Each rule carries the source's
            // own wording beside the structured form so the number the system
            // applies can be audited against the text it came from.
            $table->string('quorum_type', 20)->nullable()->after('recusal_rules');
            $table->unsignedTinyInteger('quorum_count')->nullable()->after('quorum_type');
            $table->unsignedTinyInteger('quorum_numerator')->nullable()->after('quorum_count');
            $table->unsignedTinyInteger('quorum_denominator')->nullable()->after('quorum_numerator');
            $table->string('quorum_comparator', 20)->nullable()->after('quorum_denominator');
            $table->text('quorum_text')->nullable()->after('quorum_comparator');

            $table->string('majority_type', 20)->nullable()->after('quorum_text');
            $table->string('majority_basis', 20)->nullable()->after('majority_type');
            $table->unsignedTinyInteger('majority_numerator')->nullable()->after('majority_basis');
            $table->unsignedTinyInteger('majority_denominator')->nullable()->after('majority_numerator');
            $table->string('majority_comparator', 20)->nullable()->after('majority_denominator');
            $table->text('majority_text')->nullable()->after('majority_comparator');

            $table->string('tie_break', 30)->nullable()->after('majority_text');
            $table->text('tie_break_text')->nullable()->after('tie_break');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->json('voting_rules_snapshot')->nullable()->after('readiness_override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('voting_rules_snapshot');
        });

        Schema::table('committees', function (Blueprint $table) {
            $table->dropColumn([
                'formation_decision_number', 'formation_decision_date', 'term_note', 'legal_basis',
                'minutes_approval_body', 'voting_rights_note', 'minutes_signature_rule', 'recusal_rules',
                'quorum_type', 'quorum_count', 'quorum_numerator', 'quorum_denominator',
                'quorum_comparator', 'quorum_text',
                'majority_type', 'majority_basis', 'majority_numerator', 'majority_denominator',
                'majority_comparator', 'majority_text',
                'tie_break', 'tie_break_text',
            ]);
        });
    }
};
