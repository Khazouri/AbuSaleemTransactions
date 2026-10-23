<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the paper meeting left behind (user decision 2026-10-23): the committee
 * card's voting and minutes rules (nothing counts a quorum or a majority since
 * the sitting went), the chair's adoption of the agenda, and the decision
 * templates the deleted recording screen started from.
 *
 * Not reversible: the rows are gone, so a rollback restores from a backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committees', function (Blueprint $table) {
            $table->dropColumn([
                'rapporteur_votes',
                'minutes_approval_body', 'voting_rights_note', 'minutes_signature_rule', 'recusal_rules',
                'quorum_type', 'quorum_count', 'quorum_numerator', 'quorum_denominator', 'quorum_comparator', 'quorum_text',
                'majority_type', 'majority_basis', 'majority_numerator', 'majority_denominator', 'majority_comparator', 'majority_text',
                'tie_break', 'tie_break_text',
            ]);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agenda_adopted_by_user_id');
            $table->dropColumn('agenda_adopted_at');
        });

        Schema::table('decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });

        Schema::dropIfExists('templates');
    }

    public function down(): void {}
};
