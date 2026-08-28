<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "direct manager" concept the workflow-alignment redesign adds — see
 * AGENT_NOTES.md ("Align transaction workflow with the ... infographic").
 * Self-referencing FK: the row that identifies an employee's manager is
 * another row in the same table.
 *
 * `User` uses SoftDeletes, so `nullOnDelete` only fires on a hard delete — a
 * soft-deleted manager leaves a dangling `manager_id` on purpose. The actor
 * check that resolves "is this user's manager" (WorkflowService::actorMayUse)
 * must therefore query through the model's default soft-delete scope and the
 * `is_active` flag, falling back to an R08 override when that comes back
 * empty, rather than assuming this column alone is a live pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('department_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
