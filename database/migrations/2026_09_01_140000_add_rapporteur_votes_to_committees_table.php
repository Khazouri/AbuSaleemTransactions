<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 48 — [D] Art. 11/15/18: مقرر اللجنة (the rapporteur) does not
 * participate in the substantive vote unless the committee's own تشكيل
 * (formation) decision explicitly grants it. No dedicated tashkil table
 * exists in this schema, so the flag lives on the committee record itself —
 * `false` (the standard's default) means the meeting's `rapporteur_user_id`
 * (see the `meetings` table) is excluded from voting on every item; `true`
 * means this committee's own formation decision granted dual status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committees', function (Blueprint $table) {
            $table->boolean('rapporteur_votes')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('committees', function (Blueprint $table) {
            $table->dropColumn('rapporteur_votes');
        });
    }
};
