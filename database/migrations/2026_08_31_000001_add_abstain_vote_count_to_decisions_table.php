<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 41 — a committee member who attended but doesn't vote either way.
 * Snapshot count only, matching the shape of the existing `votes_*_count`
 * columns exactly; `votes.vote` needs no width change since `abstain` fits
 * well within the 30 characters Stage 35 already widened it to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->unsignedInteger('votes_abstain_count')->default(0)->after('votes_refer_other_body_count');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn('votes_abstain_count');
        });
    }
};
