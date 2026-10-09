<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEETING_MINUTE_SIGNATURES — one required signer per set of minutes.
 *
 * Rows are created in bulk, one per attendee with `attended=true`, the
 * moment the head approves the draft (see MeetingMinutesController::review).
 * `signature_path`/`signed_at` start null and are filled in by that signer's
 * own `sign()` call; once every row here has a `signed_at`, the parent
 * `meeting_minutes.status` auto-advances to `approved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minute_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_minutes_id')->constrained('meeting_minutes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_minutes_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minute_signatures');
    }
};
