<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WORKFLOW_STAGES (مراحل الطلب) — the 14 steps of a request's life
 * ---------------------------------------------------------------------------
 * Every request sits at exactly one stage at any moment
 * (requests.current_stage_id). The stages, in order:
 *
 *    1  استلام الطلب من البلدية          Receive from municipality
 *    2  مراجعة الطلب من المدير المباشر       Direct manager review
 *    3  إحالة الطلب لأحد المسارات الإدارية   Administrative routing (HR / Diwan / committee secretary)
 *    4  الاستلام والتسجيل                   Receive and register
 *    5  فحص استيفاء المتطلبات               Check the paperwork is complete
 *    6  مراجعة المقرر وفق اللوائح            Reviewer checks it against regulations
 *    7  إبداء الملاحظات (إن وجدت)           Raise observations, if any
 *    8  اعتماد الوزارة                      Ministry endorsement
 *    9  تحويل الطلب للجنة القائمة         Forward to the standing committee
 *   10  استلام الطلب من اللجنة            Committee receives it
 *   11  اعتماد (حسب الصلاحيات)              Approval, per the approver's authority
 *   12  وزارة الحكم المحلي                  Ministry of Local Governance
 *   13  اعتماد الجهة المختصة                Competent authority approval
 *   14  الاعتماد النهائي والأرشفة            Final approval and archiving
 *
 * Stages 2–4 were added by the diagram-alignment redesign (see
 * AGENT_NOTES.md, "Employee Affairs Committee request" infographic); stages
 * 5–14 are the original stages 2–11, renumbered but otherwise unchanged.
 *
 * NOTE this table only names the stages. What may move a request BETWEEN
 * them — which action, performed by which role — lives in workflow_transitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();

            // Position in the sequence (1..11). Unique so two stages can't
            // claim the same slot. Used for ordering and for "is this stage
            // before/after that one" comparisons.
            $table->unsignedSmallInteger('order_no')->unique();

            // Machine-readable name, e.g. "requirements_check". Referenced by
            // seeders and tests, so it is safer to key off than the id.
            $table->string('code', 50)->unique();

            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // The role that normally owns this stage — used to render "with
            // whom does this sit right now?" in the UI.
            //
            // This is INDICATIVE ONLY. Permission to actually perform an action
            // is decided by workflow_transitions.required_role_id (Stage 14).
            // Don't use this column for access control.
            $table->foreignId('responsible_role_id')->nullable()
                ->constrained('roles')->nullOnDelete();

            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
    }
};
