<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 35 — three more committee-decision outcomes alongside
 * approve/reject/defer: conditional_approval, legal_opinion, refer_other_body
 * (see DecisionController::ACTIONS). `conditional_approval` alone is 21
 * characters, so both `votes.vote` and `decisions.outcome` widen from
 * string(20). `decisions` also gains a snapshot count per new outcome
 * (matching the shape of the three existing `votes_*_count` columns exactly)
 * and an optional `template_id` recording which reusable text, if any, the
 * decision's comment was drafted from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->string('vote', 30)->change();
        });

        Schema::table('decisions', function (Blueprint $table) {
            $table->string('outcome', 30)->change();
            $table->foreignId('template_id')->nullable()->after('meeting_request_id')
                ->constrained('templates')->nullOnDelete();
            $table->unsignedInteger('votes_conditional_approval_count')->default(0)->after('votes_defer_count');
            $table->unsignedInteger('votes_legal_opinion_count')->default(0)->after('votes_conditional_approval_count');
            $table->unsignedInteger('votes_refer_other_body_count')->default(0)->after('votes_legal_opinion_count');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn([
                'votes_conditional_approval_count',
                'votes_legal_opinion_count',
                'votes_refer_other_body_count',
            ]);
            $table->string('outcome', 20)->change();
        });

        Schema::table('votes', function (Blueprint $table) {
            $table->string('vote', 20)->change();
        });
    }
};
