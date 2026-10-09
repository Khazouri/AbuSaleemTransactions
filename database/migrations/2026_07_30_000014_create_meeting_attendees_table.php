<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEETING_ATTENDEES — who was invited to a meeting and whether they showed up.
 *
 * Rows are seeded automatically from the committee's active membership when a
 * meeting is created (see MeetingController::store), then adjusted from the
 * meeting screen for ad-hoc invitees. `attended` starts false and is flipped
 * individually as roll is taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('attended')->default(false);
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendees');
    }
};
