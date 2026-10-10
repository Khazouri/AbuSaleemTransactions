<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow designer — slice 4. A custom step carries a checklist its role
 * completes, and an outcome can wait on it. The answers live per ARRIVAL at
 * the step (a request_stage_logs row), not per request: a file returned and
 * sent back must be checked afresh, while the earlier card stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->json('checklist')->nullable()->after('target_days_max');
        });

        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->boolean('requires_checks')->default(false)->after('requires_comment');
        });

        Schema::create('request_step_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('arrival_log_id')->constrained('request_stage_logs')->cascadeOnDelete();
            $table->json('answers');
            $table->foreignId('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique(['request_id', 'arrival_log_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_step_reviews');

        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->dropColumn('requires_checks');
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn('checklist');
        });
    }
};
