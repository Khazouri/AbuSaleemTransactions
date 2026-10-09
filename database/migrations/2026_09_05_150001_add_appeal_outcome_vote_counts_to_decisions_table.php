<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary, tallied the
 * same snapshot-column way every other outcome already is. Kept as its own
 * five columns rather than reusing the seven employee_request ones: an
 * appeal decision means something structurally different (Stage 64 mutates
 * the *original* request, not this one), and a shared column would conflate
 * two outcome vocabularies that must never be confused with one another.
 * `decisions.outcome`/`votes.vote` need no further width change — already
 * string(30) since Stage 35, and the longest new key
 * (`appeal_partial_accept`, 21 characters) fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->unsignedInteger('votes_appeal_accept_count')->default(0)->after('votes_abstain_count');
            $table->unsignedInteger('votes_appeal_partial_accept_count')->default(0)->after('votes_appeal_accept_count');
            $table->unsignedInteger('votes_appeal_reject_count')->default(0)->after('votes_appeal_partial_accept_count');
            $table->unsignedInteger('votes_appeal_refer_count')->default(0)->after('votes_appeal_reject_count');
            $table->unsignedInteger('votes_appeal_redo_count')->default(0)->after('votes_appeal_refer_count');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn([
                'votes_appeal_accept_count',
                'votes_appeal_partial_accept_count',
                'votes_appeal_reject_count',
                'votes_appeal_refer_count',
                'votes_appeal_redo_count',
            ]);
        });
    }
};
