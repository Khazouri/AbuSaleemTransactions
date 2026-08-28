<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQUEST_TYPES (أنواع الطلبات)
 * ---------------------------------------------------------------------------
 * What kind of staff request this is: ترقية (promotion), إجازة (leave),
 * تظلم (grievance), and so on.
 *
 * Type drives two behaviours beyond labelling:
 *   - how long it is allowed to take        (default_sla_days)
 *   - when it must be escalated to the ministry (decision_grade_threshold)
 *
 * Types can also scope the workflow itself: workflow_transitions rows may be
 * tied to one type, so a leave request can follow a shorter path than a
 * promotion if the municipality wants that later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $table) {
            $table->id();

            // Short key: PROM, LEAV, GRIV...
            $table->string('code', 50)->nullable()->unique();

            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // Service-level agreement in days. When a request of this type
            // is created, due_date = created_at + default_sla_days. The overdue
            // sweep in Stage 17 compares against that date.
            $table->unsignedSmallInteger('default_sla_days')->nullable();

            // "درجة القرار" — the decision grade. Per the workflow spec, a
            // decision of grade 10 or above cannot be settled inside the
            // municipality: it must be escalated to وزارة الحكم المحلي
            // (stage 9). Below the threshold, that stage is skipped.
            // The approval chain in Stage 18 reads this.
            $table->unsignedSmallInteger('decision_grade_threshold')->nullable();

            // Retire a type without breaking requests that already use it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_types');
    }
};
