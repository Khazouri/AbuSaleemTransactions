<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 30 — the 5-step scheduling wizard's "details" step needs more than a
 * bare title/time/location: a formal number, a type classification, named
 * chairman/rapporteur, an expected duration, an agenda cutoff, and a
 * description. `meeting_type` stores English codes (mirroring `status`), not
 * literal Arabic strings — the frontend maps codes to دوري/استثنائي/طارئ via
 * i18n, same as scheduled/completed/cancelled already do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('meeting_number')->nullable()->after('id');
            $table->string('meeting_type', 20)->default('regular')->after('title');
            $table->foreignId('chairman_user_id')->nullable()
                ->after('location')->constrained('users')->nullOnDelete();
            $table->foreignId('rapporteur_user_id')->nullable()
                ->after('chairman_user_id')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('expected_duration_minutes')->nullable()->after('rapporteur_user_id');

            // A cutoff for last agenda additions before the meeting — needs a
            // time component to mean anything, so dateTime not date.
            $table->dateTime('agenda_deadline')->nullable()->after('expected_duration_minutes');
            $table->text('description')->nullable()->after('agenda_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chairman_user_id');
            $table->dropConstrainedForeignId('rapporteur_user_id');
            $table->dropColumn([
                'meeting_number',
                'meeting_type',
                'expected_duration_minutes',
                'agenda_deadline',
                'description',
            ]);
        });
    }
};
