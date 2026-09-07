<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 68 — [D] Art. 21's pre-meeting legal review, the one numbered step of
 * [E]'s 21-stage flow (stage 08) that had no implementation at all: before
 * this, `legal_review` existed only on `Appeal` (Stage 62).
 *
 * A table with MANY rows per request, deliberately unlike Stage 62's one-shot
 * `appeals.legal_review` JSON column. Art. 21 and [E] stage 08 both describe a
 * loop ("توجد ملاحظات → استكمال أو تصحيح ثم إعادة المراجعة"), and Art. 21
 * requires the opinion to stay readable in the file afterwards ("ويثبت الرأي
 * أو الملاحظة القانونية في الملف بما يسمح لأعضاء اللجنة بالاطلاع عليها عند
 * الدراسة") — an overwritten column would destroy the previous round's
 * opinion, which is exactly what Appendix 13's سجل الآراء القانونية exists to
 * prevent. `Request::latestLegalReview()` is what the agenda gate reads.
 *
 * The eight fields between `primary_legislation` and `prohibiting_conditions`
 * are Appendix 22's بطاقة السند القانوني, kept as real columns rather than a
 * JSON blob because later Track K stages query them (Appendix 13's register,
 * Stage 77's return-from-approving-body).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_legal_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();

            // --- Appendix 22 — بطاقة السند القانوني --------------------------
            $table->string('primary_legislation')->nullable();       // التشريع الأساسي
            $table->string('article_reference')->nullable();         // رقم المادة
            $table->string('supplementary_decision')->nullable();    // القرار أو المنشور المكمل
            // اختصاص اللجنة: قرار · توصية · رأي · دراسة فقط — Appendix 22's own
            // four values verbatim (STAGE_PLAN's Build bullet paraphrases the
            // fourth as "لا اختصاص"; the source text says دراسة فقط).
            $table->string('committee_mandate', 30)->nullable();
            $table->string('approving_body')->nullable();            // جهة الاعتماد
            // هل يلزم اعتماد مركزي؟ نعم · لا · يحتاج إلى تحقق — three values,
            // NOT a boolean: Appendix 22 explicitly offers "يحتاج إلى تحقق".
            // Deliberately not merged with Stage 54's
            // `jurisdiction_test.requires_central_approval`, which is a
            // boolean because Art. 45 Q6 asks a plain yes/no question.
            $table->string('requires_central_approval', 30)->nullable();
            $table->string('legal_deadline')->nullable();            // هل توجد مدة قانونية؟
            $table->text('prohibiting_conditions')->nullable();      // هل توجد شروط مانعة؟

            // --- النموذج 06 / Art. 21 — the five procedural outcomes ---------
            $table->string('verdict', 30);
            // ملاحظة العضو القانوني. Required by the FormRequest for four of
            // the five verdicts (every one except sound_ready) — see
            // StoreRequestLegalReviewRequest for why.
            $table->text('legal_note')->nullable();

            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_legal_reviews');
    }
};
