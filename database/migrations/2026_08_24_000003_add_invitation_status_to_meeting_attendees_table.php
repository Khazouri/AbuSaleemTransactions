<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 30 — tracks each invitee's RSVP separately from `attended` (which is
 * roll-call at the meeting itself). `pending` is the default for both the
 * auto-invited committee members and any manually added attendee; it only
 * moves to confirmed/declined/no_response when staff record a response (see
 * MeetingController::markAttendance), which also stamps `responded_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->string('invitation_status', 20)->default('pending')->after('user_id');
            $table->dateTime('responded_at')->nullable()->after('attended');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->dropColumn(['invitation_status', 'responded_at']);
        });
    }
};
