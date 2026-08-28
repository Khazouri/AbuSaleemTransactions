<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two new gates a workflow_transitions row can require, alongside the
 * existing `required_role_id` — see AGENT_NOTES.md ("Align transaction
 * workflow with the ... infographic") for why.
 *
 * `requires_submitter_manager` marks a row as usable only by the
 * transaction creator's active, non-deleted manager (or, when none is
 * assigned/reachable, by R08 as a fail-toward-admin fallback — see
 * WorkflowService::actorMayUse). `required_status_id` additionally pins a
 * row to a specific current transaction status, which is what makes the
 * three-way administrative routing enforceable rather than decorative: role
 * alone cannot tell which of three routed-to statuses a file was sent down.
 *
 * Both are nullable/false by default and, as of this migration, unused by
 * any seeded row — nothing yet sets true or a non-null status here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->boolean('requires_submitter_manager')->default(false)->after('required_role_id');
            $table->foreignId('required_status_id')->nullable()->after('requires_submitter_manager')
                ->constrained('transaction_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('required_status_id');
            $table->dropColumn('requires_submitter_manager');
        });
    }
};
