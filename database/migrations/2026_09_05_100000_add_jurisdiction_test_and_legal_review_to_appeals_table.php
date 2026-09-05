<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEALS jurisdiction-test + legal-review fields — Stage 62, Track J.
 * ---------------------------------------------------------------------------
 * Art. 77's competent-body question (is this the committee's matter, or does
 * it belong to the mayor / ministry / another org body / a disciplinary
 * board or court?) and Art. 75 point 4's 5-question legal-review checklist,
 * each recorded once by AppealController's new recordJurisdictionTest()/
 * recordLegalReview() actions. Same shape as Stage 60's formal-verification
 * columns: a json answer blob plus separate who/when columns, not one
 * combined structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->json('jurisdiction_test')->nullable()->after('formal_verified_at');
            $table->foreignId('jurisdiction_tested_by_user_id')->nullable()->after('jurisdiction_test')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('jurisdiction_tested_at')->nullable()->after('jurisdiction_tested_by_user_id');
            $table->json('legal_review')->nullable()->after('jurisdiction_tested_at');
            $table->foreignId('legal_reviewed_by_user_id')->nullable()->after('legal_review')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('legal_reviewed_at')->nullable()->after('legal_reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jurisdiction_tested_by_user_id');
            $table->dropConstrainedForeignId('legal_reviewed_by_user_id');
            $table->dropColumn(['jurisdiction_test', 'jurisdiction_tested_at', 'legal_review', 'legal_reviewed_at']);
        });
    }
};
