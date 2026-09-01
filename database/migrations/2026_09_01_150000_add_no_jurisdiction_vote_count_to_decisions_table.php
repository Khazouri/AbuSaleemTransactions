<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 49 — a seventh committee-decision outcome, "عدم اختصاص" (matter
 * outside the committee's jurisdiction, [D] status 14), distinct from Stage
 * 35's `refer_other_body` (asking another body for input — one of [D] Art.
 * 26's deferral reasons, not a jurisdiction declaration). `votes.vote` and
 * `decisions.outcome` already widened to string(30) in Stage 35;
 * `no_jurisdiction` fits without a further change. Matches the shape of the
 * existing `votes_refer_other_body_count` column exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->unsignedInteger('votes_no_jurisdiction_count')->default(0)->after('votes_refer_other_body_count');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn('votes_no_jurisdiction_count');
        });
    }
};
