<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONFLICT_OF_INTEREST_DECLARATIONS — Stage 48, [D] Art. 11/15/18.
 *
 * One row per (agenda item, member) — a member with a stake in a request
 * formally discloses it, which is itself the recusal: once the row exists,
 * DecisionEligibility blocks that user from voting or joining the discussion
 * feed on this item (ConflictOfInterestController/MeetingDiscussionNoteController),
 * and MeetingMinutesCompiler folds it into the compiled record.
 *
 * Shaped like `votes` (same cascade + unique-per-item-per-user pair): a
 * member may re-declare (e.g. correcting the stated reason) up until a
 * decision exists, same upsert-not-append-only precedent `votes.vote` set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflict_of_interest_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('declared_at');
            $table->timestamps();

            // Explicit short name: the default derived index name exceeds
            // MySQL's 64-character identifier limit for this table name.
            $table->unique(['meeting_request_id', 'user_id'], 'coi_declarations_item_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_of_interest_declarations');
    }
};
