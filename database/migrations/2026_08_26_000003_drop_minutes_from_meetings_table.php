<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 36 replaces the single free-text `minutes` field (Stage 30) with the
 * generated/reviewed/signed/approved document in `meeting_minutes`. Its only
 * reader was `MeetingDetailView.vue`'s textarea, now a link to the new screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('minutes');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->text('minutes')->nullable();
        });
    }
};
