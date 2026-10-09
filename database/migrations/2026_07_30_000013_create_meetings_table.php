<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEETINGS (الاجتماعات) — a scheduled sitting of one committee.
 *
 * `committee_id` is restrictOnDelete rather than cascade: a committee that has
 * held meetings carries history worth keeping, so CommitteeController::destroy
 * checks for exactly this and blocks with a friendly message before the
 * database constraint would ever have to reject it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->dateTime('scheduled_at');
            $table->string('location')->nullable();

            // scheduled -> completed|cancelled. No workflow-engine tie-in yet;
            // Stage 21 adds decisions/votes that act on the agenda items.
            $table->string('status', 20)->default('scheduled');

            // Filled in after the meeting is held.
            $table->text('minutes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['committee_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
