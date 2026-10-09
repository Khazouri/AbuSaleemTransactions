<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 71 — [D] Appendix 38's delay ladder needs somewhere to remember how
 * far up it has already climbed, or the nightly sweep would re-announce the
 * same delay every night until someone acted on it.
 *
 * Same discipline as Stage 17's `overdue_at`: write once per level, and let
 * the recorded value be what stops a repeat. The difference is that this
 * ladder must RESET when the file moves on, since the whole point of the soft
 * SLA is per-stage. That reset needs no column and no hook in WorkflowService:
 * a recorded level counts only while `escalation_notified_at` is at least as
 * new as the request's latest stage log — the same `acted_at` timestamp
 * Request::stageTimeliness() already measures elapsed time from — so any
 * transition (including a self-loop, which also writes a stage log and
 * restarts the clock) invalidates it automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // green|yellow|red|critical — the highest Appendix 38 level this
            // request has already been escalated at, for its current stage.
            $table->string('escalation_level', 20)->nullable()->after('overdue_at');
            $table->timestamp('escalation_notified_at')->nullable()->after('escalation_level');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['escalation_level', 'escalation_notified_at']);
        });
    }
};
