<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEALS intake fields — Stage 59, Track J.
 * ---------------------------------------------------------------------------
 * [A] §9 step 1's real intake fields, beyond Stage 58's bare target pointers:
 * تاريخ العلم به (known_at), أسباب الاعتراض (appeal_reasons), الطلب النهائي
 * (final_request). `new_facts_declaration` backs [A] §9 step 2's
 * non-duplication check ("عدم تكرار نفس التظلم دون وقائع جديدة") — a second
 * appeal against the same request is refused unless this is filled in; see
 * App\Services\AppealEligibility.
 *
 * All four are nullable at the schema layer — StoreAppealRequest/
 * AppealEligibility enforce which are actually required, and by when, since
 * that depends on data (does a prior appeal exist? was a Decision found?)
 * that only the controller can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->date('known_at')->nullable()->after('original_decision_date');
            $table->text('appeal_reasons')->nullable()->after('known_at');
            $table->text('final_request')->nullable()->after('appeal_reasons');
            $table->text('new_facts_declaration')->nullable()->after('final_request');
        });
    }

    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropColumn(['known_at', 'appeal_reasons', 'final_request', 'new_facts_declaration']);
        });
    }
};
