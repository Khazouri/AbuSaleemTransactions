<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEAL_STATUSES (حالات التظلم) — Stage 58, Track J.
 * ---------------------------------------------------------------------------
 * Appeals get their own small, sequential status machine rather than a detour
 * through the 14-row `workflow_stages` table used for ordinary requests — see
 * STAGE_PLAN.md Track J's intro, scope decision (2). The six rows mirror [A]
 * §9's own numbered sequence (تقديم → التحقق الشكلي → جمع الملف الأصلي →
 * المراجعة القانونية → العرض على اللجنة أو الجهة المختصة → التبليغ والإغلاق),
 * which also lines up one-to-one with Track J's Stages 59/60/61/62/63/65.
 *
 * Deliberately not `request_statuses`: an appeal is never a Request (see the
 * Track J intro's scope decision (1)), so reusing that table would imply it
 * is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeal_statuses', function (Blueprint $table) {
            $table->id();

            // Position in the [A] §9 sequence (1..6). Unique, same purpose as
            // workflow_stages.order_no.
            $table->unsignedSmallInteger('order_no')->unique();

            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // Hex colour for the status badge, matching request_statuses'
            // convention.
            $table->string('color', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeal_statuses');
    }
};
