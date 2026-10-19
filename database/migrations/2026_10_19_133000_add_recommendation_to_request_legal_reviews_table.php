<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-19 — the legal member enters a توصية and جهة الاعتماد only (user
 * decision). Rows recorded before then keep their Art. 21 verdict and
 * Appendix 22 card, so `verdict` becomes nullable rather than going away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_legal_reviews', function (Blueprint $table) {
            $table->string('recommendation', 20)->nullable()->after('prohibiting_conditions');   // approve | decline
            $table->string('approving_authority', 20)->nullable()->after('recommendation');      // dean | minister
            $table->string('verdict', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('request_legal_reviews', function (Blueprint $table) {
            $table->dropColumn(['recommendation', 'approving_authority']);
        });
    }
};
