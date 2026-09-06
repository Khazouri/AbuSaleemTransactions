<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 64, Track J — who/when/where a committee decision's outcome was
 * actually executed against the *original* Request, distinct from Stage
 * 63's own `legal_reviewed_at`-style columns (which record the appeal's
 * process milestones, not this later execution step). `outcome_redo_stage_id`
 * is null for every outcome except `appeal_redo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->timestamp('outcome_executed_at')->nullable()->after('legal_reviewed_at');
            $table->foreignId('outcome_executed_by_user_id')->nullable()->after('outcome_executed_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('outcome_redo_stage_id')->nullable()->after('outcome_executed_by_user_id')
                ->constrained('workflow_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outcome_executed_by_user_id');
            $table->dropConstrainedForeignId('outcome_redo_stage_id');
            $table->dropColumn('outcome_executed_at');
        });
    }
};
