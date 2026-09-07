<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 77, Track K — [D] Art. 94's إعادة المحضر من جهة الاعتماد and Appendix
 * 34's شكلية/موضوعية split, i.e. [A] §5's Path 3, which gap-analysis §14
 * recorded as having no equivalent anywhere in this system.
 *
 * A table with MANY rows per request, deliberately unlike Stage 75/76's
 * one-shot closure/execution columns. Art. 94 does not describe a field, it
 * describes an *action* — "ينشأ إجراء إعادة معالجة يثبت سبب الإعادة والإجراء
 * الذي اتخذ بشأنها" — and a file can go round the approval loop more than once
 * (returned → corrected → re-referred → returned again). Art. 98's twelve
 * registers also include سجل القرارات المعادة من جهة الاعتماد, a register of
 * returns, which an overwritten column cannot supply. Same reasoning Stage 68
 * used for `request_legal_reviews` against Stage 62's one-shot JSON column.
 *
 * The row is written in two moments because Art. 94 names two things. The
 * `return_*` half records سبب الإعادة when the file comes back; the
 * `resolution_*` half records الإجراء الذي اتخذ بشأنها, which nobody knows yet
 * at the moment of the return. An unresolved row is what Appendix 48's seventh
 * closure-refusal condition ("أعيدت من جهة الاعتماد") reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            // Which approving body sent it back, recorded as the checkpoint it
            // was standing on: approval_by_authority (البلدية) or
            // local_governance_ministry (الوزارة). Stored rather than derived
            // because the resolution can move the request off that stage.
            $table->foreignId('returned_from_stage_id')->nullable()
                ->constrained('workflow_stages')->nullOnDelete();

            // --- Appendix 34 — تصنف الإعادة إلى ------------------------------
            // شكلية | موضوعية. The classification is the datum the appendix
            // asks for, and it is what decides where resolve() sends the file.
            $table->string('return_kind', 20);
            // Art. 94's six reasons merged with Appendix 34's seven examples,
            // partitioned by the kind the sources themselves assign — plus
            // `other`, because both lists are introduced with "مثل".
            $table->string('return_reason_code', 40);
            // Art. 30's sixth recorded field — أي ملاحظات أو توجيهات صادرة عن
            // جهة الاعتماد. Required: Art. 94 makes proving the reason the
            // point of the whole action.
            $table->text('return_note');
            $table->string('letter_number')->nullable();  // رقم كتاب الإعادة
            $table->date('received_at');                  // Art. 30 — تاريخ ورود النتيجة

            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // --- Art. 94 — الإجراء الذي اتخذ بشأنها --------------------------
            $table->text('resolution_action')->nullable();
            $table->foreignId('resolution_target_stage_id')->nullable()
                ->constrained('workflow_stages')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_returns');
    }
};
