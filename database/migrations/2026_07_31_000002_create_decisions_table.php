<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DECISIONS — the committee's binding, tallied outcome for one agenda item.
 *
 * `meeting_request_id` is unique: once a decision exists for an agenda
 * item, DecisionController closes voting on it (see DecisionController::vote
 * and ::record). The vote counts are a snapshot taken at the moment of
 * decision, kept alongside the individual `votes` rows as a stable record of
 * what was tallied even if those rows are later inspected in detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('outcome', 20);
            $table->unsignedInteger('votes_approve_count')->default(0);
            $table->unsignedInteger('votes_reject_count')->default(0);
            $table->unsignedInteger('votes_defer_count')->default(0);
            $table->text('comment')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
