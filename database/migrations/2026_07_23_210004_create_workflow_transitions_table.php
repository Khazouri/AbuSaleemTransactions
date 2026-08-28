<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WORKFLOW_TRANSITIONS — the state machine, stored as data
 * ---------------------------------------------------------------------------
 * THE most important table in the system. Each row is one legal move:
 *
 *   "from stage X, action `approve`, performed by role R02,
 *    moves the request to stage Y and sets its status to `ready`"
 *
 * Because the rules live in rows rather than in PHP `if` statements, the
 * municipality can reshape the workflow without a code change, and there is
 * exactly one place to look when asking "why could/couldn't this move?".
 *
 * WorkflowService::transition() (Stage 14) is the only thing that reads this:
 * it looks for a row matching (current stage + requested action + actor's
 * role). No row = the move is rejected.
 *
 * Seeded EMPTY on purpose:
 *   - Stage 14 inserts the happy path (1 -> 2 -> ... -> 11)
 *   - Stage 16 inserts the exception rows (missing docs, rejection, cancel)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();

            // Restrict this rule to one request type, or leave NULL to
            // mean "applies to every type". Lets a specific type override the
            // general path without duplicating the whole map.
            $table->foreignId('request_type_id')->nullable()
                ->constrained('request_types')->cascadeOnDelete();

            // Where the request must currently be for this rule to apply.
            $table->foreignId('from_stage_id')->constrained('workflow_stages')->cascadeOnDelete();

            // Where it lands afterwards. For a "return to previous stage"
            // exception this points BACKWARDS — that's how rejections and
            // missing-document returns are modelled without special-casing.
            $table->foreignId('to_stage_id')->constrained('workflow_stages')->cascadeOnDelete();

            // The verb the user performs: approve, reject, forward,
            // return_missing_docs, request_edit, cancel...
            $table->string('action', 100);

            // Who is allowed to do it. NULL = any authenticated user, which
            // should be rare — most rows name a role. This is the authoritative
            // permission check for moving a request.
            $table->foreignId('required_role_id')->nullable()
                ->constrained('roles')->nullOnDelete();

            // Status to stamp on the request when this move succeeds.
            // Keeps stage and status changes atomic and consistent.
            $table->foreignId('set_status_id')->nullable()
                ->constrained('request_statuses')->nullOnDelete();

            // Marks the row as an exception path (رفض / نقص مستندات / إلغاء)
            // rather than normal forward progress. The UI renders these as
            // secondary/destructive buttons instead of the primary action.
            $table->boolean('is_exception')->default(false);

            // Force the actor to type a reason. Returns and rejections set
            // this true so the stage log always explains why it went back.
            $table->boolean('requires_comment')->default(false);

            // Display order when several actions are available at one stage.
            $table->unsignedSmallInteger('order_no')->nullable();

            $table->timestamps();

            // The hot lookup: "what can be done from here with this action?"
            // Runs on every transition attempt, so it gets its own index.
            $table->index(['from_stage_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
