<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VOTES — one committee member's vote on one agenda item.
 *
 * `vote` is a plain string (`approve|reject|defer`), not a lookup table:
 * these three values are fixed by DecisionController::ACTIONS, which maps
 * each one onto the workflow action a recorded decision will trigger.
 * A member can change their vote up until a Decision exists for the agenda
 * item (see DecisionController::vote), so this stays an upsert target rather
 * than an append-only log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('vote', 20);
            $table->text('comment')->nullable();
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['meeting_transaction_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
