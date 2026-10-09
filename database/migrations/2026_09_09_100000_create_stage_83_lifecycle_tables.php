<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 83 — the four records [D] anticipates but this system could not hold.
 *
 * Each is a **history** rather than a column set on `requests`, for the reason
 * every history table since Stage 68 has been one: the appendix behind it
 * describes an *action* with two moments that nobody can answer at the same
 * time, and a file can go through it more than once.
 *
 * - `request_document_conflicts` — Appendix 30 (إدارة حالات التعارض في
 *   المستندات). Its six steps are the row's own fields: تحديد المستندات
 *   المتعارضة is `conflict_kind`/`detail`/`attachment_ids`; مخاطبة الجهة
 *   المختصة، تحديد المستند المعتمد and تصحيح البيانات are the three resolution
 *   fields, all required together — which is what makes "**ولا يجوز للجنة
 *   اختيار أحد المستندين بناءً على تقدير شخصي**" enforceable rather than
 *   advisory, since a resolution cannot be recorded without naming the
 *   external authority that determined it. تسجيل الإجراء is the row itself,
 *   and إعادة الملف للفحص is the agenda gate lifting.
 *
 * - `request_special_cases` — Appendix 60's six الحالات الخاصة. The per-case
 *   determinations differ so much between kinds (a death case's transferable
 *   rights against a transfer case's four dates and bodies) that they are one
 *   `determinations` json validated per kind by SpecialCaseRules, not eleven
 *   sparse columns most rows would leave null. `halt_progress` is a real
 *   column rather than a determination because the approve gate reads it in
 *   SQL-free PHP on every checkpoint and must not depend on a JSON predicate.
 *
 * - `request_corrections` — Appendix 53's مذكرة تصحيح **معتمدة**. Two moments,
 *   because "معتمدة" is the appendix's own word: an unapproved memo corrects
 *   nothing. Nothing on the request or the decision is rewritten — "دون تغيير
 *   جوهر النتيجة" means the correction is a document on the file, so the
 *   incorrect and corrected values live here and the original record stands.
 *
 * - `request_withdrawals` — Appendices 68 and 69. One record with three
 *   outcomes; `decision_existed_at_filing` is snapshotted at filing time so
 *   the register still reads correctly if the file is later re-decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_document_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            // One of App\Models\RequestDocumentConflict::KINDS — Appendix 30's
            // own six named differences plus `other`, because the appendix
            // introduces its list with "مثل".
            $table->string('conflict_kind', 40);
            $table->text('detail');
            // The conflicting documents when both are in the file. Nullable:
            // a conflict against an external record has no attachment to name.
            $table->json('attachment_ids')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authority_consulted')->nullable();
            $table->text('authoritative_document')->nullable();
            $table->text('correction_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'id']);
        });

        Schema::create('request_special_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('case_kind', 40);
            $table->text('detail')->nullable();
            $table->json('determinations');
            // Appendix 60's fourth case says "يوقف الانتقال للمرحلة التالية
            // **عند الحاجة**" — a qualifier, so the halt is the recorder's own
            // declared answer rather than an unconditional consequence of the
            // case existing.
            $table->boolean('halt_progress')->default(false);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'id']);
        });

        Schema::create('request_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            // Only Appendix 53's five *material* kinds ever reach this column;
            // its six substantive ones are refused by CorrectionRules and sent
            // to Stage 66's reopen instead.
            $table->string('error_kind', 40);
            $table->text('detail');
            $table->text('incorrect_value');
            $table->text('corrected_value');
            $table->string('memo_reference')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'id']);
        });

        Schema::create('request_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            // Appendix 69 turns on whether the committee had already decided,
            // and that answer is snapshotted here so a later re-presentation
            // cannot retroactively change what this round was.
            $table->boolean('decision_existed_at_filing')->default(false);
            $table->string('outcome', 40)->nullable();
            $table->text('determination_note')->nullable();
            $table->foreignId('determined_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('determined_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_withdrawals');
        Schema::dropIfExists('request_corrections');
        Schema::dropIfExists('request_special_cases');
        Schema::dropIfExists('request_document_conflicts');
    }
};
