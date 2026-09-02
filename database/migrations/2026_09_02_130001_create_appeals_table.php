<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEALS (التظلمات) — Stage 58, Track J.
 * ---------------------------------------------------------------------------
 * A contest against an already-DECIDED request, never a Request itself and
 * never routed through WorkflowService/workflow_stages — see STAGE_PLAN.md
 * Track J's intro, scope decisions (1) and (2).
 *
 * `original_decision_id` is nullable because not every decided matter rode a
 * committee vote — e.g. Stage 54's `reject_formally` outcome at
 * requirements_check never creates a `decisions` row. `original_decision_
 * reference`/`_date` are the free-text fallback for exactly that case, so an
 * appeal against a Decision-less rejection still has something to point at.
 *
 * No ownership/status/duplication validation is enforced yet — that's Stage
 * 59's scope (see AGENT_NOTES.md). This migration only makes the entity and
 * its FKs exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appellant_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Requests are never deleted (no destroy route exists), so the
            // default RESTRICT-on-delete behaviour is fine as-is — an appeal
            // must always be able to point at its target.
            $table->foreignId('original_request_id')->constrained('requests');

            $table->foreignId('original_decision_id')->nullable()
                ->constrained('decisions')->nullOnDelete();
            $table->string('original_decision_reference')->nullable();
            $table->date('original_decision_date')->nullable();

            $table->foreignId('appeal_status_id')->nullable()
                ->constrained('appeal_statuses')->nullOnDelete();

            $table->timestamps();

            $table->index(['original_request_id']);
            $table->index(['appellant_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};
