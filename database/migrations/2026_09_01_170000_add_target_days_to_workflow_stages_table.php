<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 52 — a non-binding, per-stage target duration ("soft SLA"), distinct
 * from requests.due_date (Stage 17's hard, per-type SLA). Null on a stage
 * that has no known target — see WorkflowStageSeeder for the sourcing and
 * AGENT_NOTES.md for the [A] §12 → stage mapping judgment call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_days_min')->nullable()->after('description');
            $table->unsignedSmallInteger('target_days_max')->nullable()->after('target_days_min');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn(['target_days_min', 'target_days_max']);
        });
    }
};
